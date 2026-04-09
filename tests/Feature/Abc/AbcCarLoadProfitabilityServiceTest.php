<?php

namespace Tests\Feature\Abc;

use App\Enums\CarLoadExpenseType;
use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Models\CarLoad;
use App\Models\CarLoadExpense;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\MonthlyFixedCost;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Abc\CarLoadCostAggregatorService;
use App\Services\Abc\FixedCostCalculationAndDistributionService;
use App\Services\Abc\VehicleCostCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbcCarLoadProfitabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadCostAggregatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CarLoadCostAggregatorService(
            new VehicleCostCalculatorService,
            new FixedCostCalculationAndDistributionService,
        );
    }

    private function makeVehicle(): Vehicle
    {
        return Vehicle::create([
            'name' => 'Camion Alpha',
            'insurance_monthly' => 34_000,
            'maintenance_monthly' => 20_000,
            'repair_reserve_monthly' => 15_000,
            'depreciation_monthly' => 34_000,
            'driver_salary_monthly' => 55_000,
            'working_days_per_month' => 26,
        ]);
    }

    private function makeCarLoad(Vehicle $vehicle, Carbon $loadDate, ?Carbon $returnDate = null): CarLoad
    {
        $team = Team::create(['name' => 'Team'.rand(1, 999), 'user_id' => User::factory()->create()->id]);

        return CarLoad::create([
            'name' => 'Test CarLoad',
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'load_date' => $loadDate,
            'return_date' => $returnDate,
            'status' => 'SELLING',
        ]);
    }

    private function makeCustomer(): Customer
    {
        $commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
        ]);

        return Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    private function addSalesInvoiceToCarLoad(CarLoad $carLoad, int $totalAmount, int $totalEstimatedProfit): SalesInvoice
    {
        $customer = $this->makeCustomer();

        $invoice = SalesInvoice::create([
            'car_load_id' => $carLoad->id,
            'customer_id' => $customer->id,
            'status' => 'DRAFT',
        ]);

        // total_amount and total_estimated_profit are not in $fillable (managed by recalculateStoredTotals).
        // Set them directly for test setup to simulate finalized invoice totals.
        $invoice->total_amount = $totalAmount;
        $invoice->total_estimated_profit = $totalEstimatedProfit;
        $invoice->saveQuietly();

        return $invoice;
    }

    public function test_net_profit_is_gross_profit_minus_all_costs(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 1));

        $this->addSalesInvoiceToCarLoad($carLoad, totalAmount: 3_200_000, totalEstimatedProfit: 896_000);

        CarLoadExpense::create(['car_load_id' => $carLoad->id, 'amount' => 34_000, 'type' => CarLoadExpenseType::Fuel]);

        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 172_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 172_000,
            'active_vehicle_count' => 1,
            'finalized_at' => now(),
        ]);
        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Overhead,
            'sub_category' => MonthlyFixedCostSubCategory::ManagerSalary,
            'amount' => 225_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 225_000,
            'active_vehicle_count' => 1,
            'finalized_at' => now(),
        ]);

        $profitability = $this->service->computeProfitability($carLoad);

        // daily_fixed = (34000+20000+15000+34000+55000)/26 = 158000/26 = 6077
        $expectedDailyFixed = (int) round(158_000 / 26);
        $expectedVehicleCost = $expectedDailyFixed * 1 + 34_000;

        $this->assertEquals(3_200_000, $profitability->totalRevenue);
        $this->assertEquals(896_000, $profitability->totalGrossProfit);
        $this->assertEquals($expectedDailyFixed, $profitability->vehicleFixedCost);
        $this->assertEquals(34_000, $profitability->vehicleExpensesCost);
        $this->assertEquals(172_000, $profitability->storageAllocation);
        $this->assertEquals(225_000, $profitability->overheadAllocation);

        $expectedNetProfit = 896_000 - $expectedVehicleCost - 172_000 - 225_000;
        $this->assertEquals($expectedNetProfit, $profitability->netProfit());
    }

    public function test_is_deficit_returns_true_when_gross_profit_less_than_total_costs(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 1));

        // Very low revenue — not enough to cover fixed costs
        $this->addSalesInvoiceToCarLoad($carLoad, totalAmount: 100_000, totalEstimatedProfit: 20_000);

        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 500_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 500_000,
            'active_vehicle_count' => 1,
            'finalized_at' => now(),
        ]);

        $profitability = $this->service->computeProfitability($carLoad);

        $this->assertTrue($profitability->isDeficit());
        $this->assertLessThan(0, $profitability->netProfit());
    }

    public function test_break_even_revenue_is_correctly_computed(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 1));

        // gross margin rate = 896,000 / 3,200,000 = 28%
        $this->addSalesInvoiceToCarLoad($carLoad, totalAmount: 3_200_000, totalEstimatedProfit: 896_000);

        $fixedCostBurden = (int) round(158_000 / 26) + 172_000 + 225_000;

        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 172_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 172_000,
            'active_vehicle_count' => 1,
            'finalized_at' => now(),
        ]);
        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Overhead,
            'sub_category' => MonthlyFixedCostSubCategory::ManagerSalary,
            'amount' => 225_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 225_000,
            'active_vehicle_count' => 1,
            'finalized_at' => now(),
        ]);

        $profitability = $this->service->computeProfitability($carLoad);

        $grossMarginRate = 896_000 / 3_200_000;
        $expectedBreakEven = (int) ceil($profitability->totalFixedCostBurden() / $grossMarginRate);

        $this->assertEquals($expectedBreakEven, $profitability->breakEvenRevenue());
    }

    public function test_remaining_revenue_to_break_even_is_zero_when_already_profitable(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, Carbon::create(2026, 3, 1), Carbon::create(2026, 3, 1));

        $this->addSalesInvoiceToCarLoad($carLoad, totalAmount: 5_000_000, totalEstimatedProfit: 2_000_000);

        $profitability = $this->service->computeProfitability($carLoad);

        $this->assertEquals(0, $profitability->remainingRevenueToBreakEven());
        $this->assertFalse($profitability->isDeficit());
    }

    public function test_profitability_with_no_sales_invoices_returns_zero_revenue_and_profit(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, Carbon::create(2026, 3, 1));

        $profitability = $this->service->computeProfitability($carLoad);

        $this->assertEquals(0, $profitability->totalRevenue);
        $this->assertEquals(0, $profitability->totalGrossProfit);
        $this->assertEquals(0, $profitability->grossMarginPercent());
        $this->assertEquals(0, $profitability->breakEvenRevenue());
    }
}
