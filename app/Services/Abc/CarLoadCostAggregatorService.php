<?php

namespace App\Services\Abc;

use App\Data\Abc\CarLoadProfitabilityDTO;
use App\Models\CarLoad;
use App\Models\SalesInvoice;

/**
 * Single source of truth for car load cost and profitability calculations.
 *
 * Cost layers:
 *   1. Vehicle costs = fixed daily rate × trip days + actual variable expenses (fuel, parking, fines, etc.)
 *   2. Fixed burdens = storage + overhead allocations (equal per vehicle per month)
 *   3. Total daily cost = (vehicle costs + fixed burdens) / trip duration in days
 *
 * Profitability layers:
 *   1. Gross profit = SUM(sales_invoices.total_estimated_profit) — product margin only
 *   2. Net profit = gross profit − vehicle costs − fixed burdens
 */
readonly class CarLoadCostAggregatorService
{
    public function __construct(
        private VehicleCostCalculatorService               $abcVehicleCostService,
        private FixedCostCalculationAndDistributionService $abcFixedCostDistributionService,
    ) {}

    /**
     * Total cost allocated to a CarLoad for the entire trip duration:
     * vehicle running costs (fixed prorated + variable expenses) plus
     * the monthly fixed cost allocations (storage + overhead).
     */
    public function computeTotalOverallCostForCarLoad(CarLoad $carLoad): int
    {
        $totalVehicleCost = $this->abcVehicleCostService->computeTotalVehiclePredeterminedAndVariableCostForCarLoad($carLoad);
        $fixedCostAllocation = $this->abcFixedCostDistributionService->computeProratedFixedCostsForCarLoad($carLoad);
        $totalFixedAllocation = $fixedCostAllocation->storageAllocation + $fixedCostAllocation->overheadAllocation;

        return $totalVehicleCost + $totalFixedAllocation;
    }

    /**
     * Daily cost for a CarLoad: total overall cost divided by trip duration.
     * This is the single source of truth for the cost to distribute across
     * invoices on any given work day (used by InvoiceDeliveryCostService).
     */
    public function computeTotalDailyCostForCarLoad(CarLoad $carLoad): int
    {
        $tripDurationDays = $carLoad->vehicle?->working_days_per_month ?? 1;

        return (int) round($this->computeTotalOverallCostForCarLoad($carLoad) / $tripDurationDays);
    }

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

        $vehicleFixedCost = $this->abcVehicleCostService->computeAlreadyElapsedVehicleCostForCarLoad($carLoad);
        $vehicleExpensesCost = $this->abcVehicleCostService->computeVariableExpensesForCarLoad($carLoad);

        $fixedCostAllocation = $this->abcFixedCostDistributionService->computeProratedFixedCostsForCarLoad($carLoad);

        $isMonthFinalized = $carLoad->load_date !== null
            && $this->abcFixedCostDistributionService->isMonthFinalized(
                year: $carLoad->load_date->year,
                month: $carLoad->load_date->month,
            );

        return new CarLoadProfitabilityDTO(
            totalRevenue: $totalRevenue,
            totalGrossProfit: $totalGrossProfit,
            vehicleFixedCost: $vehicleFixedCost,
            vehicleExpensesCost: $vehicleExpensesCost,
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
