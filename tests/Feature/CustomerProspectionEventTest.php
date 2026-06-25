<?php

namespace Tests\Feature;

use App\Enums\ProspectionStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\CustomerProspectionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProspectionEventTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Commercial $commercial;

    private Customer $prospect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->commercial = Commercial::factory()->create(['user_id' => $this->user->id]);
        $this->prospect = Customer::create([
            'name' => 'Prospect Test',
            'phone_number' => '221700000100',
            'owner_number' => '221700000100',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
            'is_prospect' => true,
        ]);
    }

    public function test_creating_event_updates_customer_current_prospect_status(): void
    {
        CustomerProspectionEvent::create([
            'customer_id' => $this->prospect->id,
            'commercial_id' => $this->commercial->id,
            'status' => ProspectionStatus::InterestedUndecided,
            'notes' => 'Semble intéressé',
        ]);

        $this->prospect->refresh();

        $this->assertEquals(ProspectionStatus::InterestedUndecided->value, $this->prospect->current_prospect_status);
        $this->assertTrue($this->prospect->is_prospect);
    }

    public function test_acquired_status_sets_is_prospect_to_false(): void
    {
        CustomerProspectionEvent::create([
            'customer_id' => $this->prospect->id,
            'commercial_id' => $this->commercial->id,
            'status' => ProspectionStatus::Acquired,
        ]);

        $this->prospect->refresh();

        $this->assertEquals(ProspectionStatus::Acquired->value, $this->prospect->current_prospect_status);
        $this->assertFalse($this->prospect->is_prospect);
    }

    public function test_latest_event_status_overwrites_previous_one(): void
    {
        CustomerProspectionEvent::create([
            'customer_id' => $this->prospect->id,
            'commercial_id' => $this->commercial->id,
            'status' => ProspectionStatus::OwnerAbsent,
            'scheduled_revisit_date' => now()->addWeek()->toDateString(),
        ]);

        CustomerProspectionEvent::create([
            'customer_id' => $this->prospect->id,
            'commercial_id' => $this->commercial->id,
            'status' => ProspectionStatus::InterestedUndecided,
        ]);

        $this->prospect->refresh();

        $this->assertEquals(ProspectionStatus::InterestedUndecided->value, $this->prospect->current_prospect_status);
        $this->assertCount(2, $this->prospect->prospectionEvents);
    }

    public function test_can_filter_customers_by_current_prospect_status_in_index(): void
    {
        Customer::create([
            'name' => 'Prospect Intéressé',
            'phone_number' => '221700000101',
            'owner_number' => '221700000101',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
            'is_prospect' => true,
            'current_prospect_status' => ProspectionStatus::InterestedUndecided->value,
        ]);

        Customer::create([
            'name' => 'Prospect Absent',
            'phone_number' => '221700000102',
            'owner_number' => '221700000102',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
            'is_prospect' => true,
            'current_prospect_status' => ProspectionStatus::OwnerAbsent->value,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('clients.index', ['current_prospect_status' => ProspectionStatus::InterestedUndecided->value]));

        $response->assertOk();
        $response->assertInertia(
            fn ($page) => $page
                ->component('Clients/Index')
                ->where('clients.data', fn ($data) => collect($data)->every(
                    fn ($client) => $client['current_prospect_status'] === ProspectionStatus::InterestedUndecided->value
                ))
        );
    }

    public function test_commercial_can_create_event_via_api(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('salesperson.customers.prospection-events.store', $this->prospect), [
                'status' => ProspectionStatus::Contacted->value,
                'notes' => 'Premier contact réussi',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', ProspectionStatus::Contacted->value);

        $this->assertDatabaseHas('customer_prospection_events', [
            'customer_id' => $this->prospect->id,
            'status' => ProspectionStatus::Contacted->value,
        ]);
    }

    public function test_api_validates_status_enum_value(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('salesperson.customers.prospection-events.store', $this->prospect), [
                'status' => 'invalid_status',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_scheduled_revisit_date_required_when_status_is_owner_absent(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(route('salesperson.customers.prospection-events.store', $this->prospect), [
                'status' => ProspectionStatus::OwnerAbsent->value,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['scheduled_revisit_date']);
    }

    public function test_api_returns_events_in_reverse_chronological_order(): void
    {
        CustomerProspectionEvent::factory()->create([
            'customer_id' => $this->prospect->id,
            'status' => ProspectionStatus::Contacted,
            'created_at' => now()->subDays(5),
        ]);

        CustomerProspectionEvent::factory()->create([
            'customer_id' => $this->prospect->id,
            'status' => ProspectionStatus::InterestedUndecided,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson(route('salesperson.customers.prospection-events.index', $this->prospect));

        $response->assertOk();

        $statuses = collect($response->json('data'))->pluck('status');
        $this->assertEquals(ProspectionStatus::InterestedUndecided->value, $statuses->first());
        $this->assertEquals(ProspectionStatus::Contacted->value, $statuses->last());
    }
}
