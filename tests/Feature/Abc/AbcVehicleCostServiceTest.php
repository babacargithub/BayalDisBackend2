<?php

namespace Tests\Feature\Abc;

use App\Models\CarLoad;
use App\Models\CarLoadFuelEntry;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Abc\AbcVehicleCostService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbcVehicleCostServiceTest extends TestCase
{
    use RefreshDatabase;

    private AbcVehicleCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AbcVehicleCostService;
    }

    private function makeVehicle(array $attributes = []): Vehicle
    {
        return Vehicle::create(array_merge([
            'name' => 'Camion Alpha',
            'insurance_monthly' => 60_000,
            'maintenance_monthly' => 40_000,
            'repair_reserve_monthly' => 20_000,
            'depreciation_monthly' => 34_000,
            'driver_salary_monthly' => 55_000,
            'working_days_per_month' => 26,
        ], $attributes));
    }

    private function makeCarLoad(Vehicle $vehicle, array $attributes = []): CarLoad
    {
        $team = Team::create(['name' => 'Test Team', 'user_id' => User::factory()->create()->id]);

        return CarLoad::create(array_merge([
            'name' => 'Test CarLoad',
            'team_id' => $team->id,
            'vehicle_id' => $vehicle->id,
            'load_date' => now(),
            'return_date' => null,
            'status' => 'SELLING',
        ], $attributes));
    }

    public function test_daily_fixed_cost_is_sum_of_all_monthly_fixed_costs_divided_by_working_days(): void
    {
        $vehicle = $this->makeVehicle([
            'insurance_monthly' => 60_000,
            'maintenance_monthly' => 40_000,
            'repair_reserve_monthly' => 20_000,
            'depreciation_monthly' => 34_000,
            'driver_salary_monthly' => 55_000,
            'working_days_per_month' => 26,
        ]);

        // total monthly = 209,000 / 26 = 8,038.46... → 8,038 after round
        $expectedDailyFixedCost = (int) round(209_000 / 26);

        $this->assertEquals($expectedDailyFixedCost, $this->service->computeDailyFixedCost($vehicle));
    }

    public function test_daily_fixed_cost_returns_zero_when_working_days_is_zero(): void
    {
        $vehicle = $this->makeVehicle(['working_days_per_month' => 0]);

        $this->assertEquals(0, $this->service->computeDailyFixedCost($vehicle));
    }

    public function test_fixed_cost_for_carload_returns_zero_when_no_vehicle_assigned(): void
    {
        $team = Team::create(['name' => 'Team', 'user_id' => User::factory()->create()->id]);
        $carLoad = CarLoad::create([
            'name' => 'CarLoad sans véhicule',
            'team_id' => $team->id,
            'vehicle_id' => null,
            'load_date' => now(),
            'status' => 'SELLING',
        ]);

        $this->assertEquals(0, $this->service->computeFixedCostForCarLoad($carLoad));
    }

    public function test_fixed_cost_for_carload_uses_minimum_one_day_when_same_day_trip(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, [
            'load_date' => Carbon::today(),
            'return_date' => Carbon::today(),
        ]);

        $expectedCost = $this->service->computeDailyFixedCost($vehicle) * 1;

        $this->assertEquals($expectedCost, $this->service->computeFixedCostForCarLoad($carLoad));
    }

    public function test_fixed_cost_for_carload_is_prorated_over_trip_duration(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, [
            'load_date' => Carbon::today(),
            'return_date' => Carbon::today()->addDays(3),
        ]);

        $expectedCost = $this->service->computeDailyFixedCost($vehicle) * 3;

        $this->assertEquals($expectedCost, $this->service->computeFixedCostForCarLoad($carLoad));
    }

    public function test_fuel_cost_for_carload_sums_all_fuel_entries(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle);

        CarLoadFuelEntry::create(['car_load_id' => $carLoad->id, 'amount' => 25_000, 'filled_at' => today()]);
        CarLoadFuelEntry::create(['car_load_id' => $carLoad->id, 'amount' => 18_000, 'filled_at' => today()]);

        $this->assertEquals(43_000, $this->service->computeFuelCostForCarLoad($carLoad));
    }

    public function test_fuel_cost_is_zero_when_no_fuel_entries(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle);

        $this->assertEquals(0, $this->service->computeFuelCostForCarLoad($carLoad));
    }

    public function test_total_vehicle_cost_is_fixed_plus_fuel(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle, [
            'load_date' => Carbon::today(),
            'return_date' => Carbon::today()->addDays(2),
        ]);

        CarLoadFuelEntry::create(['car_load_id' => $carLoad->id, 'amount' => 30_000, 'filled_at' => today()]);

        $expectedFixedCost = $this->service->computeDailyFixedCost($vehicle) * 2;
        $expectedTotal = $expectedFixedCost + 30_000;

        $this->assertEquals($expectedTotal, $this->service->computeTotalVehicleCostForCarLoad($carLoad));
    }

    public function test_car_load_snapshots_fixed_daily_cost_when_vehicle_is_assigned(): void
    {
        $vehicle = $this->makeVehicle([
            'insurance_monthly' => 60_000,
            'maintenance_monthly' => 40_000,
            'repair_reserve_monthly' => 20_000,
            'depreciation_monthly' => 34_000,
            'driver_salary_monthly' => 55_000,
            'working_days_per_month' => 26,
        ]);

        $carLoad = $this->makeCarLoad($vehicle);

        $expectedDailyRate = (int) round(209_000 / 26);

        $this->assertEquals($expectedDailyRate, $carLoad->fixed_daily_cost);
    }

    public function test_snapshot_is_preserved_even_after_vehicle_monthly_costs_change(): void
    {
        $vehicle = $this->makeVehicle([
            'insurance_monthly' => 60_000,
            'maintenance_monthly' => 40_000,
            'repair_reserve_monthly' => 20_000,
            'depreciation_monthly' => 34_000,
            'driver_salary_monthly' => 55_000,
            'working_days_per_month' => 26,
        ]);

        $carLoad = $this->makeCarLoad($vehicle, [
            'load_date' => Carbon::today(),
            'return_date' => Carbon::today()->addDays(3),
        ]);

        $originalDailyRate = $carLoad->fixed_daily_cost;

        // Simulate vehicle cost increase after the car load was created
        $vehicle->update(['insurance_monthly' => 120_000]);

        $carLoad->refresh();

        // The snapshot must not change — trip cost is frozen at assignment time
        $this->assertEquals($originalDailyRate, $carLoad->fixed_daily_cost);

        // The service must use the snapshot, not the new live rate
        $expectedTripCost = $originalDailyRate * 3;
        $this->assertEquals($expectedTripCost, $this->service->computeFixedCostForCarLoad($carLoad));
    }

    public function test_snapshot_is_cleared_when_vehicle_is_unassigned(): void
    {
        $vehicle = $this->makeVehicle();
        $carLoad = $this->makeCarLoad($vehicle);

        $this->assertNotNull($carLoad->fixed_daily_cost);

        $carLoad->update(['vehicle_id' => null]);

        $this->assertNull($carLoad->fresh()->fixed_daily_cost);
    }
}
