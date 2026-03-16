<?php

namespace Tests\Feature\Abc;

use App\Models\MonthlyFixedCost;
use App\Models\SalesInvoice;
use App\Models\Vehicle;
use App\Services\Abc\AbcCostSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Metadata\Group;
use Tests\TestCase;

/**
 * Tests for A abcCostSummaryService::computeForPeriod().
 *
 * Every number shown on the "Coûts d'exploitation" page comes from this service.
 * A wrong daily cost leads to an under-rated daily sales goal, which means losses.
 *
 * Coverage:
 *  - Fixed costs filtered correctly by year+month
 *  - Commercial salaries aggregated correctly
 *  - Vehicle costs and daily vehicle costs aggregated correctly
 *  - Average working days computed (average of vehicles, fallback 26 when no vehicles)
 *  - Daily fixed costs and salaries prorated by average working days
 *  - Daily total = sum of all three daily costs
 *  - Grand total = sum of all three monthly totals
 *  - Break-even: margin rate derived from SalesInvoice aggregates
 *  - Break-even: daily sales required = round(dailyTotal / marginRate)
 *  - Edge cases: no vehicles, no invoices, zero revenue, zero margin
 *  - Rounding correctness
 */

class AbcCostSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private AbcCostSummaryService $service;

    private int $commercialPhoneCounter = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AbcCostSummaryService();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeInvoiceWithTotals(int $totalAmount, int $totalEstimatedProfit): void
    {
        // Create a minimal commercial → customer → invoice chain to satisfy FK constraints.
        // user_id is nullable so no User is needed.
        $commercialId = DB::table('commercials')->insertGetId([
            'name' => 'Invoice Helper Commercial ' . $this->commercialPhoneCounter,
            'phone_number' => '33600' . str_pad($this->commercialPhoneCounter++, 5, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Invoice Helper Customer',
            'phone_number' => '33700' . str_pad($commercialId, 5, '0', STR_PAD_LEFT),
            'owner_number' => '0000000000',
            'gps_coordinates' => '0,0',
            'commercial_id' => $commercialId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create invoice via model to respect defaults, then force-set the stored total columns.
        $invoice = SalesInvoice::create([
            'customer_id' => $customerId,
            'status' => 'DRAFT',
        ]);

        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'total_amount' => $totalAmount,
            'total_estimated_profit' => $totalEstimatedProfit,
        ]);
    }

    private function makeCommercial(int $salary): void
    {
        DB::table('commercials')->insert([
            'name' => 'Commercial ' . $this->commercialPhoneCounter,
            'phone_number' => '22170000' . str_pad($this->commercialPhoneCounter++, 4, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'salary' => $salary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeVehicleWithMonthlyCost(int $totalMonthlyFixedCost, int $workingDaysPerMonth): Vehicle
    {
        // Spread the total evenly across 5 cost fields for a clean setup.
        $perField = (int) ($totalMonthlyFixedCost / 5);
        $remainder = $totalMonthlyFixedCost - ($perField * 5);

        return Vehicle::factory()->create([
            'insurance_monthly' => $perField + $remainder,
            'maintenance_monthly' => $perField,
            'repair_reserve_monthly' => $perField,
            'depreciation_monthly' => $perField,
            'driver_salary_monthly' => $perField,
            'working_days_per_month' => $workingDaysPerMonth,
        ]);
    }

    // ─── Fixed costs ──────────────────────────────────────────────────────────

    public function test_fixed_costs_total_sums_amounts_for_the_requested_period(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 50_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(150_000, $summary->fixedCostsTotal);
    }

    public function test_fixed_costs_from_a_different_month_are_excluded(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 4, 'amount' => 999_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(100_000, $summary->fixedCostsTotal);
    }

    public function test_fixed_costs_from_a_different_year_are_excluded(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);
        MonthlyFixedCost::factory()->create(['period_year' => 2025, 'period_month' => 3, 'amount' => 999_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(100_000, $summary->fixedCostsTotal);
    }

    public function test_fixed_costs_total_is_zero_when_no_costs_exist_for_period(): void
    {
        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(0, $summary->fixedCostsTotal);
    }

    // ─── Commercial salaries ──────────────────────────────────────────────────

    public function test_commercial_salaries_total_sums_all_commercial_salaries(): void
    {
        $this->makeCommercial(200_000);
        $this->makeCommercial(150_000);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(350_000, $summary->commercialSalariesTotal);
    }

    public function test_commercial_salaries_total_is_zero_when_no_commercials_exist(): void
    {
        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(0, $summary->commercialSalariesTotal);
    }

    // ─── Vehicle aggregates ───────────────────────────────────────────────────

    public function test_vehicle_costs_total_sums_total_monthly_fixed_cost_of_all_vehicles(): void
    {
        $this->makeVehicleWithMonthlyCost(300_000, 26);
        $this->makeVehicleWithMonthlyCost(200_000, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(500_000, $summary->vehicleCostsTotal);
    }

    public function test_vehicle_costs_total_is_zero_when_no_vehicles_exist(): void
    {
        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(0, $summary->vehicleCostsTotal);
    }

    public function test_daily_vehicle_costs_sums_each_vehicle_daily_fixed_cost(): void
    {
        // Monthly = 260 000, 26 working days → daily = 10 000
        $this->makeVehicleWithMonthlyCost(260_000, 26);
        // Monthly = 200 000, 20 working days → daily = 10 000
        $this->makeVehicleWithMonthlyCost(200_000, 20);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(20_000, $summary->dailyBreakdown->dailyVehicleCosts);
    }

    public function test_each_vehicle_uses_its_own_working_days_for_its_daily_cost(): void
    {
        // Both vehicles have monthly = 260 000 but different working days
        // Vehicle A: 260 000 / 26 = 10 000/day
        $this->makeVehicleWithMonthlyCost(260_000, 26);
        // Vehicle B: 260 000 / 13 = 20 000/day
        $this->makeVehicleWithMonthlyCost(260_000, 13);

        $summary = $this->service->computeForPeriod(2026, 3);

        // 10 000 + 20 000 = 30 000
        $this->assertSame(30_000, $summary->dailyBreakdown->dailyVehicleCosts);
    }

    // ─── Average working days ──────────────────────────────────────────────────

    public function test_average_working_days_is_the_mean_of_all_vehicle_working_days(): void
    {
        $this->makeVehicleWithMonthlyCost(100, 20);
        $this->makeVehicleWithMonthlyCost(100, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        // (20 + 26) / 2 = 23
        $this->assertSame(23, $summary->dailyBreakdown->averageWorkingDaysPerMonth);
    }

    public function test_average_working_days_falls_back_to_26_when_no_vehicles_exist(): void
    {
        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(26, $summary->dailyBreakdown->averageWorkingDaysPerMonth);
    }

    // ─── Daily fixed costs and salaries ───────────────────────────────────────

    public function test_daily_fixed_costs_is_monthly_fixed_costs_divided_by_average_working_days(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 520_000]);
        $this->makeVehicleWithMonthlyCost(100, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        // 520 000 / 26 = 20 000
        $this->assertSame(20_000, $summary->dailyBreakdown->dailyFixedCosts);
    }

    public function test_daily_commercial_salaries_is_monthly_salaries_divided_by_average_working_days(): void
    {
        $this->makeCommercial(520_000);
        $this->makeVehicleWithMonthlyCost(100, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        // 520 000 / 26 = 20 000
        $this->assertSame(20_000, $summary->dailyBreakdown->dailyCommercialSalaries);
    }

    public function test_daily_fixed_costs_uses_fallback_working_days_when_no_vehicles_exist(): void
    {
        // Fallback = 26 → 520 000 / 26 = 20 000
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 520_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(20_000, $summary->dailyBreakdown->dailyFixedCosts);
    }

    public function test_daily_fixed_costs_rounds_correctly_on_non_divisible_amounts(): void
    {
        // 100 000 / 26 = 3846.15... → rounds to 3846
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(3_846, $summary->dailyBreakdown->dailyFixedCosts);
    }

    // ─── Daily total and grand total ──────────────────────────────────────────

    public function test_daily_total_overall_cost_is_the_sum_of_all_three_daily_costs(): void
    {
        // Fixed: 260 000 / 26 = 10 000/day
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 260_000]);
        // Salary: 260 000 / 26 = 10 000/day
        $this->makeCommercial(260_000);
        // Vehicle: 260 000 / 26 = 10 000/day
        $this->makeVehicleWithMonthlyCost(260_000, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(10_000, $summary->dailyBreakdown->dailyFixedCosts);
        $this->assertSame(10_000, $summary->dailyBreakdown->dailyCommercialSalaries);
        $this->assertSame(10_000, $summary->dailyBreakdown->dailyVehicleCosts);
        $this->assertSame(30_000, $summary->dailyBreakdown->dailyTotalOverallCost());
    }

    public function test_grand_total_is_the_sum_of_all_three_monthly_totals(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);
        $this->makeCommercial(200_000);
        $this->makeVehicleWithMonthlyCost(60_000, 26);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(100_000, $summary->fixedCostsTotal);
        $this->assertSame(200_000, $summary->commercialSalariesTotal);
        $this->assertSame(60_000, $summary->vehicleCostsTotal);
        $this->assertSame(360_000, $summary->grandTotal());
    }

    // ─── Break-even ───────────────────────────────────────────────────────────

    public function test_break_even_margin_rate_is_total_profit_divided_by_total_revenue(): void
    {
        $this->makeInvoiceWithTotals(1_000_000, 200_000);
        $this->makeInvoiceWithTotals(500_000, 100_000);

        $summary = $this->service->computeForPeriod(2026, 3);

        // (200 000 + 100 000) / (1 000 000 + 500 000) = 0.2000
        $this->assertEqualsWithDelta(0.2, $summary->breakEven->averageGrossMarginRate, 0.0001);
    }

    public function test_daily_sales_required_equals_daily_cost_divided_by_margin(): void
    {
        // Daily cost: 260 000 / 26 = 10 000 (no vehicles, no salaries)
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 260_000]);
        // Margin: 20%
        $this->makeInvoiceWithTotals(1_000_000, 200_000);

        $summary = $this->service->computeForPeriod(2026, 3);

        // 10 000 / 0.2 = 50 000
        $this->assertSame(50_000, $summary->breakEven->dailySalesRequiredToCoverCosts);
    }

    public function test_daily_sales_required_is_null_when_no_invoices_exist(): void
    {
        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertNull($summary->breakEven->dailySalesRequiredToCoverCosts);
        $this->assertSame(0.0, $summary->breakEven->averageGrossMarginRate);
    }

    public function test_daily_sales_required_is_null_when_total_invoiced_revenue_is_zero(): void
    {
        $this->makeInvoiceWithTotals(0, 0);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertNull($summary->breakEven->dailySalesRequiredToCoverCosts);
        $this->assertSame(0.0, $summary->breakEven->averageGrossMarginRate);
    }

    public function test_daily_sales_required_is_null_when_margin_rate_is_zero(): void
    {
        // Revenue exists but no profit
        $this->makeInvoiceWithTotals(1_000_000, 0);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(0.0, $summary->breakEven->averageGrossMarginRate);
        $this->assertNull($summary->breakEven->dailySalesRequiredToCoverCosts);
    }

    public function test_break_even_stores_raw_invoice_totals_for_context(): void
    {
        $this->makeInvoiceWithTotals(800_000, 160_000);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(800_000, $summary->breakEven->totalInvoicedRevenue);
        $this->assertSame(160_000, $summary->breakEven->totalEstimatedProfit);
    }

    // ─── Period metadata ──────────────────────────────────────────────────────

    public function test_dto_carries_the_requested_period(): void
    {
        $summary = $this->service->computeForPeriod(2025, 11);

        $this->assertSame(2025, $summary->periodYear);
        $this->assertSame(11, $summary->periodMonth);
    }

    // ─── toArray serialisation ────────────────────────────────────────────────

    public function test_to_array_contains_all_expected_keys_for_frontend(): void
    {
        $array = $this->service->computeForPeriod(2026, 3)->toArray();

        $this->assertArrayHasKey('period_year', $array);
        $this->assertArrayHasKey('period_month', $array);
        $this->assertArrayHasKey('fixed_costs_total', $array);
        $this->assertArrayHasKey('commercial_salaries_total', $array);
        $this->assertArrayHasKey('vehicle_costs_total', $array);
        $this->assertArrayHasKey('grand_total', $array);

        $this->assertArrayHasKey('daily_breakdown', $array);
        $this->assertArrayHasKey('daily_fixed_costs', $array['daily_breakdown']);
        $this->assertArrayHasKey('daily_commercial_salaries', $array['daily_breakdown']);
        $this->assertArrayHasKey('daily_vehicle_costs', $array['daily_breakdown']);
        $this->assertArrayHasKey('daily_total_overall_cost', $array['daily_breakdown']);
        $this->assertArrayHasKey('average_working_days_per_month', $array['daily_breakdown']);

        $this->assertArrayHasKey('break_even', $array);
        $this->assertArrayHasKey('average_gross_margin_rate', $array['break_even']);
        $this->assertArrayHasKey('daily_sales_required_to_cover_costs', $array['break_even']);
        $this->assertArrayHasKey('total_invoiced_revenue', $array['break_even']);
        $this->assertArrayHasKey('total_estimated_profit', $array['break_even']);
    }

    public function test_to_array_grand_total_matches_grandTotal_method(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 100_000]);
        $this->makeCommercial(200_000);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame($summary->grandTotal(), $summary->toArray()['grand_total']);
    }

    public function test_to_array_daily_total_matches_dailyTotalOverallCost_method(): void
    {
        MonthlyFixedCost::factory()->create(['period_year' => 2026, 'period_month' => 3, 'amount' => 260_000]);

        $summary = $this->service->computeForPeriod(2026, 3);

        $this->assertSame(
            $summary->dailyBreakdown->dailyTotalOverallCost(),
            $summary->toArray()['daily_breakdown']['daily_total_overall_cost'],
        );
    }
}
