<?php

namespace Tests\Feature\PricingPolicy;

use App\Models\PricingPolicy;
use App\Models\User;
use App\Services\PricingPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private PricingPolicyService $pricingPolicyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricingPolicyService = app(PricingPolicyService::class);
    }

    // ─── Service: activate ────────────────────────────────────────────────────

    public function test_activate_marks_the_given_policy_as_active(): void
    {
        $policy = PricingPolicy::factory()->inactive()->create();

        $this->pricingPolicyService->activate($policy);

        $this->assertTrue($policy->fresh()->active);
    }

    public function test_activate_deactivates_all_other_policies(): void
    {
        $previouslyActive = PricingPolicy::factory()->create(['active' => true]);
        $anotherActive = PricingPolicy::factory()->create(['active' => true]);
        $targetPolicy = PricingPolicy::factory()->inactive()->create();

        $this->pricingPolicyService->activate($targetPolicy);

        $this->assertFalse($previouslyActive->fresh()->active);
        $this->assertFalse($anotherActive->fresh()->active);
        $this->assertTrue($targetPolicy->fresh()->active);
    }

    public function test_activate_leaves_exactly_one_active_policy_when_multiple_exist(): void
    {
        PricingPolicy::factory()->count(3)->create(['active' => true]);
        $targetPolicy = PricingPolicy::factory()->inactive()->create();

        $this->pricingPolicyService->activate($targetPolicy);

        $activePolicyCount = PricingPolicy::query()->where('active', true)->count();
        $this->assertSame(1, $activePolicyCount);
    }

    // ─── Service: create ──────────────────────────────────────────────────────

    public function test_create_stores_a_new_pricing_policy_as_inactive(): void
    {
        $data = [
            'name' => 'Politique crédit 30 jours',
            'surcharge_percent' => 10,
            'grace_days' => 15,
            'apply_to_deferred_only' => true,
            'apply_credit_price' => false,
        ];

        $policy = $this->pricingPolicyService->create($data);

        $this->assertFalse($policy->active);
        $this->assertSame('Politique crédit 30 jours', $policy->name);
        $this->assertSame(10, $policy->surcharge_percent);
        $this->assertSame(15, $policy->grace_days);
        $this->assertTrue($policy->apply_to_deferred_only);
        $this->assertFalse($policy->apply_credit_price);
    }

    public function test_create_does_not_deactivate_existing_active_policy(): void
    {
        $existingActivePolicy = PricingPolicy::factory()->create(['active' => true]);

        $this->pricingPolicyService->create([
            'name' => 'Nouvelle politique',
            'surcharge_percent' => 5,
            'grace_days' => 0,
            'apply_to_deferred_only' => false,
            'apply_credit_price' => false,
        ]);

        $this->assertTrue($existingActivePolicy->fresh()->active);
    }

    // ─── Service: update ──────────────────────────────────────────────────────

    public function test_update_changes_properties_without_touching_active_status(): void
    {
        $policy = PricingPolicy::factory()->create([
            'name' => 'Ancienne politique',
            'surcharge_percent' => 5,
            'active' => true,
        ]);

        $this->pricingPolicyService->update($policy, [
            'name' => 'Politique mise à jour',
            'surcharge_percent' => 20,
            'grace_days' => 7,
            'apply_to_deferred_only' => false,
            'apply_credit_price' => true,
        ]);

        $policy->refresh();
        $this->assertSame('Politique mise à jour', $policy->name);
        $this->assertSame(20, $policy->surcharge_percent);
        $this->assertSame(7, $policy->grace_days);
        $this->assertFalse($policy->apply_to_deferred_only);
        $this->assertTrue($policy->apply_credit_price);
        $this->assertTrue($policy->active); // active status must not change
    }

    // ─── HTTP: index ──────────────────────────────────────────────────────────

    public function test_index_page_is_accessible_by_authenticated_user(): void
    {
        $user = User::factory()->create();
        PricingPolicy::factory()->count(2)->create();

        $response = $this->actingAs($user)->get(route('pricing-policies.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('PricingPolicies/Index')
            ->has('pricingPolicies', 2)
        );
    }

    // ─── HTTP: store ──────────────────────────────────────────────────────────

    public function test_store_creates_a_new_inactive_pricing_policy(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('pricing-policies.store'), [
            'name' => 'Test politique',
            'surcharge_percent' => 15,
            'grace_days' => 30,
            'apply_to_deferred_only' => true,
            'apply_credit_price' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pricing_policies', [
            'name' => 'Test politique',
            'surcharge_percent' => 15,
            'active' => false,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('pricing-policies.store'), []);

        $response->assertSessionHasErrors(['name', 'surcharge_percent', 'grace_days']);
    }

    public function test_store_rejects_surcharge_percent_above_100(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('pricing-policies.store'), [
            'name' => 'Test',
            'surcharge_percent' => 150,
            'grace_days' => 0,
            'apply_to_deferred_only' => true,
            'apply_credit_price' => false,
        ]);

        $response->assertSessionHasErrors(['surcharge_percent']);
    }

    // ─── HTTP: update ─────────────────────────────────────────────────────────

    public function test_update_changes_policy_properties(): void
    {
        $user = User::factory()->create();
        $policy = PricingPolicy::factory()->create(['name' => 'Ancienne', 'surcharge_percent' => 5]);

        $response = $this->actingAs($user)->put(route('pricing-policies.update', $policy), [
            'name' => 'Nouvelle',
            'surcharge_percent' => 25,
            'grace_days' => 10,
            'apply_to_deferred_only' => false,
            'apply_credit_price' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pricing_policies', [
            'id' => $policy->id,
            'name' => 'Nouvelle',
            'surcharge_percent' => 25,
        ]);
    }

    // ─── HTTP: activate ───────────────────────────────────────────────────────

    public function test_activate_endpoint_activates_the_policy_and_deactivates_all_others(): void
    {
        $user = User::factory()->create();
        $activePolicyOne = PricingPolicy::factory()->create(['active' => true]);
        $activePolicyTwo = PricingPolicy::factory()->create(['active' => true]);
        $targetPolicy = PricingPolicy::factory()->inactive()->create();

        $response = $this->actingAs($user)->post(route('pricing-policies.activate', $targetPolicy));

        $response->assertRedirect();
        $this->assertTrue($targetPolicy->fresh()->active);
        $this->assertFalse($activePolicyOne->fresh()->active);
        $this->assertFalse($activePolicyTwo->fresh()->active);
    }

    public function test_activate_endpoint_leaves_exactly_one_active_policy(): void
    {
        $user = User::factory()->create();
        PricingPolicy::factory()->count(4)->create(['active' => true]);
        $targetPolicy = PricingPolicy::factory()->inactive()->create();

        $this->actingAs($user)->post(route('pricing-policies.activate', $targetPolicy));

        $activePolicyCount = PricingPolicy::query()->where('active', true)->count();
        $this->assertSame(1, $activePolicyCount);
    }
}
