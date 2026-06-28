<?php

namespace Tests\Feature\Feature;

use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\Commercial;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the PATCH /api/beats/{beat}/rounds/{date}/odometer endpoint.
 *
 * Covers:
 *  - Recording departure km (type=start) stores vehicle_id + odometer_start_km and clears end
 *  - Recording arrival km (type=end) stores odometer_end_km
 *  - distance_km accessor returns end - start
 *  - Arrival km < departure km is rejected (422)
 *  - Recording arrival before departure is rejected (422)
 *  - Unauthenticated request is rejected (401)
 *  - Commercial cannot record odometer on another commercial's round (403)
 *  - Round not found returns 404
 *  - vehicle_id is required when type=start
 */
class BeatRoundOdometerTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2025-06-10';

    private User $user;

    private Commercial $commercial;

    private Beat $beat;

    private BeatRound $round;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Team Test',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Agent Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);

        $this->beat = Beat::create([
            'name' => 'Beat Test',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'name' => 'Beat Test - '.self::ROUND_DATE,
            'commercial_id' => $this->commercial->id,
        ]);

        $this->vehicle = Vehicle::factory()->create();
    }

    private function odometerUrl(string $date = self::ROUND_DATE): string
    {
        return "/api/beats/{$this->beat->id}/rounds/{$date}/odometer";
    }

    // ─── Auth / Authorization ─────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson($this->odometerUrl(), ['type' => 'start', 'km' => 5000, 'vehicle_id' => $this->vehicle->id])
            ->assertUnauthorized();
    }

    public function test_commercial_cannot_record_odometer_on_another_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $team = Team::create(['name' => 'Other Team', 'user_id' => User::factory()->create()->id]);
        Commercial::create([
            'name' => 'Other Agent',
            'phone_number' => '221700000002',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $team->id,
        ]);

        $this->actingAs($otherUser)
            ->patchJson($this->odometerUrl(), ['type' => 'start', 'km' => 5000, 'vehicle_id' => $this->vehicle->id])
            ->assertForbidden();
    }

    // ─── Round not found ──────────────────────────────────────────────────────

    public function test_returns_404_when_no_round_exists_for_given_date(): void
    {
        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl('2025-01-01'), ['type' => 'start', 'km' => 5000, 'vehicle_id' => $this->vehicle->id])
            ->assertNotFound();
    }

    // ─── Recording departure (type=start) ────────────────────────────────────

    public function test_records_departure_km_and_vehicle(): void
    {
        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), [
                'type' => 'start',
                'km' => 15230,
                'vehicle_id' => $this->vehicle->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => ['round_id', 'vehicle_id', 'odometer_start_km', 'odometer_end_km', 'distance_km']])
            ->assertJsonFragment([
                'odometer_start_km' => 15230,
                'odometer_end_km' => null,
                'distance_km' => null,
                'vehicle_id' => $this->vehicle->id,
            ]);

        $this->round->refresh();
        $this->assertSame(15230, $this->round->odometer_start_km);
        $this->assertNull($this->round->odometer_end_km);
        $this->assertSame($this->vehicle->id, $this->round->vehicle_id);
    }

    public function test_recording_start_clears_previous_end_km(): void
    {
        $this->round->update([
            'vehicle_id' => $this->vehicle->id,
            'odometer_start_km' => 10000,
            'odometer_end_km' => 10150,
        ]);

        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), [
                'type' => 'start',
                'km' => 10200,
                'vehicle_id' => $this->vehicle->id,
            ])
            ->assertOk();

        $this->round->refresh();
        $this->assertSame(10200, $this->round->odometer_start_km);
        $this->assertNull($this->round->odometer_end_km);
    }

    public function test_vehicle_id_is_required_when_recording_start(): void
    {
        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), ['type' => 'start', 'km' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id']);
    }

    // ─── Recording arrival (type=end) ─────────────────────────────────────────

    public function test_records_arrival_km_and_computes_distance(): void
    {
        $this->round->update([
            'vehicle_id' => $this->vehicle->id,
            'odometer_start_km' => 15230,
        ]);

        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), ['type' => 'end', 'km' => 15468])
            ->assertOk()
            ->assertJsonFragment([
                'odometer_start_km' => 15230,
                'odometer_end_km' => 15468,
                'distance_km' => 238,
            ]);

        $this->round->refresh();
        $this->assertSame(15468, $this->round->odometer_end_km);
        $this->assertSame(238, $this->round->distance_km);
    }

    public function test_arrival_km_less_than_departure_km_is_rejected(): void
    {
        $this->round->update([
            'vehicle_id' => $this->vehicle->id,
            'odometer_start_km' => 15230,
        ]);

        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), ['type' => 'end', 'km' => 15000])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Le kilométrage d\'arrivée (15000 km) ne peut pas être inférieur au kilométrage de départ (15230 km)']);
    }

    public function test_arrival_km_equal_to_departure_km_is_accepted(): void
    {
        $this->round->update([
            'vehicle_id' => $this->vehicle->id,
            'odometer_start_km' => 15230,
        ]);

        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), ['type' => 'end', 'km' => 15230])
            ->assertOk()
            ->assertJsonFragment(['distance_km' => 0]);
    }

    public function test_arrival_before_departure_recorded_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->patchJson($this->odometerUrl(), ['type' => 'end', 'km' => 15000])
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Le kilométrage de départ doit être enregistré en premier']);
    }

    // ─── distance_km accessor on model ───────────────────────────────────────

    public function test_distance_km_is_null_when_either_reading_is_missing(): void
    {
        $this->round->update(['odometer_start_km' => 5000]);
        $this->assertNull($this->round->fresh()->distance_km);

        $this->round->update(['odometer_start_km' => null, 'odometer_end_km' => 5100]);
        $this->assertNull($this->round->fresh()->distance_km);
    }

    public function test_distance_km_is_computed_correctly(): void
    {
        $this->round->update(['odometer_start_km' => 10000, 'odometer_end_km' => 10350]);
        $this->assertSame(350, $this->round->fresh()->distance_km);
    }
}
