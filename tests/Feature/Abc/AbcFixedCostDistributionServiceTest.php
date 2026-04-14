<?php

namespace Tests\Feature\Abc;

use App\Enums\MonthlyFixedCostPool;
use App\Enums\MonthlyFixedCostSubCategory;
use App\Models\CarLoad;
use App\Models\MonthlyFixedCost;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Abc\FixedCostCalculationAndDistributionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbcFixedCostDistributionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FixedCostCalculationAndDistributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FixedCostCalculationAndDistributionService;
    }

    private function makeVehicle(string $name = 'Camion Alpha'): Vehicle
    {
        return Vehicle::create([
            'name' => $name,
            'insurance_monthly' => 50_000,
            'maintenance_monthly' => 30_000,
            'repair_reserve_monthly' => 10_000,
            'depreciation_monthly' => 20_000,
            'driver_salary_monthly' => 80_000,
            'working_days_per_month' => 26,
        ]);
    }

    private function makeCarLoadForVehicleInMonth(Vehicle $vehicle, Carbon $loadDate): CarLoad
    {
        $team = Team::create(['name' => 'Team '.rand(1, 9999), 'user_id' => User::factory()->create()->id]);

        return CarLoad::create([
            'name' => 'CarLoad '.$vehicle->name,
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'load_date' => $loadDate,
            'status' => 'SELLING',
        ]);
    }

    private function makeMonthlyFixedCost(
        MonthlyFixedCostPool $pool,
        MonthlyFixedCostSubCategory $subCategory,
        int $amount,
        int $year,
        int $month,
    ): MonthlyFixedCost {
        return MonthlyFixedCost::create([
            'cost_pool' => $pool,
            'sub_category' => $subCategory,
            'amount' => $amount,
            'period_year' => $year,
            'period_month' => $month,
        ]);
    }

    // =========================================================================
    // finalizeMonth tests
    // =========================================================================

    public function test_finalize_month_with_one_vehicle_sets_per_vehicle_amount_equal_to_total(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));
        $this->makeMonthlyFixedCost(MonthlyFixedCostPool::Storage, MonthlyFixedCostSubCategory::WarehouseRent, 172_000, 2026, 3);

        $this->service->finalizeMonth(2026, 3);

        $entry = MonthlyFixedCost::first();
        $this->assertEquals(172_000, $entry->per_vehicle_amount);
        $this->assertEquals(1, $entry->active_vehicle_count);
        $this->assertNotNull($entry->finalized_at);
    }

    public function test_finalize_month_with_two_vehicles_splits_cost_equally(): void
    {
        $vehicleAlpha = $this->makeVehicle('Camion Alpha');
        $vehicleBeta = $this->makeVehicle('Camion Beta');
        $this->makeCarLoadForVehicleInMonth($vehicleAlpha, Carbon::create(2026, 3, 1));
        $this->makeCarLoadForVehicleInMonth($vehicleBeta, Carbon::create(2026, 3, 5));

        $this->makeMonthlyFixedCost(MonthlyFixedCostPool::Storage, MonthlyFixedCostSubCategory::WarehouseRent, 100_000, 2026, 3);

        $this->service->finalizeMonth(2026, 3);

        $entry = MonthlyFixedCost::first();
        $this->assertEquals(50_000, $entry->per_vehicle_amount);
        $this->assertEquals(2, $entry->active_vehicle_count);
    }

    public function test_finalize_month_with_four_vehicles_splits_cost_equally(): void
    {
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $name) {
            $vehicle = $this->makeVehicle("Camion $name");
            $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));
        }
        $this->makeMonthlyFixedCost(MonthlyFixedCostPool::Storage, MonthlyFixedCostSubCategory::WarehouseRent, 100_000, 2026, 3);

        $this->service->finalizeMonth(2026, 3);

        $entry = MonthlyFixedCost::first();
        $this->assertEquals(25_000, $entry->per_vehicle_amount);
        $this->assertEquals(4, $entry->active_vehicle_count);
    }

    public function test_finalize_month_does_not_reprocess_already_finalized_entries(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));

        MonthlyFixedCost::create([
            'cost_pool' => MonthlyFixedCostPool::Storage,
            'sub_category' => MonthlyFixedCostSubCategory::WarehouseRent,
            'amount' => 100_000,
            'period_year' => 2026,
            'period_month' => 3,
            'per_vehicle_amount' => 99_999,
            'active_vehicle_count' => 1,
            'finalized_at' => now()->subDay(),
        ]);

        $this->service->finalizeMonth(2026, 3);

        $entry = MonthlyFixedCost::first();
        $this->assertEquals(99_999, $entry->per_vehicle_amount);
    }

    public function test_finalize_month_does_nothing_when_no_active_vehicles(): void
    {
        $this->makeMonthlyFixedCost(MonthlyFixedCostPool::Storage, MonthlyFixedCostSubCategory::WarehouseRent, 100_000, 2026, 3);

        $this->service->finalizeMonth(2026, 3);

        $entry = MonthlyFixedCost::first();
        $this->assertNull($entry->per_vehicle_amount);
        $this->assertNull($entry->finalized_at);
    }

    // =========================================================================
    // computeAllocatedFixedCostsForCarLoad tests
    // =========================================================================

    public function test_allocation_for_single_vehicle_single_carload_equals_full_per_vehicle_amount(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));

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

        $allocation = $this->service->computeProratedFixedCostsForCarLoad($carLoad);

        $this->assertEquals(172_000, $allocation->storageAllocation);
        $this->assertEquals(225_000, $allocation->overheadAllocation);
        $this->assertEquals(397_000, $allocation->total());
    }

    public function test_allocation_gives_full_per_vehicle_amount_to_each_carload_regardless_of_carload_count(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad1 = $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));
        $carLoad2 = $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 15));

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

        $allocation1 = $this->service->computeProratedFixedCostsForCarLoad($carLoad1);
        $allocation2 = $this->service->computeProratedFixedCostsForCarLoad($carLoad2);

        // Each CarLoad independently receives the full per-vehicle allocation —
        // fixed costs are not split across multiple trips in the same month.
        $this->assertEquals(172_000, $allocation1->storageAllocation);
        $this->assertEquals(172_000, $allocation2->storageAllocation);
    }

    public function test_allocation_returns_zero_dto_when_carload_has_no_load_date(): void
    {
        // Use an in-memory (non-persisted) CarLoad because load_date is NOT NULL in the DB.
        // The service only reads $carLoad->load_date — no DB query is needed for this edge case.
        $carLoad = new CarLoad(['load_date' => null, 'vehicle_id' => null]);

        $allocation = $this->service->computeProratedFixedCostsForCarLoad($carLoad);

        $this->assertEquals(0, $allocation->storageAllocation);
        $this->assertEquals(0, $allocation->overheadAllocation);
    }

    public function test_is_month_finalized_returns_false_before_and_true_after_finalization(): void
    {
        $vehicle = $this->makeVehicle();
        $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));
        $this->makeMonthlyFixedCost(MonthlyFixedCostPool::Storage, MonthlyFixedCostSubCategory::WarehouseRent, 100_000, 2026, 3);

        $this->assertFalse($this->service->isMonthFinalized(2026, 3));

        $this->service->finalizeMonth(2026, 3);

        $this->assertTrue($this->service->isMonthFinalized(2026, 3));
    }

    public function test_multiple_sub_category_entries_in_same_pool_are_summed_into_per_vehicle_total(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoadForVehicleInMonth($vehicle, Carbon::create(2026, 3, 1));

        // Storage pool: 3 sub-categories
        foreach ([
            [MonthlyFixedCostSubCategory::WarehouseRent, 142_000],
            [MonthlyFixedCostSubCategory::Electricity, 10_000],
            [MonthlyFixedCostSubCategory::Wifi, 10_000],
        ] as [$subCategory, $amount]) {
            MonthlyFixedCost::create([
                'cost_pool' => MonthlyFixedCostPool::Storage,
                'sub_category' => $subCategory,
                'amount' => $amount,
                'period_year' => 2026,
                'period_month' => 3,
                'per_vehicle_amount' => $amount,
                'active_vehicle_count' => 1,
                'finalized_at' => now(),
            ]);
        }

        $allocation = $this->service->computeProratedFixedCostsForCarLoad($carLoad);

        $this->assertEquals(162_000, $allocation->storageAllocation);
    }
}
