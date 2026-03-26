<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Enums\CarLoadExpenseType;
use App\Enums\CarLoadStatus;
use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Caisse;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\MonthlyFixedCost;
use App\Models\ProductCategory;
use App\Models\Vehicle;
use App\Services\AccountService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Single entry-point that runs every historical-data-refactoring command
 * in the correct dependency order:
 *
 *  Step 1 — bayal:migrate-single-ventes-to-invoices
 *            Wraps legacy TYPE_SINGLE ventes into proper SalesInvoices with
 *            back-dated payments. Runs first because subsequent steps rely on
 *            every vente having a parent invoice.
 *
 *  Step 2 — bayal:correct-cost-prices-and-profits
 *            Recomputes product cost prices from purchase history, then
 *            recalculates vente profits, payment profits, and all invoice
 *            stored totals in one clean pass. Must run after migration so
 *            the newly created invoices are also corrected.
 *
 *  Step 3 — bayal:link-invoices-to-car-loads
 *            Backfills car_load_id on invoices that predate the field. Runs
 *            last because it only needs invoices to exist and be final.
 *
 *  Step 4 — backfillCarLoadStatuses (inline)
 *            Sets all car loads to TERMINATED_AND_TRANSFERRED except the most
 *            recent one (ordered by id), which is set to ONGOING_INVENTORY to
 *            reflect that the current cycle is at the inventory stage.
 *
 *  Step 5 — backfillStockEntryWarehouse (inline)
 *            Creates the "Dépôt Ouest Foire" warehouse if it does not yet
 *            exist, then links every stock_entry that has no warehouse_id to
 *            that warehouse. This represents the single physical depot from
 *            which all historical stock was loaded.
 *
 * The --dry-run flag is forwarded to every sub-command so you can preview
 * the full impact of all steps without writing anything.
 */
class RefactorOldData extends Command
{
    protected $signature = 'bayal:refactor-old-data
                            {--dry-run : Preview all changes without writing to the database}';

    protected $description = 'Run all historical-data-refactoring commands in the correct order: migrate ventes → correct profits → link car loads → backfill statuses → link stock entries to warehouse.';

    private const DEPOT_OUEST_FOIRE_NAME = 'Dépôt Ouest Foire';

    /**
     * Historical fuel cost per working week (Monday – Saturday), in XOF.
     * Applied to every car load that has no expense entries recorded.
     * A car load spanning one month will accumulate roughly 4 × WEEKLY_FUEL_COST_XOF.
     */
    private const WEEKLY_FUEL_COST_XOF = 15_000;

    /**
     * Products that carry a per-unit packaging cost not recorded at purchase time.
     * Applied to both the products table and every linked stock_entry.
     *
     * @var array<int>
     */
    private const PRODUCT_IDS_WITH_PACKAGING_COST = [3, 7, 10];

    /** Per-unit packaging cost in XOF applied to PRODUCT_IDS_WITH_PACKAGING_COST. */
    private const PRODUCT_PACKAGING_COST_XOF = 13;

    /**
     * Standard transportation cost per purchase invoice, in XOF.
     * Historically not recorded — this backfill applies 8 000 F to every invoice
     * and distributes it proportionally across the invoice's line items.
     */
    private const PURCHASE_INVOICE_TRANSPORTATION_COST_XOF = 8_000;

    private const DEPOT_OUEST_FOIRE_ADDRESS = 'Ouest Foire, Dakar';

    /**
     * The first entry is the fallback category assigned to products with no category.
     * All entries are matched by name — safe to re-run.
     *
     * @var array<int, array{name: string, commission_rate: string}>
     */
    private const PRODUCT_CATEGORIES = [
        ['name' => 'JETABLES',     'commission_rate' => '0.0250'],
        ['name' => 'ALIMENTAIRES', 'commission_rate' => '0.0100'],
        ['name' => 'HYGIENE',      'commission_rate' => '0.0200'],
        ['name' => 'VAISSELLE',    'commission_rate' => '0.0300'],
        ['name' => 'DIVERS',       'commission_rate' => '0.0300'],
    ];

    /**
     * Ordered list of sub-commands to execute.
     * Each entry is an array of [command-name, options].
     *
     * @var array<int, array{command: string, label: string}>
     */
    /**
     * Fleet vehicles to seed on every fresh database.
     * Add new vehicles here as the fleet grows.
     *
     * @var array<int, array<string, mixed>>
     */
    private const FLEET_VEHICLES = [
        [
            'plate_number' => 'AA-531-WL',
            'name' => 'BERLINGO 531',
            'insurance_monthly' => 7_000,
            'maintenance_monthly' => 13_500,
            'repair_reserve_monthly' => 35_000,
            'depreciation_monthly' => 60_000,
            'driver_salary_monthly' => 220_000,
            'working_days_per_month' => 26,
            'estimated_daily_fuel_consumption' => 2_500,
        ],
    ];

    /**
     * Monthly fixed cost entries to seed on every fresh database.
     * Each entry is matched by (cost_pool + sub_category + period_year + period_month)
     * to avoid duplicates — safe to re-run.
     *
     * @var array<int, array<string, mixed>>
     */
    private const MONTHLY_FIXED_COSTS = [
        [
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 1_000,
            'label' => 'Loyer mensuel',
            'period_year' => 2026,
            'period_month' => 2,
        ],
        [
            'cost_pool' => MonthlyFixedCostPool::Overhead,
            'sub_category' => MonthlyFixedCostSubCategory::ManagerSalary,
            'amount' => 1_000,
            'label' => 'Salaire manager',
            'period_year' => 2026,
            'period_month' => 3,
        ],
        [
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 1_000,
            'label' => 'Loyer dépôt',
            'period_year' => 2026,
            'period_month' => 3,
        ],
    ];

