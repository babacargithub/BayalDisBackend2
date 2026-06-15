<?php

namespace Tests\Feature\SalespersonApi;

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

    private const ENDPOINT_PREFIX = '/api/beats';

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
            'commercial_id' => $this->commercial->id,
        ]);
    }

    public function test_adds_customers_to_beat_template_roster(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $customerC = $this->makeCustomer();

        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            ['customer_ids' => [$customerA->id, $customerB->id, $customerC->id]],
        );

        $response->assertOk()->assertJson(['message' => 'Clients ajoutés au beat']);

        $this->assertCount(3, $this->beat->templateStops()->get());
        $this->assertDatabaseHas('beat_stops', [
            'beat_id' => $this->beat->id,
            'customer_id' => $customerA->id,
            'visit_date' => null,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
        $this->assertDatabaseHas('beat_stops', ['beat_id' => $this->beat->id, 'customer_id' => $customerB->id, 'visit_date' => null]);
        $this->assertDatabaseHas('beat_stops', ['beat_id' => $this->beat->id, 'customer_id' => $customerC->id, 'visit_date' => null]);
    }

    public function test_re_adding_existing_customers_is_idempotent(): void
    {
        $customer = $this->makeCustomer();

        // First add
        $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            ['customer_ids' => [$customer->id]],
        )->assertOk();

        // Second add of the same customer — must succeed and not duplicate the stop
        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            ['customer_ids' => [$customer->id]],
        );

        $response->assertOk()->assertJson(['message' => 'Clients ajoutés au beat']);

        $this->assertCount(1, $this->beat->templateStops()->where('customer_id', $customer->id)->get());
    }

    public function test_mix_of_new_and_existing_customers_only_creates_missing_stops(): void
    {
        $existingCustomer = $this->makeCustomer();
        $newCustomer = $this->makeCustomer();

        // Pre-seed the roster with one customer
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $existingCustomer->id,
            'status' => BeatStop::STATUS_PLANNED,
            'visit_date' => null,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            ['customer_ids' => [$existingCustomer->id, $newCustomer->id]],
        );

        $response->assertOk();

        // Roster should now have exactly 2 stops (no duplicate for existing customer)
        $this->assertCount(2, $this->beat->templateStops()->get());
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

        $customer = $this->makeCustomer();

        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$beatOwnedByOther->id}/customers",
            ['customer_ids' => [$customer->id]],
        );

        $response->assertForbidden();
        $this->assertCount(0, $beatOwnedByOther->templateStops()->get());
    }

    public function test_returns_422_when_customer_ids_is_missing(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            [],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_ids']);
    }

    public function test_returns_422_when_customer_id_does_not_exist(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            self::ENDPOINT_PREFIX."/{$this->beat->id}/customers",
            ['customer_ids' => [999999]],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_ids.0']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
