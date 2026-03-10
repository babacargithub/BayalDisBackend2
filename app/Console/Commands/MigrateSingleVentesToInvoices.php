<?php

namespace App\Console\Commands;

use App\Enums\SalesInvoiceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retroactively wraps legacy TYPE_SINGLE ventes into proper SalesInvoices.
 *
 * The mobile app recorded some sales as TYPE_SINGLE ventes — not linked to any
 * invoice or payment. This command migrates ALL of them:
 *
 * 1. Finds every unlinked TYPE_SINGLE vente (no date range restriction).
 * 2. Groups them by (customer_id, sale_date).
 * 3. Creates one SalesInvoice per group, back-dated to the original sale date.
 * 4. Reassigns each vente to its invoice (sales_invoice_id + type = INVOICE_ITEM).
 * 5. Creates one Payment per distinct payment_method per invoice for the paid ventes.
 *
 * All writes are done via DB::table() to bypass model events. Profit recalculation
 * and recalculateStoredTotals() are intentionally left to the subsequent
 * `bayal:correct-cost-prices-and-profits` command.
 */
class MigrateSingleVentesToInvoices extends Command
{
    protected $signature = 'bayal:migrate-single-ventes-to-invoices
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Wrap all legacy TYPE_SINGLE ventes (not yet linked to an invoice) into back-dated SalesInvoices with matching payments.';

    private bool $isDryRun = false;

    private int $invoiceSequence = 0;