    private const PIPELINE = [
        [
            'command' => 'bayal:migrate-single-ventes-to-invoices',
            'label' => 'Step 1/5 — Migrate legacy TYPE_SINGLE ventes to SalesInvoices',
        ],
        [
            'command' => 'bayal:correct-cost-prices-and-profits',
            'label' => 'Step 2/5 — Correct cost prices and recalculate all profits',
        ],
        [
            'command' => 'bayal:link-invoices-to-car-loads',
            'label' => 'Step 3/5 — Backfill car_load_id on legacy invoices',
        ],
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if (! $this->allMigrationsHaveBeenRun()) {
            $this->error('There are pending database migrations. Run `php artisan migrate` first, then retry.');

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('═══════════════════════════════════════════════════════════');
            $this->warn('  DRY RUN — no changes will be written to the database.');
            $this->warn('═══════════════════════════════════════════════════════════');
        } else {
            $this->info('═══════════════════════════════════════════════════════════');
            $this->info('  bayal:refactor-old-data — starting full pipeline');
            $this->info('═══════════════════════════════════════════════════════════');
        }

        // Step 0.1: set packaging cost for specific products + their stock entries (idempotent)
        // Must run before the profit-correction pipeline so cost prices are complete.
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.1 — Backfill product packaging costs');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillProductPackagingCosts($isDryRun);

        // Step 0: seed fleet vehicles (idempotent — skips existing plate numbers)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0 — Seed fleet vehicles');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedFleetVehicles($isDryRun);

        // Step 0.2: link all unassigned car loads to the fleet vehicle (idempotent)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.2 — Link all car loads to the fleet vehicle');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillCarLoadVehicle($isDryRun);

        // Step 0.3: backfill weekly fuel entries for car loads with no expense data (idempotent)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.3 — Backfill weekly fuel entries for historical car loads');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillFuelEntries($isDryRun);

        // Step 0.4: set transportation cost on all purchase invoices and distribute to items
        // + update linked stock entries. Must run before the profit-correction pipeline.
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.4 — Backfill purchase invoice transportation costs');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillPurchaseInvoiceTransportationCosts($isDryRun);

        // Step 0.5: seed monthly fixed costs (idempotent — skips existing pool+sub_category+period)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.5 — Seed monthly fixed costs');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedMonthlyFixedCosts($isDryRun);

        // Step 0.6: normalize payment_method values in payments and ventes tables to UPPERCASE.
        // Historical records used mixed-case values ('Cash', 'Wave', 'Om') before the convention
        // was standardised. This must run before the profit-correction pipeline so all subsequent
        // reads use the canonical form.
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.6 — Normalize payment methods to uppercase');
        $this->info('───────────────────────────────────────────────────────────');
        $this->normalizePaymentMethodsToUppercase($isDryRun);

        // Step 0.7: backfill credit_price = round(price × 1.20) on products that have no
        // credit_price set. Products with an explicitly configured credit_price are skipped.
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 0.7 — Backfill credit_price (+20%) on products');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillProductCreditPrices($isDryRun);

        foreach (self::PIPELINE as $step) {
            $this->newLine();
            $this->info('───────────────────────────────────────────────────────────');
            $this->info("  {$step['label']}");
            $this->info('───────────────────────────────────────────────────────────');

            $subCommandOptions = $isDryRun ? ['--dry-run' => true] : [];

            $exitCode = $this->call($step['command'], $subCommandOptions);

            if ($exitCode !== Command::SUCCESS) {
                $this->error("Sub-command [{$step['command']}] failed with exit code {$exitCode}. Pipeline aborted.");

                return Command::FAILURE;
            }
        }

        // Step 4 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 4/6 — Backfill car load statuses');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillCarLoadStatuses($isDryRun);

        // Step 5 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 5/6 — Create "'.self::DEPOT_OUEST_FOIRE_NAME.'" and link stock entries');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillStockEntryWarehouse($isDryRun);

        // Step 6 runs inline (no sub-command)
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 6/7 — Seed product categories and assign unassigned products to JETABLES');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedDefaultProductCategory($isDryRun);

        // Step 7 runs inline (no sub-command)
        // Must run last: depends on car_load_id being set (Step 3) and expenses existing (Step 0.3).
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 7/8 — Backfill delivery costs on historical invoices');
        $this->info('───────────────────────────────────────────────────────────');
        $this->backfillInvoiceDeliveryCosts($isDryRun);

        // Step 8 runs inline (no sub-command)
        // Must run last so existing caisse balances are stable.
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 8/9 — Provision commercial caisses and seed accounts');
        $this->info('───────────────────────────────────────────────────────────');
        $this->provisionCommercialCaissesAndSeedAccounts($isDryRun);

        // Step 9 runs inline (no sub-command)
        // Must run after step 0 (fleet vehicles seeded) and step 0.5 (fixed costs seeded).
        $this->newLine();
        $this->info('───────────────────────────────────────────────────────────');
        $this->info('  Step 9/9 — Seed vehicle cost accounts and fixed cost accounts');
        $this->info('───────────────────────────────────────────────────────────');
        $this->seedVehicleAndFixedCostAccounts($isDryRun);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');

        if ($isDryRun) {
            $this->warn('  Dry-run complete. Re-run without --dry-run to apply.');
        } else {
            $this->info('  All steps completed successfully.');
        }

        $this->info('═══════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }

    /**
     * Sets packaging_cost = PRODUCT_PACKAGING_COST_XOF on the products listed in
     * PRODUCT_IDS_WITH_PACKAGING_COST, then propagates that same value to every
     * stock_entry row for those products.
     *
     * Both updates are skipped when the value is already correct — idempotent.
     */
    private function backfillProductPackagingCosts(bool $isDryRun): void
    {
        $productIds = self::PRODUCT_IDS_WITH_PACKAGING_COST;
        $packagingCost = self::PRODUCT_PACKAGING_COST_XOF;

        // ── Products ──────────────────────────────────────────────────────────

        $productsToUpdate = DB::table('products')
            ->whereIn('id', $productIds)
            ->where('packaging_cost', '!=', $packagingCost)
            ->get(['id', 'name', 'packaging_cost']);

        if ($productsToUpdate->isEmpty()) {
            $this->info("  All target products already have packaging_cost = {$packagingCost} F. Skipping product update.");
        } else {
            foreach ($productsToUpdate as $product) {
                $this->line("  Product #{$product->id} «{$product->name}»: packaging_cost {$product->packaging_cost} → {$packagingCost} F.");

                if (! $isDryRun) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['packaging_cost' => $packagingCost]);
                }
            }

            $this->info("  Done — {$productsToUpdate->count()} product(s) updated.");
        }

        // ── Stock entries ─────────────────────────────────────────────────────

        $stockEntriesToUpdate = DB::table('stock_entries')
            ->whereIn('product_id', $productIds)
            ->where('packaging_cost', '!=', $packagingCost)
            ->count();

        if ($stockEntriesToUpdate === 0) {
            $this->info("  All stock entries for target products already have packaging_cost = {$packagingCost} F. Skipping.");

            return;
        }

        $this->line("  {$stockEntriesToUpdate} stock entry/entries for target products need packaging_cost → {$packagingCost} F.");

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would update {$stockEntriesToUpdate} stock entry/entries.");

            return;
        }

