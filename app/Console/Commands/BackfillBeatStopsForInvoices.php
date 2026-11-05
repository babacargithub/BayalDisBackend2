<?php

namespace App\Console\Commands;

use App\Models\BeatRound;
use App\Models\SalesInvoice;
use App\Services\BeatService;
use Illuminate\Console\Command;

/**
 * Backfills beat stop completion and round strike rates for invoices created back when
 * SalesInvoiceService::createSalesInvoice() silently skipped the "complete beat stop +
 * recalculate strike rate" step.
 *
 * The step is replayed for EVERY invoice in the date range, whatever its current payment
 * state. An invoice unpaid when taken in round A but paid later in round B must still
 * complete round A's stop, so the invoice's current payments must not be used as a filter.
 * Replaying is done on the invoice creation date, then the strike rate of each affected
 * round is recomputed once.
 *
 * Safe to run multiple times — only stops still PLANNED are transitioned, and the strike
 * rate is a pure recomputation.
 *
 * Usage:
 *   php artisan bayal:backfill-beat-stops-for-invoices --dry-run
 *   php artisan bayal:backfill-beat-stops-for-invoices --from=2026-01-01 --to=2026-06-30
 */
class BackfillBeatStopsForInvoices extends Command
{
    protected $signature = 'bayal:backfill-beat-stops-for-invoices
                            {--from= : Only invoices created on or after this date (YYYY-MM-DD)}
                            {--to=   : Only invoices created on or before this date (YYYY-MM-DD)}
                            {--dry-run : Only report how many invoices would be processed}';

    protected $description = 'Complete beat stops and recompute round strike rates for invoices created before the creation hook was fixed.';

    public function handle(BeatService $beatService): int
    {
        $invoicesToReplayQuery = SalesInvoice::query()
            ->whereNotNull('customer_id')
            ->when($this->option('from'), fn ($query, $fromDate) => $query->whereDate('created_at', '>=', $fromDate))
            ->when($this->option('to'), fn ($query, $toDate) => $query->whereDate('created_at', '<=', $toDate))
            ->orderBy('created_at');

        $candidateInvoicesCount = (clone $invoicesToReplayQuery)->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$candidateInvoicesCount} invoice(s) would be processed.");

            return self::SUCCESS;
        }

        $affectedBeatRoundIds = [];

        $invoicesToReplayQuery->each(function (SalesInvoice $invoice) use ($beatService, &$affectedBeatRoundIds): void {
            $roundIdsForInvoice = $beatService->completeRoundStopForCustomerOnDate(
                customerId: $invoice->customer_id,
                date: $invoice->created_at->toDateString(),
            );

            foreach ($roundIdsForInvoice as $roundId) {
                $affectedBeatRoundIds[$roundId] = true;
            }
        });

        $recalculatedRoundsCount = 0;
        foreach (array_keys($affectedBeatRoundIds) as $beatRoundId) {
            $beatRound = BeatRound::find($beatRoundId);

            if ($beatRound === null) {
                continue;
            }

            $beatRound->update(['strike_rate' => $beatService->calculateStrikeRateForBeatRound($beatRound)]);
            $recalculatedRoundsCount++;
        }

        $this->info("Processed {$candidateInvoicesCount} invoice(s); recalculated strike rate of {$recalculatedRoundsCount} round(s).");

        return self::SUCCESS;
    }
}
