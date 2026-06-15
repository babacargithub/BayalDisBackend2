<?php

namespace Tests\Feature\SalespersonApi;

use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BeatsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/beats';

    private User $user;

    private Commercial $commercial;

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
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson(self::ENDPOINT);

        $response->assertUnauthorized();
    }

    public function test_returns_empty_list_when_commercial_has_no_beats(): void
    {
        $response = $this->actingAs($this->user)->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_returns_beats_with_correct_customers_count(): void
    {
        $beatWithThreeCustomers = Beat::create([
            'name' => 'Marché Central',
            'commercial_id' => $this->commercial->id,
        ]);

        $beatWithOneCustomer = Beat::create([
            'name' => 'Zone Nord',
            'commercial_id' => $this->commercial->id,
        ]);

        Beat::create([
            'name' => 'Zone Vide',
            'commercial_id' => $this->commercial->id,
        ]);

        // Add 3 template stops (visit_date IS NULL) to the first beat
        foreach (range(1, 3) as $_) {
            $customer = $this->makeCustomer();
            BeatStop::create([
                'beat_id' => $beatWithThreeCustomers->id,
                'customer_id' => $customer->id,
                'visit_date' => null,
            ]);
        }

        // Add 1 template stop to the second beat
        $customer = $this->makeCustomer();
        BeatStop::create([
            'beat_id' => $beatWithOneCustomer->id,
            'customer_id' => $customer->id,
            'visit_date' => null,
        ]);

        // Add an occurrence stop (with visit_date) — must NOT be counted as a customer
        BeatStop::create([
            'beat_id' => $beatWithThreeCustomers->id,
            'customer_id' => $customer->id,
            'visit_date' => now()->toDateString(),
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)->getJson(self::ENDPOINT);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(3, $data);

        $beatsByName = collect($data)->keyBy('name');

        $this->assertEquals(3, $beatsByName['Marché Central']['customers_count']);
        $this->assertEquals(1, $beatsByName['Zone Nord']['customers_count']);
        $this->assertEquals(0, $beatsByName['Zone Vide']['customers_count']);

        // Each item must expose id, name, customers_count
        foreach ($data as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('customers_count', $item);
        }
    }

    private function makeSalesInvoice(Customer $customer, int $totalAmount, int $totalPayments): SalesInvoice
    {
        $invoice = new SalesInvoice([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => $totalPayments >= $totalAmount ? 'FULLY_PAID' : ($totalPayments > 0 ? 'PARTIALLY_PAID' : 'DRAFT'),
            'paid' => $totalPayments >= $totalAmount,
        ]);
        $invoice->total_amount = $totalAmount;
        $invoice->total_payments = $totalPayments;
        $invoice->save();

        return $invoice;
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

    // ─── GET /api/beats/{beat}/customers ─────────────────────────────────────

    public function test_beat_customers_returns_403_when_beat_belongs_to_another_commercial(): void
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
        $beat = Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertForbidden();
    }

    public function test_beat_customers_returns_empty_list_when_beat_has_no_template_stops(): void
    {
        $beat = Beat::create(['name' => 'Empty Beat', 'commercial_id' => $this->commercial->id]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_beat_customers_returns_customer_list_with_correct_shape(): void
    {
        $beat = Beat::create(['name' => 'Marché Sandaga', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);

        $item = $data[0];
        $this->assertEquals($customer->id, $item['id']);
        $this->assertEquals($customer->name, $item['name']);
        $this->assertEquals($customer->address, $item['address']);
        $this->assertArrayHasKey('debt', $item);
    }

    public function test_beat_customers_debt_sums_unpaid_invoice_amounts_only(): void
    {
        $beat = Beat::create(['name' => 'Zone Test', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);

        // Partially paid invoice: 10 000 total, 4 000 paid → 6 000 remaining
        $this->makeSalesInvoice($customer, totalAmount: 10000, totalPayments: 4000);

        // Fully paid invoice: 5 000 total, 5 000 paid → 0 remaining
        $this->makeSalesInvoice($customer, totalAmount: 5000, totalPayments: 5000);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $this->assertEquals(6000, $response->json('data.0.debt'));
    }

    public function test_beat_customers_excludes_occurrence_stops(): void
    {
        $beat = Beat::create(['name' => 'Zone Exclusion', 'commercial_id' => $this->commercial->id]);
        $templateCustomer = $this->makeCustomer();
        $occurrenceOnlyCustomer = $this->makeCustomer();

        // Template stop — should appear
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $templateCustomer->id, 'visit_date' => null]);

        // Occurrence stop only (has a date) — must NOT appear
        BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $occurrenceOnlyCustomer->id,
            'visit_date' => now()->toDateString(),
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($templateCustomer->id, $ids);
        $this->assertNotContains($occurrenceOnlyCustomer->id, $ids);
    }

    public function test_only_returns_beats_belonging_to_authenticated_commercial(): void
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

        Beat::create(['name' => 'My Beat', 'commercial_id' => $this->commercial->id]);
        Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);

        $response = $this->actingAs($this->user)->getJson(self::ENDPOINT);

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertContains('My Beat', $names);
        $this->assertNotContains('Their Beat', $names);
    }

    // ─── display_position ordering & reorder endpoint ────────────────────────

    public function test_list_beat_customers_includes_display_position_in_response(): void
    {
        $beat = Beat::create(['name' => 'Zone Position', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null, 'display_position' => 2]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $this->assertArrayHasKey('display_position', $response->json('data.0'));
        $this->assertEquals(2, $response->json('data.0.display_position'));
    }

    public function test_list_beat_customers_orders_by_display_position_then_id(): void
    {
        $beat = Beat::create(['name' => 'Zone Ordre', 'commercial_id' => $this->commercial->id]);

        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $customerC = $this->makeCustomer();

        // Insert in reverse order with explicit positions — expect them back sorted
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerC->id, 'visit_date' => null, 'display_position' => 2]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null, 'display_position' => 0]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null, 'display_position' => 1]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$customerA->id, $customerB->id, $customerC->id], $ids);
    }

    public function test_list_beat_customers_places_null_position_after_positioned_customers(): void
    {
        $beat = Beat::create(['name' => 'Zone Null', 'commercial_id' => $this->commercial->id]);

        $positioned = $this->makeCustomer();
        $unpositioned = $this->makeCustomer();

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $unpositioned->id, 'visit_date' => null, 'display_position' => null]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $positioned->id, 'visit_date' => null, 'display_position' => 0]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$positioned->id, $unpositioned->id], $ids);
    }

    public function test_reorder_beat_customers_returns_403_for_other_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'T2', 'user_id' => User::factory()->create()->id]);
        $otherCommercial = Commercial::create([
            'name' => 'Other',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);
        $beat = Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);

        $response = $this->actingAs($this->user)->putJson("/api/beats/{$beat->id}/customers/reorder", [
            'positions' => [],
        ]);

        $response->assertForbidden();
    }

    public function test_reorder_beat_customers_validates_required_fields(): void
    {
        $beat = Beat::create(['name' => 'Zone Validate', 'commercial_id' => $this->commercial->id]);

        $response = $this->actingAs($this->user)->putJson("/api/beats/{$beat->id}/customers/reorder", []);

        $response->assertUnprocessable();
    }

    public function test_reorder_beat_customers_updates_display_position_on_template_stops(): void
    {
        $beat = Beat::create(['name' => 'Zone Reorder', 'commercial_id' => $this->commercial->id]);

        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $customerC = $this->makeCustomer();

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null, 'display_position' => 0]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null, 'display_position' => 1]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerC->id, 'visit_date' => null, 'display_position' => 2]);

        // Reverse the order
        $response = $this->actingAs($this->user)->putJson("/api/beats/{$beat->id}/customers/reorder", [
            'positions' => [
                ['customer_id' => $customerA->id, 'display_position' => 2],
                ['customer_id' => $customerB->id, 'display_position' => 1],
                ['customer_id' => $customerC->id, 'display_position' => 0],
            ],
        ]);

        $response->assertOk()->assertJson(['message' => 'Ordre mis à jour']);

        // Confirm persisted order by calling the list endpoint
        $listResponse = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/customers");
        $ids = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertEquals([$customerC->id, $customerB->id, $customerA->id], $ids);
    }

    public function test_reorder_does_not_affect_occurrence_stops_for_same_customer(): void
    {
        $beat = Beat::create(['name' => 'Zone Occurrence', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null, 'display_position' => 0]);
        $occurrenceStop = BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'visit_date' => now()->toDateString(),
            'status' => BeatStop::STATUS_PLANNED,
            'display_position' => null,
        ]);

        $this->actingAs($this->user)->putJson("/api/beats/{$beat->id}/customers/reorder", [
            'positions' => [['customer_id' => $customer->id, 'display_position' => 5]],
        ]);

        // Occurrence stop must remain untouched
        $this->assertNull($occurrenceStop->fresh()->display_position);
    }

    // ─── DELETE /api/beats/{beat}/customers/{customer} ────────────────────────

    public function test_remove_customer_returns_403_for_other_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'T3', 'user_id' => User::factory()->create()->id]);
        $otherCommercial = Commercial::create([
            'name' => 'Other3',
            'phone_number' => '221700000098',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);
        $beat = Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);
        $customer = $this->makeCustomer();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$beat->id}/customers/{$customer->id}");

        $response->assertForbidden();
    }

    public function test_remove_customer_returns_404_when_no_template_stop_exists(): void
    {
        $beat = Beat::create(['name' => 'Zone 404', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$beat->id}/customers/{$customer->id}");

        $response->assertNotFound();
    }

    public function test_remove_customer_returns_404_when_only_occurrence_stop_exists(): void
    {
        $beat = Beat::create(['name' => 'Zone Occurrence Only', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();

        BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'visit_date' => now()->toDateString(),
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$beat->id}/customers/{$customer->id}");

        $response->assertNotFound();
    }

    public function test_remove_customer_deletes_template_stop_and_returns_success(): void
    {
        $beat = Beat::create(['name' => 'Zone Delete', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();
        $templateStop = BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'visit_date' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$beat->id}/customers/{$customer->id}");

        $response->assertOk()->assertJson(['message' => 'Client retiré du beat']);
        $this->assertNull($templateStop->fresh());
    }

    public function test_remove_customer_does_not_delete_occurrence_stops(): void
    {
        $beat = Beat::create(['name' => 'Zone Keep Occurrence', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);
        $occurrenceStop = BeatStop::create([
            'beat_id' => $beat->id,
            'customer_id' => $customer->id,
            'visit_date' => now()->toDateString(),
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/beats/{$beat->id}/customers/{$customer->id}");

        $this->assertNotNull($occurrenceStop->fresh());
    }

    // ─── GET /api/beats/{beat}/rounds ─────────────────────────────────────────

    public function test_list_rounds_returns_403_for_other_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'T4', 'user_id' => User::factory()->create()->id]);
        $otherCommercial = Commercial::create([
            'name' => 'Other4', 'phone_number' => '221700000097', 'gender' => 'male',
            'user_id' => $otherUser->id, 'team_id' => $otherTeam->id,
        ]);
        $beat = Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);

        $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/rounds")->assertForbidden();
    }

    public function test_list_rounds_returns_upcoming_dates_based_on_day_of_week(): void
    {
        $beat = Beat::create([
            'name' => 'Beat Lundi Test',
            'commercial_id' => $this->commercial->id,
            'day_of_week' => 'monday',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/rounds");

        $response->assertOk();
        $data = $response->json('data');

        // Must return 4 upcoming Mondays
        $this->assertCount(4, $data);
        foreach ($data as $round) {
            $this->assertEquals('upcoming', $round['status']);
            $this->assertEquals('Monday', \Carbon\Carbon::parse($round['date'])->englishDayOfWeek);
            $this->assertArrayHasKey('label', $round);
            $this->assertArrayHasKey('total', $round);
            $this->assertArrayHasKey('completed', $round);
            $this->assertArrayHasKey('cancelled', $round);
            $this->assertArrayHasKey('planned', $round);
        }
    }

    public function test_list_rounds_returns_empty_when_no_day_of_week_and_no_past_rounds(): void
    {
        $beat = Beat::create(['name' => 'Beat Sans Jour', 'commercial_id' => $this->commercial->id]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/rounds");

        $response->assertOk()->assertJson(['data' => []]);
    }

    public function test_list_rounds_returns_correct_status_for_existing_rounds(): void
    {
        $beat = Beat::create([
            'name' => 'Beat Status Test',
            'commercial_id' => $this->commercial->id,
            'day_of_week' => 'monday',
        ]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);

        $pastDate = '2026-01-05'; // A Monday in the past
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => $pastDate, 'status' => BeatStop::STATUS_COMPLETED]);

        $inProgressDate = '2026-01-12'; // A Monday in the past with remaining planned
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => $inProgressDate, 'status' => BeatStop::STATUS_PLANNED]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/rounds");
        $response->assertOk();

        $byDate = collect($response->json('data'))->keyBy('date');

        $this->assertEquals('done', $byDate[$pastDate]['status']);
        $this->assertEquals('in_progress', $byDate[$inProgressDate]['status']);
    }

    public function test_list_rounds_does_not_duplicate_dates_that_already_have_stops(): void
    {
        $beat = Beat::create([
            'name' => 'Beat No Dupe',
            'commercial_id' => $this->commercial->id,
            'day_of_week' => 'monday',
        ]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);

        // Generate the next Monday's round manually so it appears as existing
        $nextMonday = \Carbon\Carbon::now()->next(\Carbon\Carbon::MONDAY)->toDateString();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => $nextMonday, 'status' => BeatStop::STATUS_PLANNED]);

        $response = $this->actingAs($this->user)->getJson("/api/beats/{$beat->id}/rounds");
        $response->assertOk();

        $dates = collect($response->json('data'))->pluck('date')->all();
        $this->assertEquals(count($dates), count(array_unique($dates)), 'Round dates must be unique');
        // Next Monday shows as "upcoming" from existing rounds + 4 newly computed upcoming dates = 5 total
        $upcomingCount = collect($response->json('data'))->where('status', 'upcoming')->count();
        $this->assertEquals(5, $upcomingCount);
    }

    // ─── GET /api/beats/{beat}/rounds/{date}/customers ────────────────────────

    public function test_list_round_customers_returns_403_for_other_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'T5', 'user_id' => User::factory()->create()->id]);
        $otherCommercial = Commercial::create([
            'name' => 'Other5', 'phone_number' => '221700000096', 'gender' => 'male',
            'user_id' => $otherUser->id, 'team_id' => $otherTeam->id,
        ]);
        $beat = Beat::create(['name' => 'Their Beat2', 'commercial_id' => $otherCommercial->id]);

        $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/2026-06-09/customers")
            ->assertForbidden();
    }

    public function test_list_round_customers_rejects_invalid_date_format(): void
    {
        $beat = Beat::create(['name' => 'Beat Date', 'commercial_id' => $this->commercial->id]);

        $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/not-a-date/customers")
            ->assertUnprocessable();
    }

    public function test_list_round_customers_auto_generates_stops_for_new_date(): void
    {
        $beat = Beat::create(['name' => 'Beat AutoGen', 'commercial_id' => $this->commercial->id]);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null, 'display_position' => 0]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null, 'display_position' => 1]);

        $futureDate = '2030-06-10'; // far future, guaranteed no stops

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/{$futureDate}/customers");

        $response->assertOk();
        $this->assertCount(2, $response->json('data.customers'));

        // Calling again must not duplicate stops
        $response2 = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/{$futureDate}/customers");
        $this->assertCount(2, $response2->json('data.customers'));
    }

    public function test_list_round_customers_copies_display_position_from_template_on_generation(): void
    {
        $beat = Beat::create(['name' => 'Beat Position Copy', 'commercial_id' => $this->commercial->id]);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null, 'display_position' => 1]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null, 'display_position' => 0]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/2030-06-17/customers");

        $response->assertOk();
        $customerIds = collect($response->json('data.customers'))->pluck('customer_id')->all();

        // customerB has position 0, so it must come first
        $this->assertEquals($customerB->id, $customerIds[0]);
        $this->assertEquals($customerA->id, $customerIds[1]);
    }

    public function test_list_round_customers_returns_correct_shape_and_counts(): void
    {
        $beat = Beat::create(['name' => 'Beat Shape', 'commercial_id' => $this->commercial->id]);
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null]);

        $date = '2026-01-05';
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => $date, 'status' => BeatStop::STATUS_COMPLETED, 'visited_at' => now()]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => $date, 'status' => BeatStop::STATUS_PLANNED]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/{$date}/customers");

        $response->assertOk();
        $this->assertEquals('in_progress', $response->json('data.status'));
        $this->assertEquals(2, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.completed'));
        $this->assertEquals(1, $response->json('data.planned'));
        $this->assertEquals(0, $response->json('data.cancelled'));
        $this->assertEquals($date, $response->json('data.date'));
        $this->assertStringContainsString('2026', $response->json('data.label'));

        $customer = $response->json('data.customers.0');
        $this->assertArrayHasKey('stop_id', $customer);
        $this->assertArrayHasKey('customer_id', $customer);
        $this->assertArrayHasKey('name', $customer);
        $this->assertArrayHasKey('address', $customer);
        $this->assertArrayHasKey('phone_number', $customer);
        $this->assertArrayHasKey('debt', $customer);
        $this->assertArrayHasKey('status', $customer);
        $this->assertArrayHasKey('visited_at', $customer);
        $this->assertArrayHasKey('notes', $customer);
        $this->assertArrayHasKey('display_position', $customer);
    }

    public function test_round_customers_debt_excludes_invoices_created_on_round_date(): void
    {
        $roundDate = '2026-06-09';

        $beat = Beat::create(['name' => 'Beat Debt Filter', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => $roundDate, 'status' => BeatStop::STATUS_PLANNED]);

        // Invoice created the day before the round — must be included in debt
        $previousInvoice = $this->makeSalesInvoice($customer, totalAmount: 10000, totalPayments: 4000);
        $previousInvoice->created_at = \Carbon\Carbon::parse($roundDate)->subDay();
        $previousInvoice->save();

        // Invoice created on the round date itself — must be excluded from debt
        $sameDayInvoice = $this->makeSalesInvoice($customer, totalAmount: 5000, totalPayments: 0);
        $sameDayInvoice->created_at = \Carbon\Carbon::parse($roundDate)->midDay();
        $sameDayInvoice->save();

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/{$roundDate}/customers");

        $response->assertOk();
        // Only the 6 000 remaining from the previous invoice should count
        $this->assertEquals(6000, $response->json('data.customers.0.debt'));
    }

    public function test_round_customers_debt_is_zero_when_no_prior_invoices(): void
    {
        $roundDate = '2026-06-09';

        $beat = Beat::create(['name' => 'Beat No Prior Debt', 'commercial_id' => $this->commercial->id]);
        $customer = $this->makeCustomer();
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);
        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => $roundDate, 'status' => BeatStop::STATUS_PLANNED]);

        // Invoice created on the round date — must not count
        $sameDayInvoice = $this->makeSalesInvoice($customer, totalAmount: 8000, totalPayments: 0);
        $sameDayInvoice->created_at = \Carbon\Carbon::parse($roundDate);
        $sameDayInvoice->save();

        $response = $this->actingAs($this->user)
            ->getJson("/api/beats/{$beat->id}/rounds/{$roundDate}/customers");

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.customers.0.debt'));
    }
}
