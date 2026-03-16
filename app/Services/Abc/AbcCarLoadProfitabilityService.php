<?php

namespace App\Services\Abc;

use App\Data\Abc\CarLoadProfitabilityDTO;
use App\Models\CarLoad;
use App\Models\SalesInvoice;

/**
 * Assembles the full ABC profitability picture for a single CarLoad.
 *
 * Profitability layers:
 *   1. Gross profit = SUM(sales_invoices.total_estimated_profit) — product margin only
 *   2. Vehicle costs = fixed daily rate × trip days + actual fuel receipts
 *   3. Fixed burdens = storage + overhead allocations (equal per vehicle per month)
 *   4. Net profit = gross profit − vehicle costs − fixed burdens
 */
readonly class AbcCarLoadProfitabilityService
{
    public function __construct(
        private AbcVehicleCostService           $abcVehicleCostService,
        private AbcFixedCostDistributionService $abcFixedCostDistributionService,
    ) {}

    /**
     * Compute the full profitability for a given CarLoad.
     *
     * All financial totals use the stored columns on sales_invoices
     * (total_amount, total_estimated_profit) for consistency with the rest of the app.
     */
    public function computeProfitability(CarLoad $carLoad): CarLoadProfitabilityDTO
    {
        $totalRevenue = $this->computeTotalRevenueForCarLoad($carLoad);
        $totalGrossProfit = $this->computeTotalGrossProfitForCarLoad($carLoad);

        $vehicleFixedCost = $this->abcVehicleCostService->computeFixedCostForCarLoad($carLoad);
        $vehicleFuelCost = $this->abcVehicleCostService->computeFuelCostForCarLoad($carLoad);
        // TODO find a way to distribue vehicle fuel cost

        $fixedCostAllocation = $this->abcFixedCostDistributionService->computeAllocatedFixedCostsForCarLoad($carLoad);

        $isMonthFinalized = $carLoad->load_date !== null
            && $this->abcFixedCostDistributionService->isMonthFinalized(
                year: $carLoad->load_date->year,
                month: $carLoad->load_date->month,
            );

        return new CarLoadProfitabilityDTO(
            totalRevenue: $totalRevenue,
            totalGrossProfit: $totalGrossProfit,
            vehicleFixedCost: $vehicleFixedCost,
            vehicleFuelCost: $vehicleFuelCost,
            storageAllocation: $fixedCostAllocation->storageAllocation,
            overheadAllocation: $fixedCostAllocation->overheadAllocation,
            isMonthFinalized: $isMonthFinalized,
        );
    }

    /**
     * Total revenue from all sales invoices linked to this CarLoad.
     * Uses stored total_amount column for performance.
     */
    private function computeTotalRevenueForCarLoad(CarLoad $carLoad): int
    {
        return (int) SalesInvoice::query()
            ->where('car_load_id', $carLoad->id)
            ->sum('total_amount');
    }

    /**
     * Total gross profit from all sales invoices linked to this CarLoad.
     * Uses stored total_estimated_profit column (COGS-based, before overhead).
     */
    private function computeTotalGrossProfitForCarLoad(CarLoad $carLoad): int
    {
        return (int) SalesInvoice::query()
            ->where('car_load_id', $carLoad->id)
            ->sum('total_estimated_profit');
    }
}
