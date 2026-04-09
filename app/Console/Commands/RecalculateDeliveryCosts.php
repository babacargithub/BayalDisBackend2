<?php

namespace App\Console\Commands;

use App\Models\CarLoad;
use App\Models\SalesInvoice;
use App\Services\InvoiceDeliveryCostService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recomputes the delivery_cost for all historical sales invoices linked to a car load.
 *
 * Iterates over every unique (car_load_id, work_day) pair found in sales_invoices
 * and calls InvoiceDeliveryCostService::recalculateDeliveryCostForCarLoadDay() for each.
 *
 * Safe to run multiple times — the service is idempotent.
 *
 * Usage:
 *   php artisan bayal:recalculate-delivery-costs                       # all car loads, all dates
 *   php artisan bayal:recalculate-delivery-costs --car-load=42         # single car load
 *   php artisan bayal:recalculate-delivery-costs --from=2026-01-01     # from date onward
 *   php artisan bayal:recalculate-delivery-costs --to=2026-03-31       # up to date
 *   php artisan bayal:recalculate-delivery-costs --from=2026-01-01 --to=2026-03-31
 */
class RecalculateDeliveryCosts extends Command
{
    protected $signature = 'bayal:recalculate-delivery-costs
                            {--car-load= : Only reprocess a specific car load ID}
                            {--from=     : Only reprocess invoices created on or after this date (YYYY-MM-DD)}
                            {--to=       : Only reprocess invoices created on or before this date (YYYY-MM-DD)}';

    protected $description = 'Recompute the delivery cost for all historical car-load invoices.';

    public function handle(InvoiceDeliveryCostService $deliveryCostService): int
    {
        $carLoadIdFilter = $this->option('car-load') !== null ? (int) $this->option('car-load') : null;
        $fromDate = $this->option('from');
        $toDate = $this->option('to');

        $query = SalesInvoice::query()
            ->whereNotNull('car_load_id')
            ->select([
                'car_load_id',
                DB::raw('DATE(created_at) AS work_day'),
            ])
            ->groupBy('car_load_id', DB::raw('DATE(created_at)'))
            ->orderBy('work_day');

        if ($carLoadIdFilter !== null) {
            $query->where('car_load_id', $carLoadIdFilter);
        }

        if ($fromDate !== null) {
            $query->whereDate('created_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        $pairs = $query->get();

        if ($pairs->isEmpty()) {
            $this->warn('No car-load invoices found matching the given filters. Nothing to recompute.');

            return Command::SUCCESS;
        }

        $totalPairs = $pairs->count();
        $this->info("Found {$totalPairs} (car_load × work_day) pair(s) to recompute.");

        $successCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalPairs);
        $progressBar->start();

        foreach ($pairs as $pair) {
            $carLoad = CarLoad::find($pair->car_load_id);

            if ($carLoad === null) {
                $this->newLine();
                $this->warn("  ⚠ CarLoad #{$pair->car_load_id} not found — skipping work_day {$pair->work_day}.");
                $errorCount++;
                $progressBar->advance();

                continue;
            }

            try {
                $deliveryCostService->recalculateDeliveryCostForCarLoadDay($carLoad, $pair->work_day);
                $successCount++;
            } catch (Throwable $exception) {
                $this->newLine();
                $this->error("  ✘ CarLoad #{$pair->car_load_id} / {$pair->work_day} — {$exception->getMessage()}");
                $errorCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info("Done — {$successCount} pair(s) recomputed, {$errorCount} error(s).");

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
