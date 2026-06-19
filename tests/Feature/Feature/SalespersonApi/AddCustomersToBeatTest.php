<?php

namespace Tests\Feature\Feature\SalespersonApi;

use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddCustomersToBeatTest extends TestCase
{
    use RefreshDatabase;

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
            'phone_number' => '221700000002',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_add_customers_to_beat_returns_success_message(): void
    {
        $beat = Beat::create(['name' => 'Beat Test', 'commercial_id' => $this->commercial->id]);

        $customerA = Customer::create([
            'name' => 'Customer A',
            'address' => 'Adresse A',
            'phone_number' => '221700000010',
            'owner_number' => '221700000010',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
        $customerB = Customer::create([
            'name' => 'Customer B',
            'address' => 'Adresse B',
            'phone_number' => '221700000011',
            'owner_number' => '221700000011',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/beats/{$beat->id}/customers", [
            'customer_ids' => [$customerA->id, $customerB->id],
        ]);

        $response->assertOk()->assertJson(['message' => 'Clients ajoutés au beat']);

        $this->assertDatabaseHas('beat_stops', ['beat_id' => $beat->id, 'customer_id' => $customerA->id, 'visit_date' => null]);
        $this->assertDatabaseHas('beat_stops', ['beat_id' => $beat->id, 'customer_id' => $customerB->id, 'visit_date' => null]);
    }

    public function test_add_customers_to_beat_returns_403_for_other_commercials_beat(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create(['name' => 'Other Team', 'user_id' => User::factory()->create()->id]);
        $otherCommercial = Commercial::create([
            'name' => 'Other Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);
        $beat = Beat::create(['name' => 'Their Beat', 'commercial_id' => $otherCommercial->id]);

        $customer = Customer::create([
            'name' => 'Customer X',
            'address' => 'Adresse X',
            'phone_number' => '221700000020',
            'owner_number' => '221700000020',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $otherCommercial->id,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/beats/{$beat->id}/customers", [
            'customer_ids' => [$customer->id],
        ]);

        $response->assertForbidden();
    }

    public function test_add_customers_to_beat_silently_skips_already_added_customers(): void
    {
        $beat = Beat::create(['name' => 'Beat Dedupe', 'commercial_id' => $this->commercial->id]);

        $customer = Customer::create([
            'name' => 'Customer Y',
            'address' => 'Adresse Y',
            'phone_number' => '221700000030',
            'owner_number' => '221700000030',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        BeatStop::create(['beat_id' => $beat->id, 'customer_id' => $customer->id, 'visit_date' => null]);

        $response = $this->actingAs($this->user)->postJson("/api/beats/{$beat->id}/customers", [
            'customer_ids' => [$customer->id],
        ]);

        $response->assertOk();
        $this->assertCount(1, BeatStop::where('beat_id', $beat->id)->where('customer_id', $customer->id)->whereNull('visit_date')->get());
    }
}
