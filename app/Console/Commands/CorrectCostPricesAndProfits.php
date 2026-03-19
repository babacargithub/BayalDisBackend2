<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockEntry;
use App\Models\Vente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrects historical financial data in the following order:
 *
 * 0. Fix known data-entry errors in purchase_invoice_items / stock_entries.
 * 1. Set parent product cost_price to weighted average from purchase history.
 * 2. Set child product cost_price derived from parent (parent_cost_price / base_quantity).
 * 3. Update the latest StockEntry.unit_price per product to match its new cost_price.
 * 4. Recalculate Vente.profit for every vente using the updated cost prices.
 * 5. Recalculate Payment.profit for every payment using the invoice profit margin.
 * 6. Call recalculateStoredTotals() on every SalesInvoice to sync all cached columns.
 *
 * Vente and Payment rows are updated via DB::table() to bypass model events
 * and avoid triggering hundreds of redundant recalculateStoredTotals() calls
 * mid-run. A single final pass over all invoices (step 6) is authoritative.
 */
class CorrectCostPricesAndProfits extends Command
{
    protected $signature = 'bayal:correct-cost-prices-and-profits
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Correct product cost prices from purchase history, then recalculate all vente and payment profits.';

    private bool $isDryRun = false;

    public function handle(): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');

        if ($this->isDryRun) {
            $this->warn('DRY RUN — no changes will be written to the database.');
        }

        DB::transaction(function () {
            $this->stepZeroFixKnownDataEntryErrors();
            $this->stepZeroPointFiveZeroOutSupersededCarLoadItems();
            $this->stepOneUpdateParentProductCostPrices();
            $this->stepTwoUpdateChildProductCostPrices();
            $this->stepThreeUpdateLatestStockEntryUnitPrices();
            $this->stepFourRecalculateVenteProfits();
            $this->stepFiveRecalculatePaymentProfits();
            $this->stepSixRecalculateAllInvoiceStoredTotals();
        });

        $this->info('All corrections applied successfully.');

