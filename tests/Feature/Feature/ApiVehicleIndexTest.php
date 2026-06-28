<?php

namespace Tests\Feature\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/vehicles — returns the vehicle list for mobile odometer vehicle selection.
 */
class ApiVehicleIndexTest extends TestCase
{
    use RefreshDatabase;

    // ─── Authentication ───────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
    }

    // ─── Empty state ──────────────────────────────────────────────────────────

    public function test_returns_empty_data_array_when_no_vehicles_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/vehicles')
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    // ─── Happy path ───────────────────────────────────────────────────────────

    public function test_returns_all_vehicles_with_required_fields(): void
    {
        $vehicleAlpha = Vehicle::factory()->create(['name' => 'Camion Alpha', 'plate_number' => 'DK-001-AA']);
        $vehicleBeta = Vehicle::factory()->create(['name' => 'Camion Beta', 'plate_number' => 'DK-002-BB']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/vehicles')
            ->assertOk();

        $response->assertJsonCount(2, 'data');

        $response->assertJsonFragment([
            'id' => $vehicleAlpha->id,
            'name' => 'Camion Alpha',
            'plate_number' => 'DK-001-AA',
        ]);

        $response->assertJsonFragment([
            'id' => $vehicleBeta->id,
            'name' => 'Camion Beta',
            'plate_number' => 'DK-002-BB',
        ]);
    }

    public function test_response_does_not_expose_cost_fields(): void
    {
        Vehicle::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/vehicles')
            ->assertOk();

        $firstVehicle = $response->json('data.0');

        $this->assertArrayNotHasKey('insurance_monthly', $firstVehicle);
        $this->assertArrayNotHasKey('driver_salary_monthly', $firstVehicle);
        $this->assertArrayNotHasKey('estimated_daily_fuel_consumption', $firstVehicle);
    }

    public function test_vehicles_are_returned_in_alphabetical_order_by_name(): void
    {
        Vehicle::factory()->create(['name' => 'Zebra']);
        Vehicle::factory()->create(['name' => 'Alpha']);
        Vehicle::factory()->create(['name' => 'Mango']);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/vehicles')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Alpha', 'Mango', 'Zebra'], $names);
    }
}
