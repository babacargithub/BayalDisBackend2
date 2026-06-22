<?php

namespace Tests\Feature\Feature;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the web Beats show page and the GET /beats/{beat}/rounds/{date} JSON endpoint.
 *
 * Covers:
 *  - BeatStopController::show() includes rounds data in Inertia props
 *  - BeatStopController::getRoundDetail() returns round customers with financial summary
 *  - Unauthenticated access is rejected
 */
class BeatsWebShowRoundsTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2025-01-06'; // A Monday

    private User $user;

    private Beat $beat;

    private Commercial $commercial;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);

        $this->beat = Beat::create([
            'name' => 'Beat Test',
            'day_of_week' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'address' => 'Dakar Centre',
            'phone_number' => '221700000002',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        // Template stop (no round — defines recurring roster)
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $this->customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
    }

    public function test_show_page_is_accessible_by_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('beats.show', $this->beat));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Beats/Show'));
    }

    public function test_show_page_returns_rounds_prop(): void
    {
        $this->actingAs($this->user)
            ->get(route('beats.show', $this->beat))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Beats/Show')
                ->has('rounds')
            );
    }

    public function test_show_page_rounds_prop_is_empty_when_no_rounds_created(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('beats.show', $this->beat));

        $response->assertOk();

        $rounds = $response->original->getData()['page']['props']['rounds'];
        $this->assertEmpty($rounds, 'Expected no rounds when none have been explicitly created');
    }

    public function test_show_page_rounds_includes_past_round_when_occurrence_stops_exist(): void
    {
        $round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.self::ROUND_DATE,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $this->customer->id,
            'status' => BeatStop::STATUS_COMPLETED,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('beats.show', $this->beat));

        $response->assertOk();

        $rounds = $response->original->getData()['page']['props']['rounds'];

        $pastRound = collect($rounds)->firstWhere('date', self::ROUND_DATE);
        $this->assertNotNull($pastRound, 'Expected a round entry for '.self::ROUND_DATE);
        $this->assertSame('done', $pastRound['status']);
        $this->assertSame(1, $pastRound['completed']);
        $this->assertSame(0, $pastRound['planned']);
    }

    public function test_show_page_requires_authentication(): void
    {
        $this->get(route('beats.show', $this->beat))
            ->assertRedirect(route('login'));
    }

    public function test_get_round_detail_returns_json_with_correct_structure(): void
    {
        $round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.self::ROUND_DATE,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $this->customer->id,
            'status' => BeatStop::STATUS_COMPLETED,
            'visited_at' => Carbon::parse(self::ROUND_DATE)->setTime(10, 30),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('beats.rounds.detail', ['beat' => $this->beat->id, 'date' => self::ROUND_DATE]));

        $response->assertOk();
        $response->assertJsonStructure([
            'date',
            'label',
            'status',
            'total',
            'completed',
            'cancelled',
            'planned',
            'total_debt_to_collect',
            'total_collected',
            'remaining_to_collect',
            'customers',
        ]);
    }

    public function test_get_round_detail_returns_correct_stop_counts(): void
    {
        $anotherCustomer = Customer::create([
            'name' => 'Client B',
            'address' => 'Parcelles',
            'phone_number' => '221700000004',
            'owner_number' => '221700000005',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.self::ROUND_DATE,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $this->customer->id,
            'status' => BeatStop::STATUS_COMPLETED,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $anotherCustomer->id,
            'status' => BeatStop::STATUS_CANCELLED,
        ]);

        // Template stop for second customer (required for getOrGenerateStopsForDate to not regenerate)
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $anotherCustomer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('beats.rounds.detail', ['beat' => $this->beat->id, 'date' => self::ROUND_DATE]));

        $response->assertOk();
        $response->assertJson([
            'date' => self::ROUND_DATE,
            'total' => 2,
            'completed' => 1,
            'cancelled' => 1,
            'planned' => 0,
        ]);
    }

    public function test_get_round_detail_customers_include_required_fields(): void
    {
        $round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Monday->value,
            'commercial_id' => $this->commercial->id,
            'name' => 'Beat Test - '.self::ROUND_DATE,
        ]);

        BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $round->id,
            'customer_id' => $this->customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('beats.rounds.detail', ['beat' => $this->beat->id, 'date' => self::ROUND_DATE]));

        $response->assertOk();
        $response->assertJsonStructure([
            'customers' => [
                '*' => [
                    'stop_id',
                    'customer_id',
                    'name',
                    'address',
                    'phone_number',
                    'debt',
                    'status',
                    'visited_at',
                    'notes',
                    'display_position',
                ],
            ],
        ]);
    }

    public function test_get_round_detail_requires_authentication(): void
    {
        $this->getJson(route('beats.rounds.detail', ['beat' => $this->beat->id, 'date' => self::ROUND_DATE]))
            ->assertUnauthorized();
    }

    public function test_get_round_detail_returns_404_for_unknown_beat(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('beats.rounds.detail', ['beat' => 999999, 'date' => self::ROUND_DATE]))
            ->assertNotFound();
    }
}
