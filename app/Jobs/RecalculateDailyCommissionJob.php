<?php

namespace App\Jobs;

use App\Services\Commission\DailyCommissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalculates the DailyCommission for the work day on which a Payment was made or deleted.
 *
 * Dispatched automatically from Payment::saved and Payment::deleted model events.
 * Accepts primitive data rather than the Payment model so the job remains safe to run
 * after a payment has been deleted (SerializesModels would re-fetch and throw otherwise).
 *
 * Failures are logged and swallowed so they never interrupt the payment flow.
 */
class RecalculateDailyCommissionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly string $workDay,
        public readonly ?int $salesInvoiceId,
    ) {}

    public function handle(DailyCommissionService $dailyCommissionService): void
    {
        try {
            $dailyCommissionService->recalculateDailyCommissionForPaymentData(
                userId: $this->userId,
                workDay: $this->workDay,
                salesInvoiceId: $this->salesInvoiceId,
            );
        } catch (Throwable $throwable) {
            Log::error('RecalculateDailyCommissionJob failed', [
                'user_id' => $this->userId,
                'work_day' => $this->workDay,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