    public function handle(): int
    {
        $this->isDryRun = (bool) $this->option('dry-run');

        if ($this->isDryRun) {
            $this->warn('DRY RUN — no changes will be written to the database.');
        }

        $unlinkedSingleVentes = $this->loadUnlinkedSingleVentes();

        if ($unlinkedSingleVentes->isEmpty()) {
            $this->info('No unlinked TYPE_SINGLE ventes found in the migration window. Nothing to do.');

            return Command::SUCCESS;
        }

        $this->info("Found {$unlinkedSingleVentes->count()} unlinked TYPE_SINGLE ventes to migrate.");

        // Group by customer_id + calendar date so each group becomes one invoice.
        $ventesGroupedByCustomerAndDate = $unlinkedSingleVentes->groupBy(
            fn ($vente) => Carbon::parse($vente->created_at)->toDateString().'_'.$vente->customer_id
        );

        $this->info("Will create {$ventesGroupedByCustomerAndDate->count()} invoices.");

        $totalInvoicesCreated = 0;
        $totalVentesReassigned = 0;
        $totalPaymentsCreated = 0;

        DB::transaction(function () use (
            $ventesGroupedByCustomerAndDate,
            &$totalInvoicesCreated,
            &$totalVentesReassigned,
            &$totalPaymentsCreated,
        ) {
            foreach ($ventesGroupedByCustomerAndDate as $ventesInGroup) {
                $firstVente = $ventesInGroup->first();
                $saleDate = Carbon::parse($firstVente->created_at);
                $customerId = $firstVente->customer_id;

                $invoiceId = $this->createBackDatedInvoice($customerId, $saleDate);
                $totalInvoicesCreated++;

                $this->reassignVentesToInvoice($ventesInGroup, $invoiceId);
                $totalVentesReassigned += $ventesInGroup->count();

                $paymentsCreated = $this->createPaymentsForPaidVentes($ventesInGroup, $invoiceId, $saleDate);
                $totalPaymentsCreated += $paymentsCreated;

                $this->line(
                    "  Invoice HIST-{$saleDate->toDateString()}-{$customerId}: "
                    ."{$ventesInGroup->count()} ventes, {$paymentsCreated} payment(s)"
                );
            }
        });

        $this->info("Migration complete — {$totalInvoicesCreated} invoices, {$totalVentesReassigned} ventes reassigned, {$totalPaymentsCreated} payments created.");
        $this->warn('Run `bayal:correct-cost-prices-and-profits` next to recalculate all profits and invoice stored totals.');

        return Command::SUCCESS;
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    private function loadUnlinkedSingleVentes(): \Illuminate\Support\Collection
    {
        return DB::table('ventes')
            ->whereNull('sales_invoice_id')
            ->where('type', 'SINGLE')
            ->orderBy('created_at')
            ->get();
    }

    // =========================================================================
    // Invoice creation
    // =========================================================================

    private function createBackDatedInvoice(int $customerId, Carbon $saleDate): int
    {
        $this->invoiceSequence++;
        $invoiceNumber = 'HIST-'.$saleDate->format('Ymd').'-'.str_pad($this->invoiceSequence, 4, '0', STR_PAD_LEFT);

        $this->line("  Creating invoice {$invoiceNumber} for customer #{$customerId} on {$saleDate->toDateString()}");

        if ($this->isDryRun) {
            return 0;
        }

        $now = now();

        return DB::table('sales_invoices')->insertGetId([
            'invoice_number' => $invoiceNumber,
            'customer_id' => $customerId,
            'commercial_id' => null,
            'car_load_id' => null,
            'status' => SalesInvoiceStatus::Draft->value,
            'paid' => false,
            'comment' => 'Migré depuis ventes TYPE_SINGLE',
            'should_be_paid_at' => null,
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => $saleDate->copy()->startOfDay(),
            'updated_at' => $now,
        ]);
    }

    // =========================================================================
    // Vente reassignment
    // =========================================================================

    /**
     * Reassign all ventes in the group to the new invoice.
     * Uses DB::table to bypass Vente::saved → recalculateStoredTotals events.
     */
    private function reassignVentesToInvoice(\Illuminate\Support\Collection $ventesInGroup, int $invoiceId): void
    {
        if ($this->isDryRun) {
            return;
        }

        $venteIds = $ventesInGroup->pluck('id')->all();

        DB::table('ventes')
            ->whereIn('id', $venteIds)
            ->update([
                'sales_invoice_id' => $invoiceId,
                'type' => 'INVOICE_ITEM',
                'customer_id' => null, // customer is now carried by the invoice
            ]);
    }

    // =========================================================================
    // Payment creation
    // =========================================================================

    /**
     * For each distinct payment_method among the paid ventes in this group,
     * insert one Payment row back-dated to the sale date.
     *
     * Profit is intentionally left at 0 — bayal:correct-cost-prices-and-profits
     * will recalculate it correctly after vente profits have been updated.
     *
     * Uses DB::table to bypass Payment::creating (profit) and
     * Payment::saved (recalculateStoredTotals) events.
     */
    private function createPaymentsForPaidVentes(
        \Illuminate\Support\Collection $ventesInGroup,
        int $invoiceId,
        Carbon $saleDate,
    ): int {
        $paidVentes = $ventesInGroup->filter(fn ($vente) => (bool) $vente->paid);

        if ($paidVentes->isEmpty()) {
            return 0;
        }

        // Group paid ventes by payment_method; null method falls back to 'Cash'.
        $paidVentesGroupedByPaymentMethod = $paidVentes->groupBy(
            fn ($vente) => $vente->payment_method ?? 'Cash'
        );

        $paymentsCreated = 0;

        foreach ($paidVentesGroupedByPaymentMethod as $paymentMethod => $ventesForMethod) {
            $paymentAmount = $ventesForMethod->sum(fn ($vente) => $vente->price * $vente->quantity);

            $this->line("    Payment: {$paymentAmount} XOF via {$paymentMethod} ({$ventesForMethod->count()} ventes)");

            if ($this->isDryRun) {
                $paymentsCreated++;

                continue;
            }

            DB::table('payments')->insert([
                'sales_invoice_id' => $invoiceId,
                'amount' => $paymentAmount,
                'profit' => 0,
                'payment_method' => $paymentMethod,
                'comment' => 'Migré depuis ventes TYPE_SINGLE',
                'user_id' => null,
                'created_at' => $saleDate->copy()->startOfDay(),
                'updated_at' => now(),
            ]);

            $paymentsCreated++;
        }

        return $paymentsCreated;
    }
}
