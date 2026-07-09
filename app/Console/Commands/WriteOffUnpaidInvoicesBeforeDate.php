<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Services\SalesInvoiceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-off cutover command: marks every currently-unpaid invoice created before
 * a given date as definitively written off (lost debt).
 *
 * This replaces the previous ad-hoc approach of hardcoding a cutoff date
 * (AdminController::UNPAID_INVOICES_START_DATE) inside every debt/AR query.
 * Once run, written-off invoices are excluded from all aggregations via the
 * SalesInvoice global scope — no query-site date filter is needed anymore.
 *
 * Only invoices with zero payments are eligible (SalesInvoiceService::writeOffInvoice()
 * refuses invoices carrying any payment). Partially-paid pre-cutoff invoices are
 * left untouched and will re-enter debt totals — they represent debt that was
 * genuinely partially recovered and must be resolved through a different path.
 *
 * Usage:
 *   php artisan bayal:write-off-unpaid-invoices-before-date 2026-03-29 --dry-run
 *   php artisan bayal:write-off-unpaid-invoices-before-date 2026-03-29
 */
class WriteOffUnpaidInvoicesBeforeDate extends Command
{
    protected $signature = 'bayal:write-off-unpaid-invoices-before-date
                            {date : Write off unpaid invoices created strictly before this date (YYYY-MM-DD)}
                            {--dry-run : Only report what would be written off, without making changes}';

    protected $description = 'Mark unpaid invoices created before a given date as definitively lost (written off).';

    public function handle(SalesInvoiceService $salesInvoiceService): int
    {
        $cutoffDate = $this->argument('date');
        $isDryRun = (bool) $this->option('dry-run');

        $eligibleInvoices = SalesInvoice::query()
            ->whereDate('created_at', '<', $cutoffDate)
            ->where('total_payments', 0)
            ->get();

        if ($eligibleInvoices->isEmpty()) {
            $this->info("No unpaid invoices found before {$cutoffDate}. Nothing to write off.");

            return Command::SUCCESS;
        }

        $totalAmount = $eligibleInvoices->sum('total_amount');
        $this->info(
            "Found {$eligibleInvoices->count()} unpaid invoice(s) before {$cutoffDate} ".
            "totalling {$totalAmount} F."
        );

        if ($isDryRun) {
            $this->warn('Dry run — no changes made.');

            return Command::SUCCESS;
        }

        $successCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($eligibleInvoices->count());
        $progressBar->start();

        foreach ($eligibleInvoices as $invoice) {
            try {
                $salesInvoiceService->writeOffInvoice($invoice);
                $successCount++;
            } catch (Throwable $exception) {
                $this->newLine();
                $this->error("  ✘ Invoice #{$invoice->id} — {$exception->getMessage()}");
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info("Done — {$successCount} invoice(s) written off, {$errorCount} error(s).");

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
