<?php

namespace Tests\Feature\SalespersonApi;

use App\Enums\BeatStopStatus;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateBeatStopStatusTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2025-06-04';

    private User $user;

    private Commercial $commercial;

    private Beat $beat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $team = Team::create([
            'name' => 'Test Team',
            'user_id' => User::factory()->create()->id,
        ]);
        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);
        $this->beat = Beat::create([
            'name' => 'Beat Test',
            'day_of_week' => DayOfWeek::Wednesday->value,
            'commercial_id' => $this->commercial->id,
        ]);
    }

    // =========================================================================
    // PATCH /api/beats/{beat}/rounds/{date}/stops/{stop}
    // =========================================================================

    public function test_updates_stop_status_and_returns_204(): void
    {
        $stop = $this->makeOccurrenceStop();

        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_COMPLETED],
        );

        $response->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_COMPLETED, $stop->status);
        $this->assertNull($stop->notes);
    }

    public function test_updates_stop_status_and_notes_together(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_CANCELLED, 'notes' => 'Client absent'],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_CANCELLED, $stop->status);
        $this->assertSame('Client absent', $stop->notes);
    }

    public function test_can_set_stop_back_to_planned(): void
    {
        $stop = $this->makeOccurrenceStop(BeatStop::STATUS_COMPLETED);

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_PLANNED],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_PLANNED, $stop->status);
    }

    public function test_invalid_status_value_is_rejected_with_422(): void
    {
        $stop = $this->makeOccurrenceStop();

        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => 'invalid_status'],
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_missing_status_field_is_rejected_with_422(): void
    {
        $stop = $this->makeOccurrenceStop();

        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            [],
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_stop_belonging_to_different_beat_returns_404(): void
    {
        $otherBeat = Beat::create([
            'name' => 'Other Beat',
            'commercial_id' => $this->commercial->id,
        ]);
        $stopOnOtherBeat = $this->makeOccurrenceStop(BeatStop::STATUS_PLANNED, $otherBeat);

        // Request uses $this->beat but stop belongs to $otherBeat
        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stopOnOtherBeat->id}",
            ['status' => BeatStop::STATUS_COMPLETED],
        );

        $response->assertNotFound();
    }

    public function test_stop_on_different_date_returns_404(): void
    {
        // Stop exists but on a different date than the route requests
        $differentDate = '2025-06-11';
        $roundForDifferentDate = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => $differentDate,
            'week_day' => DayOfWeek::Wednesday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.$differentDate,
        ]);
        $stopOnDifferentDate = BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $roundForDifferentDate->id,
            'customer_id' => $this->makeCustomer()->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stopOnDifferentDate->id}",
            ['status' => BeatStop::STATUS_COMPLETED],
        );

        $response->assertNotFound();
    }

    public function test_returns_403_when_beat_belongs_to_different_commercial(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'user_id' => User::factory()->create()->id,
        ]);
        $otherCommercial = Commercial::create([
            'name' => 'Other Commercial',
            'phone_number' => '221700000002',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);
        $beatOwnedByOther = Beat::create([
            'name' => 'Beat Autre',
            'commercial_id' => $otherCommercial->id,
        ]);
        $stop = $this->makeOccurrenceStop(BeatStop::STATUS_PLANNED, $beatOwnedByOther);

        $response = $this->actingAs($this->user)->patchJson(
            "/api/beats/{$beatOwnedByOther->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_COMPLETED],
        );

        $response->assertForbidden();
    }

    // =========================================================================
    // GET /api/beats/{beat}/rounds/{date}/customers — available_statuses field
    // =========================================================================

    public function test_round_customers_response_includes_available_statuses(): void
    {
        $customer = $this->makeCustomer();
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => $this->beat->day_of_week?->value,
            'commercial_id' => $this->commercial->id,
            'name' => $this->beat->name.' - '.self::ROUND_DATE,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE.'/customers',
        );

        $expectedAvailableStatuses = array_map(
            fn (BeatStopStatus $case) => ['status' => $case->value, 'label' => $case->label()],
            BeatStopStatus::cases(),
        );

        $response->assertOk()
            ->assertJsonPath('data.available_statuses', $expectedAvailableStatuses);
    }

    // =========================================================================
    // New no-sale statuses
    // =========================================================================

    public function test_can_set_stop_to_stock_restant_status(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_STOCK_RESTANT, 'notes' => 'Client a encore du stock'],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_STOCK_RESTANT, $stop->status);
        $this->assertSame('Client a encore du stock', $stop->notes);
    }

    public function test_can_set_stop_to_restaurant_ferme_status(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_RESTAURANT_FERME],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_RESTAURANT_FERME, $stop->status);
    }

    public function test_can_set_stop_to_produits_non_disponibles_status(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_PRODUITS_NON_DISPONIBLES],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_PRODUITS_NON_DISPONIBLES, $stop->status);
    }

    public function test_can_set_stop_to_dette_non_acceptee_status(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_DETTE_NON_ACCEPTEE],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_DETTE_NON_ACCEPTEE, $stop->status);
    }

    public function test_round_customers_response_includes_no_sale_count(): void
    {
        $customer = $this->makeCustomer();
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => $this->beat->day_of_week?->value,
            'commercial_id' => $this->commercial->id,
            'name' => $this->beat->name.' - '.self::ROUND_DATE,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE.'/customers',
        );

        $response->assertOk()
            ->assertJsonPath('data.no_sale', 0);
    }

    public function test_can_set_stop_to_reprogramme_status(): void
    {
        $stop = $this->makeOccurrenceStop();

        $this->actingAs($this->user)->patchJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE."/stops/{$stop->id}",
            ['status' => BeatStop::STATUS_REPROGRAMME],
        )->assertNoContent();

        $stop->refresh();
        $this->assertSame(BeatStop::STATUS_REPROGRAMME, $stop->status);
    }

    public function test_no_sale_stops_are_counted_in_round_customers_no_sale_field(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $customerA->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $customerB->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Wednesday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.self::ROUND_DATE,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $customerA->id,
            'status' => BeatStop::STATUS_STOCK_RESTANT,
        ]);
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $customerB->id,
            'status' => BeatStop::STATUS_DETTE_NON_ACCEPTEE,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            "/api/beats/{$this->beat->id}/rounds/".self::ROUND_DATE.'/customers',
        );

        $response->assertOk()
            ->assertJsonPath('data.no_sale', 2)
            ->assertJsonPath('data.planned', 0)
            ->assertJsonPath('data.completed', 0)
            ->assertJsonPath('data.cancelled', 0);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeOccurrenceStop(
        string $status = BeatStop::STATUS_PLANNED,
        ?Beat $beat = null,
    ): BeatStop {
        $targetBeat = $beat ?? $this->beat;
        $round = BeatRound::firstOrCreate(
            ['beat_id' => $targetBeat->id, 'planned_at' => self::ROUND_DATE],
            [
                'name' => $targetBeat->name.' - '.self::ROUND_DATE,
                'week_day' => $targetBeat->day_of_week?->value,
                'commercial_id' => $targetBeat->commercial_id,
            ],
        );

        return BeatStop::create([
            'beat_id' => $targetBeat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $this->makeCustomer()->id,
            'status' => $status,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }
}
