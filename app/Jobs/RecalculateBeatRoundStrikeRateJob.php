<?php

namespace App\Jobs;

use App\Models\BeatRound;
use App\Services\BeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes and stores the strike rate for a single BeatRound.
 *
 * Delegates the calculation to BeatService::calculateStrikeRateForBeatRound(),
 * which is the single source of truth for strike rate computation.
 *
 * Dispatched from SalesInvoiceService after every sale or payment that triggers
 * a beat stop completion. Accepts a primitive round ID rather than the model
 * so the job is safe to serialize and decoupled from the request cycle.
 *
 * Failures are logged and swallowed so they never interrupt the sale or payment flow.
 */
class RecalculateBeatRoundStrikeRateJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $beatRoundId,
    ) {}

    public function handle(BeatService $beatService): void
    {
        try {
            $round = BeatRound::find($this->beatRoundId);

            if ($round === null) {
                return;
            }

            $computedStrikeRate = $beatService->calculateStrikeRateForBeatRound($round);

            $round->update(['strike_rate' => $computedStrikeRate]);
        } catch (Throwable $throwable) {
            Log::error('RecalculateBeatRoundStrikeRateJob failed', [
                'beat_round_id' => $this->beatRoundId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
