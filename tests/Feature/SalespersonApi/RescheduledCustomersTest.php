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

class RescheduledCustomersTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/beats/rescheduled-customers';

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
            'name' => 'Beat Lundi',
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_returns_empty_list_when_no_rescheduled_stops_exist(): void
    {
        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_returns_rescheduled_stop_within_date_range(): void
    {
        $customer = $this->makeCustomer();
        $this->makeRescheduledStop($customer, '2026-06-23', notes: 'Patron absent');

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.id', $customer->id)
            ->assertJsonPath('data.0.customer.name', $customer->name)
            ->assertJsonPath('data.0.customer.address', $customer->address)
            ->assertJsonPath('data.0.customer.phone_number', $customer->phone_number)
            ->assertJsonPath('data.0.original_beat.id', $this->beat->id)
            ->assertJsonPath('data.0.original_beat.name', $this->beat->name)
            ->assertJsonPath('data.0.original_round_date', '2026-06-23')
            ->assertJsonPath('data.0.notes', 'Patron absent');
    }

    public function test_does_not_return_stop_outside_date_range(): void
    {
        // Stop is on 2026-06-20, but query is for 2026-06-23 to 2026-06-27
        $this->makeRescheduledStop($this->makeCustomer(), '2026-06-20');

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_does_not_return_stops_belonging_to_another_commercial(): void
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
        $otherBeat = Beat::create([
            'name' => 'Beat Autre',
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $otherCommercial->id,
        ]);
        $this->makeRescheduledStop($this->makeCustomer(), '2026-06-23', beat: $otherBeat);

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_does_not_return_stops_with_non_reprogramme_statuses(): void
    {
        $roundDate = '2026-06-23';
        $round = $this->makeRound($roundDate);

        foreach ([
            BeatStopStatus::Planned->value,
            BeatStopStatus::Completed->value,
            BeatStopStatus::Cancelled->value,
            BeatStopStatus::StockRestant->value,
            BeatStopStatus::DetteNonAcceptee->value,
        ] as $status) {
            BeatStop::create([
                'beat_id' => $this->beat->id,
                'beat_round_id' => $round->id,
                'customer_id' => $this->makeCustomer()->id,
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_returns_multiple_rescheduled_stops_across_different_rounds(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->makeRescheduledStop($customerA, '2026-06-23');
        $this->makeRescheduledStop($customerB, '2026-06-25');

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_stop_id_is_present_in_response(): void
    {
        $stop = $this->makeRescheduledStop($this->makeCustomer(), '2026-06-23');

        $response = $this->actingAs($this->user)->getJson(
            self::ENDPOINT.'?from=2026-06-23&to=2026-06-27',
        );

        $response->assertOk()
            ->assertJsonPath('data.0.stop_id', $stop->id);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRound(string $date, ?Beat $beat = null): BeatRound
    {
        $targetBeat = $beat ?? $this->beat;

        return BeatRound::firstOrCreate(
            ['beat_id' => $targetBeat->id, 'planned_at' => $date],
            [
                'name' => $targetBeat->name.' - '.$date,
                'week_day' => $targetBeat->day_of_week?->value,
                'commercial_id' => $targetBeat->commercial_id,
            ],
        );
    }

    private function makeRescheduledStop(
        Customer $customer,
        string $roundDate,
        ?string $notes = null,
        ?Beat $beat = null,
    ): BeatStop {
        $round = $this->makeRound($roundDate, $beat);

        return BeatStop::create([
            'beat_id' => ($beat ?? $this->beat)->id,
            'beat_round_id' => $round->id,
            'customer_id' => $customer->id,
            'status' => BeatStopStatus::Reprogramme->value,
            'notes' => $notes,
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