        DB::table('stock_entries')
            ->whereIn('product_id', $productIds)
            ->update(['packaging_cost' => $packagingCost]);

        $this->info("  Done — {$stockEntriesToUpdate} stock entry/entries updated.");
    }

    /**
     * Sets transportation_cost = PURCHASE_INVOICE_TRANSPORTATION_COST_XOF on every
     * purchase invoice, then distributes that cost proportionally (by quantity) across
     * the invoice's line items, and finally propagates the per-unit transport rate to
     * each item's linked stock entry.
     *
     * Distribution formula (exact integer, no rounding loss):
     *   base_cost_per_unit = floor(8000 / total_invoice_quantity)
     *   remainder_units    = 8000 % total_invoice_quantity
     *   item.transportation_cost = base_cost_per_unit × item.quantity
     *                              + min(remainder_units_left, item.quantity)  [first items absorb 1 F each]
     *   SUM(item.transportation_cost) == 8000 guaranteed.
     *
     * stock_entry.transportation_cost = round(item.transportation_cost / item.quantity) [per-unit].
     *
     * Idempotent: re-running produces the same values.
     */
    private function backfillPurchaseInvoiceTransportationCosts(bool $isDryRun): void
    {
        $invoiceTransportCost = self::PURCHASE_INVOICE_TRANSPORTATION_COST_XOF;

        $purchaseInvoices = DB::table('purchase_invoices')
            ->orderBy('id')
            ->get(['id', 'transportation_cost']);

        if ($purchaseInvoices->isEmpty()) {
            $this->info('  No purchase invoices found. Nothing to backfill.');

            return;
        }

        $this->line("  {$purchaseInvoices->count()} purchase invoice(s) will be set to {$invoiceTransportCost} F transportation cost.");

        $totalItemsUpdated = 0;
        $totalStockEntriesUpdated = 0;

        foreach ($purchaseInvoices as $purchaseInvoice) {
            $items = DB::table('purchase_invoice_items')
                ->where('purchase_invoice_id', $purchaseInvoice->id)
                ->orderBy('id')
                ->get(['id', 'quantity', 'transportation_cost']);

            if ($items->isEmpty()) {
                $this->line("  Invoice #{$purchaseInvoice->id}: no items — skipping distribution.");

                continue;
            }

            $totalInvoiceQuantity = $items->sum('quantity');

            if ($totalInvoiceQuantity <= 0) {
                $this->line("  Invoice #{$purchaseInvoice->id}: total quantity is 0 — skipping.");

                continue;
            }

            $this->line("  Invoice #{$purchaseInvoice->id}: {$items->count()} item(s), total qty {$totalInvoiceQuantity}, distributing {$invoiceTransportCost} F.");

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would set transportation_cost = {$invoiceTransportCost} F and distribute to items.");

                continue;
            }

            // ── Set total transportation cost on the invoice ──────────────────

            DB::table('purchase_invoices')
                ->where('id', $purchaseInvoice->id)
                ->update(['transportation_cost' => $invoiceTransportCost]);

            // ── Distribute to line items (exact integer, no rounding loss) ────

            $baseCostPerUnit = intdiv($invoiceTransportCost, $totalInvoiceQuantity);
            $remainderUnits = $invoiceTransportCost % $totalInvoiceQuantity;

            foreach ($items as $item) {
                // Give this item its proportional share plus absorb up to item.quantity
                // units of the remainder (1 F per unit) so the total is exact.
                $itemTransportCost = $baseCostPerUnit * $item->quantity
                    + min($remainderUnits, $item->quantity);

                $remainderUnits = max(0, $remainderUnits - $item->quantity);

                DB::table('purchase_invoice_items')
                    ->where('id', $item->id)
                    ->update(['transportation_cost' => $itemTransportCost]);

                $totalItemsUpdated++;

                // ── Propagate per-unit cost to the linked stock entry ─────────

                $perUnitTransportCost = $item->quantity > 0
                    ? (int) round($itemTransportCost / $item->quantity)
                    : 0;

                $updatedStockEntryCount = DB::table('stock_entries')
                    ->where('purchase_invoice_item_id', $item->id)
                    ->update(['transportation_cost' => $perUnitTransportCost]);

                $totalStockEntriesUpdated += $updatedStockEntryCount;
            }
        }

        $this->info("  Done — {$purchaseInvoices->count()} invoice(s) updated, {$totalItemsUpdated} item(s) updated, {$totalStockEntriesUpdated} stock entry/entries updated.");
    }

    /**
     * Assigns the single fleet vehicle to every car load that has no vehicle_id.
     *
     * Uses the model (not raw DB) so the CarLoad::saving boot hook fires on each
     * record and snapshots fixed_daily_cost at the vehicle's current daily rate.
     *
     * Idempotent: car loads that already have a vehicle_id are skipped.
     */
    private function backfillCarLoadVehicle(bool $isDryRun): void
    {
        $fleetPlateNumber = self::FLEET_VEHICLES[0]['plate_number'];

        $vehicle = Vehicle::where('plate_number', $fleetPlateNumber)->first();

        if ($vehicle === null) {
            $this->warn("  Vehicle «{$fleetPlateNumber}» not found — cannot link car loads. Run Step 0 first.");

            return;
        }

        $unlinkedCarLoads = CarLoad::whereNull('vehicle_id')->orderBy('id')->get();

        if ($unlinkedCarLoads->isEmpty()) {
            $this->info('  All car loads already have a vehicle. Nothing to do.');

            return;
        }

        $this->line("  {$unlinkedCarLoads->count()} car load(s) have no vehicle — will assign «{$vehicle->name}» (id #{$vehicle->id}).");

        if ($isDryRun) {
            foreach ($unlinkedCarLoads as $carLoad) {
                $this->warn("  [DRY RUN] Would link car load #{$carLoad->id} «{$carLoad->name}» → vehicle «{$vehicle->name}».");
            }

            return;
        }

        foreach ($unlinkedCarLoads as $carLoad) {
            // Assigning via the model triggers the saving hook which snapshots fixed_daily_cost.
            $carLoad->update(['vehicle_id' => $vehicle->id]);
            $this->info("  Linked car load #{$carLoad->id} «{$carLoad->name}» → vehicle «{$vehicle->name}» (fixed_daily_cost: {$carLoad->fixed_daily_cost} F/j).");
        }

        $this->info("  Done — {$unlinkedCarLoads->count()} car load(s) linked to «{$vehicle->name}».");
    }

    /**
     * Ensures every entry in MONTHLY_FIXED_COSTS exists in the database.
     * Matches by (cost_pool + sub_category + period_year + period_month) — skips if already present.
     * Idempotent: safe to re-run on an already-seeded database.
     */
    private function seedMonthlyFixedCosts(bool $isDryRun): void
    {
        foreach (self::MONTHLY_FIXED_COSTS as $costData) {
            $existing = MonthlyFixedCost::where('cost_pool', $costData['cost_pool'])
                ->where('sub_category', $costData['sub_category'])
                ->where('period_year', $costData['period_year'])
                ->where('period_month', $costData['period_month'])
                ->first();

            $label = $costData['label'];
            $period = $costData['period_year'].'-'.str_pad((string) $costData['period_month'], 2, '0', STR_PAD_LEFT);

            if ($existing !== null) {
                $this->line("  Monthly cost «{$label}» {$period} already exists (id #{$existing->id}). Skipping.");

                continue;
            }

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create monthly cost «{$label}» {$period} — {$costData['amount']} F.");

                continue;
            }

            $cost = MonthlyFixedCost::create($costData);
            $this->info("  Created monthly cost «{$label}» {$period} — {$costData['amount']} F (id #{$cost->id}).");
        }
    }

    /**
     * Ensures every vehicle in FLEET_VEHICLES exists in the database.
     * Matches by plate_number — skips the vehicle if it already exists.
     * Idempotent: safe to re-run on an already-seeded database.
     */
    private function seedFleetVehicles(bool $isDryRun): void
    {
        foreach (self::FLEET_VEHICLES as $vehicleData) {
            $plateNumber = $vehicleData['plate_number'];

            $existingVehicle = Vehicle::where('plate_number', $plateNumber)->first();

            if ($existingVehicle !== null) {
                $this->line("  Vehicle «{$plateNumber}» already exists (id #{$existingVehicle->id}). Skipping.");

                continue;
            }

            $dailyRate = (int) round(
                ($vehicleData['insurance_monthly'] + $vehicleData['maintenance_monthly']
                + $vehicleData['repair_reserve_monthly'] + $vehicleData['depreciation_monthly']
                + $vehicleData['driver_salary_monthly'])
                / $vehicleData['working_days_per_month']
            );

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create vehicle «{$plateNumber}» — daily rate: {$dailyRate} F/jour.");

                continue;
            }

            $vehicle = Vehicle::create($vehicleData);
            $this->info("  Created vehicle «{$plateNumber}» (id #{$vehicle->id}) — daily rate: {$dailyRate} F/jour.");
        }
    }

    /**
     * Creates one CarLoadExpense (type=FUEL) of WEEKLY_FUEL_COST_XOF per working week
     * (Mon–Sat) within each car load's active period.
     *
     * A car load spanning one month gets roughly 4 entries (4 × WEEKLY_FUEL_COST_XOF).
     * A car load is skipped entirely if it already has any expense entries, making this
     * step safe to re-run without creating duplicates.
     *
     * Date range per car load:
     *  - Start : load_date (car loads without a load_date are skipped)
     *  - End   : return_date if it has already passed, otherwise today
     *
     * Inserts are done via DB::table() to bypass model events and avoid
     * dispatching RecalculateInvoicesDeliveryCostJob for every historical entry.
     */
    private function backfillFuelEntries(bool $isDryRun): void
    {
        $carLoadsWithoutExpenses = CarLoad::whereDoesntHave('expenses')
            ->whereNotNull('load_date')
            ->orderBy('id')
            ->get();

        if ($carLoadsWithoutExpenses->isEmpty()) {
            $this->info('  All car loads already have expense entries. Nothing to backfill.');

            return;
        }

        $this->line("  {$carLoadsWithoutExpenses->count()} car load(s) have no expenses — backfilling at ".self::WEEKLY_FUEL_COST_XOF.' F/week.');

        $totalEntriesCreated = 0;

        foreach ($carLoadsWithoutExpenses as $carLoad) {
            $startDate = Carbon::parse($carLoad->load_date)->startOfDay();

            $endDate = ($carLoad->return_date !== null && Carbon::parse($carLoad->return_date)->isPast())
                ? Carbon::parse($carLoad->return_date)->startOfDay()
                : today();

            // Count working weeks: start from Monday of the week containing load_date
            // so partial first weeks are included.
            $weekStart = $startDate->copy()->startOfWeek(Carbon::MONDAY);
            $numberOfWeeks = 0;

            while ($weekStart->lte($endDate)) {
                $numberOfWeeks++;
                $weekStart->addWeek();
            }

            if ($numberOfWeeks === 0) {
                $this->line("  Car load #{$carLoad->id} «{$carLoad->name}»: no weeks found in range — skipping.");

                continue;
            }

            $totalFuel = $numberOfWeeks * self::WEEKLY_FUEL_COST_XOF;
            $this->line("  Car load #{$carLoad->id} «{$carLoad->name}»: {$numberOfWeeks} week(s) × ".self::WEEKLY_FUEL_COST_XOF." F = {$totalFuel} F ({$startDate->toDateString()} → {$endDate->toDateString()}).");

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create {$numberOfWeeks} weekly fuel expense(s) of ".self::WEEKLY_FUEL_COST_XOF.' F each.');

                continue;
            }

            $now = now();

            $expensesToInsert = array_fill(0, $numberOfWeeks, [
                'car_load_id' => $carLoad->id,
                'label' => 'Carburant hebdomadaire',
                'type' => CarLoadExpenseType::Fuel->value,
                'amount' => self::WEEKLY_FUEL_COST_XOF,
                'notes' => 'Reconstitution historique',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('car_load_expenses')->insert($expensesToInsert);

            $totalEntriesCreated += $numberOfWeeks;

            $this->info("  Created {$numberOfWeeks} weekly fuel expense(s) for car load #{$carLoad->id}.");
        }

        $this->info("  Done — {$totalEntriesCreated} weekly fuel expenses created across {$carLoadsWithoutExpenses->count()} car load(s).");
    }

    /**
     * Creates the "Dépôt Ouest Foire" warehouse if it does not exist, then
     * links every stock_entry whose warehouse_id is NULL to that warehouse.
     *
     * Uses the first user in the database as the warehouse manager, since
     * historical entries predate per-warehouse manager assignment.
     *
     * This is idempotent — re-running it is safe (already-linked entries are skipped).
     */
    private function backfillStockEntryWarehouse(bool $isDryRun): void
    {
        $firstUser = DB::table('users')->orderBy('id')->first(['id', 'name']);

        if (! $firstUser) {
            $this->warn('  No users found in the database. Cannot create warehouse without a manager. Skipping.');

            return;
        }

        $unlinkedCount = DB::table('stock_entries')->whereNull('warehouse_id')->count();

        if ($unlinkedCount === 0) {
            $this->info('  All stock entries already have a warehouse. Nothing to do.');

            return;
        }

        $this->line("  {$unlinkedCount} stock entries have no warehouse_id.");

        // Find or preview the warehouse
        $existingWarehouse = DB::table('warehouses')
            ->where('name', self::DEPOT_OUEST_FOIRE_NAME)
            ->first(['id', 'name']);

        if ($existingWarehouse) {
            $this->line("  Warehouse «{$existingWarehouse->name}» already exists (id #{$existingWarehouse->id}).");
            $warehouseId = $existingWarehouse->id;
        } else {
            $this->line('  Warehouse «'.self::DEPOT_OUEST_FOIRE_NAME.'» does not exist yet — will create it.');
            $warehouseId = null;
        }

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would link {$unlinkedCount} stock entries to «".self::DEPOT_OUEST_FOIRE_NAME.'».');

            return;
        }

        if (! $existingWarehouse) {
            $warehouseId = DB::table('warehouses')->insertGetId([
                'name' => self::DEPOT_OUEST_FOIRE_NAME,
                'address' => self::DEPOT_OUEST_FOIRE_ADDRESS,
                'manager_id' => $firstUser->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('  Created warehouse «'.self::DEPOT_OUEST_FOIRE_NAME."» (id #{$warehouseId}), manager: «{$firstUser->name}» (id #{$firstUser->id}).");
        }

        $updatedCount = DB::table('stock_entries')
            ->whereNull('warehouse_id')
            ->update(['warehouse_id' => $warehouseId]);

        $this->info("  Done — {$updatedCount} stock entries linked to warehouse #{$warehouseId}.");
    }

    /**
     * Sets all car loads to TERMINATED_AND_TRANSFERRED, except the most recent
     * one (by id) which is set to ONGOING_INVENTORY to reflect that the current
     * cycle is still being reconciled.
     *
     * This is idempotent — re-running it is safe.
     */
    private function backfillCarLoadStatuses(bool $isDryRun): void
    {
        $allCarLoads = DB::table('car_loads')->orderBy('id')->get(['id', 'name', 'status']);

        if ($allCarLoads->isEmpty()) {
            $this->info('  No car loads found. Nothing to do.');

            return;
        }

        $latestCarLoadId = $allCarLoads->last()->id;

        $terminatedCount = 0;
        $skippedCount = 0;

        foreach ($allCarLoads as $carLoad) {
            $targetStatus = $carLoad->id === $latestCarLoadId
                ? CarLoadStatus::OngoingInventory->value
                : CarLoadStatus::TerminatedAndTransferred->value;

            if ($carLoad->status === $targetStatus) {
                $this->line("  Skip car load #{$carLoad->id} «{$carLoad->name}»: already {$targetStatus}");
                $skippedCount++;

                continue;
            }

            $this->line("  Car load #{$carLoad->id} «{$carLoad->name}»: {$carLoad->status} → {$targetStatus}");

            if (! $isDryRun) {
                DB::table('car_loads')
                    ->where('id', $carLoad->id)
                    ->update(['status' => $targetStatus]);
            }

            $terminatedCount++;
        }

        $this->info("  Done — {$terminatedCount} car loads updated, {$skippedCount} already correct.");
    }

    /**
     * Creates all product categories defined in PRODUCT_CATEGORIES if they do not yet exist,
     * then assigns every product with no category to the first entry (JETABLES — the default).
     *
     * Matched by name — idempotent, safe to re-run.
     */
    private function seedDefaultProductCategory(bool $isDryRun): void
    {
        $fallbackCategoryName = self::PRODUCT_CATEGORIES[0]['name'];
        $fallbackCategory = null;

        foreach (self::PRODUCT_CATEGORIES as $categoryData) {
            $categoryName = $categoryData['name'];
            $commissionRate = $categoryData['commission_rate'];

            $existingCategory = ProductCategory::where('name', $categoryName)->first();

            if ($existingCategory !== null) {
                $this->line("  Category «{$categoryName}» already exists (id #{$existingCategory->id}). Skipping creation.");
                if ($categoryName === $fallbackCategoryName) {
                    $fallbackCategory = $existingCategory;
                }

                continue;
            }

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create category «{$categoryName}» — commission rate: {$commissionRate}.");

                continue;
            }

            $created = ProductCategory::create([
                'name' => $categoryName,
                'commission_rate' => $commissionRate,
            ]);

            $this->info("  Created category «{$categoryName}» (id #{$created->id}) — commission rate: {$commissionRate}.");

            if ($categoryName === $fallbackCategoryName) {
                $fallbackCategory = $created;
            }
        }

        // Assign products with no category to the fallback (JETABLES).
        $productsWithoutCategory = DB::table('products')->whereNull('product_category_id')->count();

        if ($productsWithoutCategory === 0) {
            $this->info('  All products already have a category. Nothing to assign.');

            return;
        }

        $this->line("  {$productsWithoutCategory} product(s) have no category — will assign to «{$fallbackCategoryName}».");

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would assign {$productsWithoutCategory} product(s) to «{$fallbackCategoryName}».");

            return;
        }

        if ($fallbackCategory === null) {
            $this->error("  Fallback category «{$fallbackCategoryName}» could not be resolved. Skipping product assignment.");

            return;
        }

        $assignedCount = DB::table('products')
            ->whereNull('product_category_id')
            ->update(['product_category_id' => $fallbackCategory->id]);

        $this->info("  Done — {$assignedCount} product(s) assigned to «{$fallbackCategoryName}» (id #{$fallbackCategory->id}).");
    }

    /**
     * Backfills delivery_cost on every historical sales invoice that is linked to a car load.
     *
     * For future car loads (exactly 7 working days), the standard daily-rate formula in
     * AbcVehicleCostService is correct and handles delivery cost redistribution automatically.
     * For historical car loads, the calendar span (load_date → return_date) is unreliable
     * because selling may have stopped weeks before the car load was formally closed.
     *
     * This method uses a different denominator: the number of distinct Mon–Sat calendar
     * dates on which the car load actually generated at least one invoice ("selling days").
     * This produces a realistic daily cost that reflects actual commercial activity.
     *
     * Daily cost formula (per car load):
     *   fixed_daily_cost            — rate already snapshotted on the car load
     *   + round(total_expenses / selling_days)  — actual fuel/other costs spread over selling days
     *
     * That daily cost is then split equally across all invoices created on the same calendar
     * day for that car load. The integer remainder is distributed 1 XOF at a time to the
     * first invoices (ordered by id) so the sum is exact.
     *
     * All writes go directly through DB::table() to bypass model events and avoid
     * dispatching RecalculateInvoicesDeliveryCostJob for every historical row.
     *
     * Idempotent: re-running overwrites delivery_cost with the same computed values.
     */
    private function backfillInvoiceDeliveryCosts(bool $isDryRun): void
    {
        $carLoadIdsWithInvoices = DB::table('sales_invoices')
            ->whereNotNull('car_load_id')
            ->distinct()
            ->orderBy('car_load_id')
            ->pluck('car_load_id');

        if ($carLoadIdsWithInvoices->isEmpty()) {
            $this->info('  No invoices with a car_load_id found. Nothing to backfill.');

            return;
        }

        $this->line("  {$carLoadIdsWithInvoices->count()} car load(s) have linked invoices — computing selling-day delivery costs.");

        $totalInvoicesUpdated = 0;

        foreach ($carLoadIdsWithInvoices as $carLoadId) {
            $carLoad = CarLoad::find($carLoadId);

            if ($carLoad === null) {
                $this->warn("  Car load #{$carLoadId} not found in car_loads table — skipping.");

                continue;
            }

            // Collect all distinct dates that had at least one invoice for this car load,
            // then exclude Sundays in PHP so this works on both MySQL and SQLite.
            $allInvoiceDates = DB::table('sales_invoices')
                ->where('car_load_id', $carLoadId)
                ->selectRaw('DATE(created_at) as work_day')
                ->distinct()
                ->orderBy('work_day')
                ->pluck('work_day');

            $sellingDates = $allInvoiceDates->filter(
                fn (string $date) => Carbon::parse($date)->dayOfWeek !== Carbon::SUNDAY
            )->values();

            $sellingDays = $sellingDates->count();

            if ($sellingDays === 0) {
                $this->line("  Car load #{$carLoadId} «{$carLoad->name}»: no Mon–Sat selling days found — skipping.");

                continue;
            }

            // Daily cost: fixed snapshot rate + variable expenses prorated over selling days.
            $fixedDailyRate = (int) ($carLoad->fixed_daily_cost ?? 0);
            $totalVariableExpenses = (int) $carLoad->expenses()->sum('amount');
            $dailyCost = $fixedDailyRate + (int) round($totalVariableExpenses / $sellingDays);

            $this->line(
                "  Car load #{$carLoadId} «{$carLoad->name}»: {$sellingDays} selling day(s), "
                ."daily cost: {$dailyCost} F "
                ."(fixed: {$fixedDailyRate} F/day + variable: {$totalVariableExpenses} F / {$sellingDays} days)."
            );

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would update delivery_cost for all invoices of car load #{$carLoadId}.");

                continue;
            }

            foreach ($sellingDates as $workDay) {
                $invoiceIds = DB::table('sales_invoices')
                    ->where('car_load_id', $carLoadId)
                    ->whereDate('created_at', $workDay)
                    ->orderBy('id')
                    ->pluck('id');

                $numberOfInvoices = $invoiceIds->count();
                $baseDeliveryCostPerInvoice = intdiv($dailyCost, $numberOfInvoices);
                $remainder = $dailyCost % $numberOfInvoices;

                foreach ($invoiceIds->values() as $index => $invoiceId) {
                    // Distribute remainder 1 XOF at a time to first invoices so SUM == dailyCost.
                    $deliveryCost = $baseDeliveryCostPerInvoice + ($index < $remainder ? 1 : 0);

                    DB::table('sales_invoices')
                        ->where('id', $invoiceId)
                        ->update(['delivery_cost' => $deliveryCost]);
                }

                $totalInvoicesUpdated += $numberOfInvoices;
            }

            $this->info("  Car load #{$carLoadId}: {$sellingDates->count()} day(s) updated.");
        }

        $this->info("  Done — {$totalInvoicesUpdated} invoice(s) updated across {$carLoadIdsWithInvoices->count()} car load(s).");
    }

    /**
     * Sets credit_price = round(price × 1.20) on every product that does not yet have a
     * credit_price configured.
     *
     * Products that already have a credit_price set are left untouched — this allows
     * manually configured credit prices to be preserved across re-runs.
     *
     * Idempotent: safe to re-run.
     */
    private function backfillProductCreditPrices(bool $isDryRun): void
    {
        $productsWithoutCreditPrice = DB::table('products')
            ->whereNull('credit_price')
            ->orderBy('id')
            ->get(['id', 'name', 'price']);

        if ($productsWithoutCreditPrice->isEmpty()) {
            $this->info('  All products already have a credit_price set. Nothing to do.');

            return;
        }

        $this->line("  {$productsWithoutCreditPrice->count()} product(s) have no credit_price — will set to price × 1.20.");

        $updatedCount = 0;

        foreach ($productsWithoutCreditPrice as $product) {
            $creditPrice = (int) round($product->price * 1.20);
            $this->line("  Product #{$product->id} «{$product->name}»: price {$product->price} F → credit_price {$creditPrice} F (+20%).");

            if (! $isDryRun) {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update(['credit_price' => $creditPrice]);
            }

            $updatedCount++;
        }

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would set credit_price on {$updatedCount} product(s).");

            return;
        }

        $this->info("  Done — {$updatedCount} product(s) updated with credit_price (+20%).");
    }

    /**
     * Normalises payment_method values in both the payments and ventes tables to uppercase.
     *
     * Historical records were created with mixed-case values ('Cash', 'Wave', 'Om').
     * The canonical form is now all-uppercase: 'CASH', 'WAVE', 'OM'.
     *
     * The mapping is applied to any mixed-case variant, making this idempotent —
     * records already in uppercase are unaffected by the WHERE clause.
     *
     * Both tables are updated because legacy TYPE_SINGLE ventes stored payment_method
     * directly on the vente row before the SalesInvoice model was introduced.
     *
     * @var array<string, string> Maps every known legacy value to its canonical uppercase form.
     */
    private function normalizePaymentMethodsToUppercase(bool $isDryRun): void
    {
        $canonicalMapping = [
            'Cash' => 'CASH',
            'cash' => 'CASH',
            'Wave' => 'WAVE',
            'wave' => 'WAVE',
            'Om' => 'OM',
            'om' => 'OM',
        ];

        $tables = ['payments', 'ventes'];
        $totalUpdated = 0;

        foreach ($tables as $table) {
            foreach ($canonicalMapping as $legacyValue => $canonicalValue) {
                $count = DB::table($table)
                    ->where('payment_method', $legacyValue)
                    ->count();

                if ($count === 0) {
                    continue;
                }

                $this->line("  {$table}: {$count} row(s) with payment_method = '{$legacyValue}' → '{$canonicalValue}'");

                if (! $isDryRun) {
                    DB::table($table)
                        ->where('payment_method', $legacyValue)
                        ->update(['payment_method' => $canonicalValue]);
                }

                $totalUpdated += $count;
            }
        }

        if ($totalUpdated === 0) {
            $this->info('  All payment methods are already uppercase. Nothing to do.');

            return;
        }

        $this->info("  Done — {$totalUpdated} row(s) normalised across ".count($tables).' tables.');
    }

    /**
     * Provisions a personal caisse + two accounts (COMMERCIAL_COLLECTED and COMMERCIAL_COMMISSION)
     * for every commercial that does not already have one.
     *
     * Then seeds the global MERCHANDISE_SALES account and initialises it with the sum of all
     * existing main-caisse balances so the company-wide invariant holds from day one:
     *
     *   SUM(account.balance) == SUM(caisse.balance)
     *
     * Idempotent: re-running is safe — already-provisioned commercials and the existing
     * MERCHANDISE_SALES account balance are left untouched.
     */
    private function provisionCommercialCaissesAndSeedAccounts(bool $isDryRun): void
    {
        // ── 1. Provision personal caisses + accounts for each commercial ──────

        $allCommercials = Commercial::orderBy('id')->get();

        if ($allCommercials->isEmpty()) {
            $this->info('  No commercials found. Nothing to provision.');
        } else {
            $this->line("  {$allCommercials->count()} commercial(s) found.");
            $provisioned = 0;

            foreach ($allCommercials as $commercial) {
                $existingCaisse = Caisse::where('commercial_id', $commercial->id)
                    ->where('caisse_type', CaisseType::Commercial->value)
                    ->first();

                if ($existingCaisse !== null) {
                    $this->line("  Commercial «{$commercial->name}»: caisse already exists (id #{$existingCaisse->id}). Skipping.");

                    continue;
                }

                if ($isDryRun) {
                    $this->warn("  [DRY RUN] Would provision caisse + accounts for «{$commercial->name}».");

                    continue;
                }

                Caisse::create([
                    'name' => "Caisse — {$commercial->name}",
                    'caisse_type' => CaisseType::Commercial,
                    'commercial_id' => $commercial->id,
                    'balance' => 0,
                    'closed' => false,
                ]);

                Account::firstOrCreate(
                    ['account_type' => AccountType::CommercialCollected->value, 'commercial_id' => $commercial->id],
                    ['name' => "Encaissements — {$commercial->name}", 'balance' => 0, 'is_active' => true]
                );

                Account::firstOrCreate(
                    ['account_type' => AccountType::CommercialCommission->value, 'commercial_id' => $commercial->id],
                    ['name' => "Commission — {$commercial->name}", 'balance' => 0, 'is_active' => true]
                );

                $this->info("  Provisioned caisse + accounts for «{$commercial->name}».");
                $provisioned++;
            }

            if ($provisioned > 0) {
                $this->info("  Done — {$provisioned} commercial(s) provisioned.");
            }
        }

        // ── 2. Seed the MERCHANDISE_SALES account ─────────────────────────────

        $merchandiseSalesAccount = Account::where('account_type', AccountType::MerchandiseSales->value)->first();

        $totalMainCaisseBalance = Caisse::where('caisse_type', CaisseType::Main->value)->sum('balance');
        $totalAccountBalance = Account::sum('balance');

        // The invariant requires: SUM(accounts) == SUM(caisses).
        // After provisioning commercial accounts (all at 0), the deficit is:
        //   totalCaisseBalance - totalAccountBalance
        // which corresponds to the existing main caisse balances not yet attributed to any account.
        $totalCaisseBalance = Caisse::sum('balance');
        $deficit = $totalCaisseBalance - $totalAccountBalance;

        if ($merchandiseSalesAccount === null) {
            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create MERCHANDISE_SALES account and seed it with {$deficit} F.");

                return;
            }

            $merchandiseSalesAccount = Account::create([
                'name' => 'Vente marchandises',
                'account_type' => AccountType::MerchandiseSales,
                'balance' => 0,
                'is_active' => true,
            ]);

            $this->info("  Created MERCHANDISE_SALES account (id #{$merchandiseSalesAccount->id}).");
        } else {
            $this->line("  MERCHANDISE_SALES account already exists (id #{$merchandiseSalesAccount->id}, balance: {$merchandiseSalesAccount->balance} F).");
        }

        if ($deficit <= 0) {
            $this->info('  Invariant already satisfied. No initial seeding needed.');

            return;
        }

        if ($isDryRun) {
            $this->warn("  [DRY RUN] Would credit MERCHANDISE_SALES with {$deficit} F to satisfy the invariant.");

            return;
        }

        // Credit the deficit to MERCHANDISE_SALES to initialise the invariant.
        AccountTransaction::create([
            'account_id' => $merchandiseSalesAccount->id,
            'amount' => $deficit,
            'transaction_type' => 'CREDIT',
            'label' => 'Solde initial — attribution des encaisses existantes',
            'reference_type' => 'INITIAL',
            'reference_id' => null,
        ]);

        $merchandiseSalesAccount->increment('balance', $deficit);

        $this->info("  Credited MERCHANDISE_SALES with {$deficit} F (initial balance attribution). Invariant satisfied.");
    }

    /**
     * Creates the five vehicle cost accounts for each fleet vehicle in the database,
     * and one FixedCost account for each distinct label found in the monthly_fixed_costs table.
     *
     * Vehicle accounts created per vehicle:
     *   VEHICLE_DEPRECIATION, VEHICLE_INSURANCE, VEHICLE_REPAIR_RESERVE,
     *   VEHICLE_MAINTENANCE, VEHICLE_FUEL
     *
     * Fixed cost accounts are keyed by the label column of monthly_fixed_costs.
     * Each unique label gets exactly one account, regardless of how many periods
     * share that label.
     *
     * Idempotent: existing accounts are skipped — safe to re-run.
     */
    private function seedVehicleAndFixedCostAccounts(bool $isDryRun): void
    {
        $accountService = app(AccountService::class);

        $vehicleAccountTypes = [
            AccountType::VehicleDepreciation,
            AccountType::VehicleInsurance,
            AccountType::VehicleRepairReserve,
            AccountType::VehicleMaintenance,
            AccountType::VehicleFuel,
        ];

        // ── Vehicle cost accounts (5 per vehicle) ────────────────────────────

        $vehicles = Vehicle::orderBy('id')->get();

        if ($vehicles->isEmpty()) {
            $this->info('  No vehicles found — run Step 0 first to seed the fleet. Skipping vehicle accounts.');
        } else {
            $vehicleAccountsCreated = 0;

            foreach ($vehicles as $vehicle) {
                $this->line("  Vehicle «{$vehicle->name}» (id #{$vehicle->id}):");

                foreach ($vehicleAccountTypes as $accountType) {
                    $existingAccount = Account::where('account_type', $accountType->value)
                        ->where('vehicle_id', $vehicle->id)
                        ->first();

                    $accountName = "{$accountType->label()} — {$vehicle->name}";

                    if ($existingAccount !== null) {
                        $this->line("    ✓ {$accountName} already exists (id #{$existingAccount->id}).");

                        continue;
                    }

                    if ($isDryRun) {
                        $this->warn("    [DRY RUN] Would create account «{$accountName}».");

                        continue;
                    }

                    $created = $accountService->getOrCreateVehicleAccount($vehicle, $accountType);
                    $this->info("    + Created «{$accountName}» (id #{$created->id}).");
                    $vehicleAccountsCreated++;
                }
            }

            if (! $isDryRun) {
                $this->info("  Done — {$vehicleAccountsCreated} vehicle account(s) created across {$vehicles->count()} vehicle(s).");
            }
        }

        // ── Fixed cost accounts (one per distinct label) ──────────────────────

        $this->newLine();

        $distinctFixedCostLabels = MonthlyFixedCost::query()
            ->whereNotNull('label')
            ->distinct()
            ->orderBy('label')
            ->pluck('label');

        // Also collect labels derived from sub_category for fixed costs without a label.
        $subCategoryDerivedLabels = MonthlyFixedCost::query()
            ->whereNull('label')
            ->distinct()
            ->orderBy('sub_category')
            ->pluck('sub_category')
            ->map(fn ($subCategory) => is_string($subCategory) ? $subCategory : $subCategory->value);

        $allFixedCostLabels = $distinctFixedCostLabels->merge($subCategoryDerivedLabels)->unique()->sort()->values();

        if ($allFixedCostLabels->isEmpty()) {
            $this->info('  No monthly fixed costs found — run Step 0.5 first to seed fixed costs. Skipping fixed cost accounts.');

            return;
        }

        $fixedCostAccountsCreated = 0;

        foreach ($allFixedCostLabels as $label) {
            $existingAccount = Account::where('account_type', AccountType::FixedCost->value)
                ->where('name', $label)
                ->first();

            if ($existingAccount !== null) {
                $this->line("  ✓ Fixed cost account «{$label}» already exists (id #{$existingAccount->id}).");

                continue;
            }

            if ($isDryRun) {
                $this->warn("  [DRY RUN] Would create fixed cost account «{$label}».");

                continue;
            }

            $created = $accountService->getOrCreateFixedCostAccount($label);
            $this->info("  + Created fixed cost account «{$label}» (id #{$created->id}).");
            $fixedCostAccountsCreated++;
        }

        if (! $isDryRun) {
            $this->info("  Done — {$fixedCostAccountsCreated} fixed cost account(s) created.");
        }
    }

    private function allMigrationsHaveBeenRun(): bool
    {
        $ranMigrations = DB::table('migrations')->pluck('migration')->all();

        $allMigrationFiles = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME));

        $pendingMigrations = $allMigrationFiles->reject(
            fn (string $filename) => in_array($filename, $ranMigrations, strict: true)
        );

        if ($pendingMigrations->isNotEmpty()) {
            $this->warn('Pending migrations:');
            $pendingMigrations->each(fn (string $filename) => $this->line("  • {$filename}"));
        }

        return $pendingMigrations->isEmpty();
    }
}