        return Command::SUCCESS;
    }

    // =========================================================================
    // Step 0 — Fix known data-entry errors in purchase_invoice_items / stock_entries
    // =========================================================================

    /**
     * Each entry in $knownErrors describes a confirmed data-entry mistake.
     * The fix is idempotent: if the product_id is already correct the UPDATE is a no-op.
     *
     * How to add a new correction:
     *   - Add an entry to $knownErrors with a human-readable description and
     *     the table / id / wrong_product_id / correct_product_id values.
     *   - Both purchase_invoice_items and stock_entries linked to the same
     *     erroneous item must be listed if they also carry the wrong product_id.
     */
    private function stepZeroFixKnownDataEntryErrors(): void
    {
        $this->info('Step 0: Fixing known data-entry errors in purchase records…');

        $knownErrors = [
            [
                'description' => 'INV20250309 — child variant "500g - 20pcs" (#10) was entered instead of parent "500g carton 1000pcs" (#18)',
                'fixes' => [
                    ['table' => 'purchase_invoice_items', 'id' => 59, 'wrong_product_id' => 10, 'correct_product_id' => 18],
                    ['table' => 'stock_entries',           'id' => 71, 'wrong_product_id' => 10, 'correct_product_id' => 18],
                ],
            ],
            [
                'description' => 'INV20232425 — child variant "Transparent 1000ml 5pcs" (#14) was entered instead of parent "Transparent 1000ml carton 500pcs" (#21) at carton-level price (46 000 XOF)',
                'fixes' => [
                    ['table' => 'purchase_invoice_items', 'id' => 61, 'wrong_product_id' => 14, 'correct_product_id' => 21],
                    ['table' => 'stock_entries',           'id' => 73, 'wrong_product_id' => 14, 'correct_product_id' => 21],
                ],
            ],
        ];

        $fixedCount = 0;
        $skippedCount = 0;

        foreach ($knownErrors as $knownError) {
            $this->line("  Error: {$knownError['description']}");

            foreach ($knownError['fixes'] as $fix) {
                $currentProductId = DB::table($fix['table'])->where('id', $fix['id'])->value('product_id');

                if ($currentProductId === null) {
                    $this->warn("    Skip {$fix['table']} #{$fix['id']}: row not found.");
                    $skippedCount++;

                    continue;
                }

                if ((int) $currentProductId === $fix['correct_product_id']) {
                    $this->line("    Already correct: {$fix['table']} #{$fix['id']} product_id = {$fix['correct_product_id']}");
                    $skippedCount++;

                    continue;
                }

                $this->line("    Fix {$fix['table']} #{$fix['id']}: product_id {$currentProductId} → {$fix['correct_product_id']}");

                if (! $this->isDryRun) {
                    DB::table($fix['table'])
                        ->where('id', $fix['id'])
                        ->update(['product_id' => $fix['correct_product_id']]);
                }

                $fixedCount++;
            }
        }

        $this->info("  Done — {$fixedCount} rows fixed, {$skippedCount} already correct or not found.");
    }

    // =========================================================================
    // Step 0.5 — Zero out quantity_left on superseded car load items
    // =========================================================================

    /**
     * When a new car load is created from a closed inventory, the remaining stock
     * is carried forward into the new car load's items. The old car load's items
     * were never zeroed out, leaving phantom quantity_left values that inflate the
     * apparent remaining inventory across all historical car loads.
     *
     * A car load is "superseded" when another car load references it via
     * previous_car_load_id. All items of such car loads must have quantity_left = 0
     * since the physical stock moved to the successor car load.
     *
     * This is idempotent — rows already at 0 are unaffected.
     */
    private function stepZeroPointFiveZeroOutSupersededCarLoadItems(): void
    {
        $this->info('Step 0.5: Zeroing out quantity_left on superseded car load items…');

        $supersededCarLoadIds = DB::table('car_loads')
            ->whereNotNull('previous_car_load_id')
            ->pluck('previous_car_load_id')
            ->unique()
            ->values()
            ->all();

        if (empty($supersededCarLoadIds)) {
            $this->info('  No superseded car loads found. Nothing to do.');

            return;
        }

        $affectedItemCount = DB::table('car_load_items')
            ->whereIn('car_load_id', $supersededCarLoadIds)
            ->where('quantity_left', '>', 0)
            ->count();

        $this->line('  Found '.count($supersededCarLoadIds)." superseded car load(s) with {$affectedItemCount} item(s) still showing quantity_left > 0.");

        if (! $this->isDryRun) {
            DB::table('car_load_items')
                ->whereIn('car_load_id', $supersededCarLoadIds)
                ->update(['quantity_left' => 0]);
        }

        $this->info("  Done — {$affectedItemCount} car load items zeroed out.");
    }

    // =========================================================================
    // Step 1 — Parent product cost_price = weighted average from purchase history
    // =========================================================================

    private function stepOneUpdateParentProductCostPrices(): void
    {
        $this->info('Step 1: Updating parent product cost prices from purchase history…');

        $parentProducts = Product::query()->whereNull('parent_id')->get();

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($parentProducts as $parentProduct) {
            // Only include items from invoices that were actually stocked (goods received).
            // Unstocked invoices represent orders that never arrived and must not distort
            // the weighted-average cost price.
            //
            // Full unit cost = unit_price + transportation_cost_per_unit + packaging_cost.
            // transportation_cost on purchase_invoice_items is the LINE total (not per-unit),
            // so total_value = SUM(qty * unit_price + transport_line + qty * packaging) which
            // simplifies to the sum of all-in costs across all batches.
            $purchaseAggregate = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
                ->where('purchase_invoice_items.product_id', $parentProduct->id)
                ->where('purchase_invoices.is_stocked', true)
                ->selectRaw('
                    SUM(purchase_invoice_items.quantity) as total_quantity,
                    SUM(
                        purchase_invoice_items.quantity * purchase_invoice_items.unit_price
                        + purchase_invoice_items.transportation_cost
                        + purchase_invoice_items.quantity * ?
                    ) as total_value
                ', [$parentProduct->packaging_cost])
                ->first();

            if ($purchaseAggregate === null || $purchaseAggregate->total_quantity <= 0) {
                $this->line("  Skip parent #{$parentProduct->id} «{$parentProduct->name}»: no purchase history found.");
                $skippedCount++;

                continue;
            }

            $weightedAverageCostPrice = (int) round($purchaseAggregate->total_value / $purchaseAggregate->total_quantity);

            $this->line("  Parent #{$parentProduct->id} «{$parentProduct->name}»: {$parentProduct->cost_price} → {$weightedAverageCostPrice} XOF");

            if (! $this->isDryRun) {
                $parentProduct->cost_price = $weightedAverageCostPrice;
                $parentProduct->saveQuietly();
            }

            $updatedCount++;
        }

        $this->info("  Done — {$updatedCount} updated, {$skippedCount} skipped (no purchase history).");
    }

    // =========================================================================
    // Step 2 — Child product cost_price = parent cost_price / base_quantity
    // =========================================================================

    private function stepTwoUpdateChildProductCostPrices(): void
    {
        $this->info('Step 2: Updating child (variant) product cost prices from parent average…');

        $childProducts = Product::query()
            ->whereNotNull('parent_id')
            ->with('parent')
            ->get();

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($childProducts as $childProduct) {
            $parentProduct = $childProduct->parent;

            if ($parentProduct === null) {
                $this->warn("  Skip child #{$childProduct->id} «{$childProduct->name}»: parent record not found.");
                $skippedCount++;

                continue;
            }

            if ($parentProduct->cost_price <= 0) {
                $this->line("  Skip child #{$childProduct->id} «{$childProduct->name}»: parent cost_price is 0 or negative.");
                $skippedCount++;

                continue;
            }

            if ($childProduct->base_quantity <= 0) {
                $this->warn("  Skip child #{$childProduct->id} «{$childProduct->name}»: child base_quantity is 0 — cannot compute.");
                $skippedCount++;

                continue;
            }

            if ($parentProduct->base_quantity <= 0) {
                $this->warn("  Skip child #{$childProduct->id} «{$childProduct->name}»: parent base_quantity is 0 — cannot compute.");
                $skippedCount++;

                continue;
            }

            // Correct formula: how many child units fit in one parent × parent cost
            // conversion_ratio = parent.base_quantity / child.base_quantity
            // child.cost_price  = parent.cost_price / conversion_ratio
            //                   = parent.cost_price * child.base_quantity / parent.base_quantity
            $derivedChildCostPrice = (int) round(
                $parentProduct->cost_price * $childProduct->base_quantity / $parentProduct->base_quantity
            );

            $this->line("  Child #{$childProduct->id} «{$childProduct->name}» (parent: {$parentProduct->name}, base_qty: {$childProduct->base_quantity}): {$childProduct->cost_price} → {$derivedChildCostPrice} XOF");

            if (! $this->isDryRun) {
                $childProduct->cost_price = $derivedChildCostPrice;
                $childProduct->saveQuietly();
            }

            $updatedCount++;
        }

        $this->info("  Done — {$updatedCount} updated, {$skippedCount} skipped.");
    }

    // =========================================================================
    // Step 3 — Latest StockEntry.unit_price per product = product.cost_price
    // =========================================================================

    private function stepThreeUpdateLatestStockEntryUnitPrices(): void
    {
        $this->info('Step 3: Updating latest StockEntry unit price per product…');

        $allProducts = Product::query()->get(['id', 'name', 'cost_price']);

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($allProducts as $product) {
            if ($product->cost_price <= 0) {
                $skippedCount++;

                continue;
            }

            $latestStockEntry = StockEntry::query()
                ->where('product_id', $product->id)
                ->orderByDesc('id')
                ->first();

            if ($latestStockEntry === null) {
                $this->line("  Skip product #{$product->id} «{$product->name}»: no stock entries found.");
                $skippedCount++;

                continue;
            }

            $this->line("  Stock entry #{$latestStockEntry->id} for product «{$product->name}»: unit_price {$latestStockEntry->unit_price} → {$product->cost_price} XOF");

            if (! $this->isDryRun) {
                DB::table('stock_entries')
                    ->where('id', $latestStockEntry->id)
                    ->update(['unit_price' => $product->cost_price]);
            }

            $updatedCount++;
        }

        $this->info("  Done — {$updatedCount} updated, {$skippedCount} skipped.");
    }

    // =========================================================================
    // Step 4 — Vente.profit = (price - product.cost_price) * quantity
    // =========================================================================

    private function stepFourRecalculateVenteProfits(): void
    {
        $this->info('Step 4: Recalculating vente profits using updated cost prices…');

        // Load all products cost_prices into a keyed map to avoid per-vente queries.
        $productCostPriceById = Product::query()
            ->pluck('cost_price', 'id')
            ->all();

        $ventes = Vente::query()->get(['id', 'product_id', 'price', 'quantity', 'profit']);

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($ventes as $vente) {
            if (! isset($productCostPriceById[$vente->product_id])) {
                $this->warn("  Skip vente #{$vente->id}: product #{$vente->product_id} not found.");
                $skippedCount++;

                continue;
            }

            $productCostPrice = $productCostPriceById[$vente->product_id];
            $recalculatedProfit = ($vente->price - $productCostPrice) * $vente->quantity;

            if ($recalculatedProfit === $vente->profit) {
                $skippedCount++;

                continue;
            }

            if (! $this->isDryRun) {
                // Use DB::table to bypass Vente model events and avoid triggering
                // recalculateStoredTotals hundreds of times. Step 6 does one clean pass.
                DB::table('ventes')
                    ->where('id', $vente->id)
                    ->update(['profit' => $recalculatedProfit]);
            }

            $updatedCount++;
        }

        $this->info("  Done — {$updatedCount} ventes updated, {$skippedCount} unchanged or skipped.");
    }

    // =========================================================================
    // Step 5 — Payment.profit = round(invoice_total_profit / invoice_total * amount)
    // =========================================================================

    private function stepFiveRecalculatePaymentProfits(): void
    {
        $this->info('Step 5: Recalculating payment profits using fresh invoice profit margins…');

        // Build per-invoice fresh totals from ventes (already updated in step 4).
        $freshInvoiceTotals = DB::table('ventes')
            ->whereNotNull('sales_invoice_id')
            ->groupBy('sales_invoice_id')
            ->selectRaw('sales_invoice_id, SUM(price * quantity) as total_amount, SUM(profit) as total_estimated_profit')
            ->get()
            ->keyBy('sales_invoice_id');

        $payments = Payment::query()
            ->whereNotNull('sales_invoice_id')
            ->get(['id', 'sales_invoice_id', 'amount', 'profit']);

        $updatedCount = 0;
        $skippedCount = 0;

        foreach ($payments as $payment) {
            $invoiceTotals = $freshInvoiceTotals->get($payment->sales_invoice_id);

            if ($invoiceTotals === null || $invoiceTotals->total_amount <= 0) {
                $this->line("  Skip payment #{$payment->id}: invoice #{$payment->sales_invoice_id} has no items or zero total.");
                $skippedCount++;

                continue;
            }

            $recalculatedProfit = (int) round(
                $invoiceTotals->total_estimated_profit / $invoiceTotals->total_amount * $payment->amount
            );

            if ($recalculatedProfit === $payment->profit) {
                $skippedCount++;

                continue;
            }

            if (! $this->isDryRun) {
                // Use DB::table to bypass Payment model events.
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update(['profit' => $recalculatedProfit]);
            }

            $updatedCount++;
        }

        $this->info("  Done — {$updatedCount} payments updated, {$skippedCount} unchanged or skipped.");
    }

    // =========================================================================
    // Step 6 — Sync all SalesInvoice cached columns via recalculateStoredTotals()
    // =========================================================================

    private function stepSixRecalculateAllInvoiceStoredTotals(): void
    {
        $this->info('Step 6: Recalculating stored totals on all SalesInvoices…');

        if ($this->isDryRun) {
            $invoiceCount = SalesInvoice::query()->count();
            $this->info("  Would recalculate stored totals on {$invoiceCount} invoices.");

            return;
        }

        $invoiceCount = 0;

        SalesInvoice::query()->each(function (SalesInvoice $salesInvoice) use (&$invoiceCount) {
            $salesInvoice->recalculateStoredTotals();
            $invoiceCount++;
        });

        $this->info("  Done — {$invoiceCount} invoices recalculated.");
    }
}
