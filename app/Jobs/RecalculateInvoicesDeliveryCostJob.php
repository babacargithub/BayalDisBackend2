<?php

namespace App\Jobs;

use App\Models\CarLoad;
use App\Services\InvoiceDeliveryCostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes and redistributes the delivery_cost for all invoices belonging
 * to a given car load on a given calendar day.
 *
 * Dispatched by SalesInvoice::saved and SalesInvoice::deleted model events
 * whenever a car-load invoice (car_load_id not null) is persisted or removed.
 *
 * Accepts primitives (carLoadId, workDay) rather than a model so the job
 * remains safe to deserialize even if the CarLoad is later deleted.
 *
 * Failures are logged and swallowed so the invoice save flow is never interrupted.
 */
class RecalculateInvoicesDeliveryCostJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $carLoadId,
        public readonly string $workDay,
    ) {}

    public function handle(InvoiceDeliveryCostService $deliveryCostService): void
    {
        try {
            $carLoad = CarLoad::find($this->carLoadId);

            if ($carLoad === null) {
                return;
            }

            $deliveryCostService->recalculateDeliveryCostForCarLoadDay($carLoad, $this->workDay);
        } catch (Throwable $throwable) {
            Log::error('RecalculateInvoicesDeliveryCostJob failed', [
                'car_load_id' => $this->carLoadId,
                'work_day' => $this->workDay,
                'error' => $throwable->getMessage(),
            ]);
        }
    }
}
