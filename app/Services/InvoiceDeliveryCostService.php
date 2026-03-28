<?php

namespace App\Services;

use App\Models\CarLoad;
use App\Models\SalesInvoice;
use App\Services\Abc\AbcVehicleCostService;
use Illuminate\Support\Facades\DB;

/**
 * Distributes the car load's daily running cost equally across all invoices
 * created on the same calendar day for that car load.
 *
 * Distribution formula (integer, no sub-units):
 *  - base = floor(daily_cost / number_of_invoices)
 *  - The remainder (daily_cost % number_of_invoices) is added 1 unit at a time
 *    to the first invoices (ordered by id) to guarantee:
 *      SUM(delivery_cost of all invoices for the day) == daily_cost
 *
 * Updates are applied directly via DB::table() to bypass model events and
 * prevent recursive dispatch of RecalculateInvoicesDeliveryCostJob.
 */
readonly class InvoiceDeliveryCostService
{
    public function __construct(
        private AbcVehicleCostService $vehicleCostService,
    ) {}

    /**
     * Recompute and persist the delivery_cost for every invoice belonging to
     * the given car load that was created on the given calendar date.
     *
     * Safe to call multiple times for the same (car_load, work_day) pair —
     * the result is always consistent (idempotent).
     */
    public function recalculateDeliveryCostForCarLoadDay(
        CarLoad $carLoad,
        string $workDay,
    ): void {
        $dailyCost = $this->vehicleCostService->computeDailyFixedAndVariableVehicleCostForCarLoad($carLoad);

        $invoiceIds = SalesInvoice::where('car_load_id', $carLoad->id)
            ->whereDate('created_at', $workDay)
            ->orderBy('id')
            ->pluck('id');

        $numberOfInvoices = $invoiceIds->count();

        if ($numberOfInvoices === 0) {
            return;
        }

        $baseDeliveryCostPerInvoice = intdiv($dailyCost, $numberOfInvoices);
        $remainder = $dailyCost % $numberOfInvoices;

        $invoiceIds->each(function (int $invoiceId, int $index) use ($baseDeliveryCostPerInvoice, $remainder): void {
            // Distribute the remainder 1 unit at a time to the first $remainder invoices
            // so the sum of all delivery costs equals the daily cost exactly.
            $deliveryCost = $baseDeliveryCostPerInvoice + ($index < $remainder ? 1 : 0);

            /** @noinspection UnknownTableOrViewInspection */
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update(['delivery_cost' => $deliveryCost]);
        });
    }
}
