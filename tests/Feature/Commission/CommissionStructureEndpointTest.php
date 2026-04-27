<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialNewCustomerCommissionSetting;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialWorkPeriod;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GET /api/salesperson/commission-structure.
 *
 * Verifies the static/configuration response: CA tiers, new-customer bonuses,
 * and mandatory daily threshold. All scenarios use today as the reference date
 * since the endpoint takes no query parameters.
 */
class CommissionStructureEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/commission-structure';

    private User $user;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $team = Team::create(['name' => 'Team', 'user_id' => $this->user->id]);
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function callEndpoint(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->getJson(self::ENDPOINT);
    }

    private function makeWorkPeriodCoveringToday(): CommercialWorkPeriod
    {
        return CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: today()->toDateString(),
        );
    }

    private function addTierToWorkPeriod(CommercialWorkPeriod $workPeriod, int $tierLevel, int $caThreshold, int $bonusAmount): CommercialObjectiveTier
    {
        return CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => $tierLevel,
            'ca_threshold' => $caThreshold,
            'bonus_amount' => $bonusAmount,
        ]);
    }

    private function createGlobalTier(int $tierLevel, int $caThreshold, int $bonusAmount): CommercialObjectiveTier
    {
        return CommercialObjectiveTier::create([
            'commercial_work_period_id' => null,
            'is_global' => true,
            'tier_level' => $tierLevel,
            'ca_threshold' => $caThreshold,
            'bonus_amount' => $bonusAmount,
        ]);
    }

    private function makeNewCustomerSetting(int $confirmedBonus, int $prospectBonus): CommercialNewCustomerCommissionSetting
    {
        return CommercialNewCustomerCommissionSetting::create([
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => $confirmedBonus,
            'prospect_customer_bonus' => $prospectBonus,
        ]);
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_user_without_commercial_returns_404(): void
    {
        $userWithoutCommercial = User::factory()->create();

        $this->actingAs($userWithoutCommercial, 'sanctum')
            ->getJson(self::ENDPOINT)
            ->assertStatus(404)
            ->assertJson(['message' => 'Aucun profil commercial lié à cet utilisateur.']);
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    public function test_response_contains_required_top_level_keys(): void
    {
        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonStructure(['ca_tiers', 'new_customer_bonuses', 'mandatory_daily_threshold']);
    }

    // =========================================================================
    // ca_tiers
    // =========================================================================

    public function test_ca_tiers_is_empty_when_no_work_period_covers_today_and_no_global_tiers_exist(): void
    {
        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('ca_tiers', []);
    }

    public function test_ca_tiers_is_empty_when_work_period_has_no_tiers_and_no_global_tiers_exist(): void
    {
        $this->makeWorkPeriodCoveringToday();

        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('ca_tiers', []);
    }

    public function test_ca_tiers_returns_global_tiers_when_no_work_period_covers_today(): void
    {
        $this->createGlobalTier(1, 50_000, 10_000);
        $this->createGlobalTier(2, 100_000, 25_000);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonCount(2, 'ca_tiers');
        $response->assertJsonPath('ca_tiers.0.tier_level', 1);
        $response->assertJsonPath('ca_tiers.0.ca_threshold', 50_000);
        $response->assertJsonPath('ca_tiers.0.bonus_amount', 10_000);
        $response->assertJsonPath('ca_tiers.1.tier_level', 2);
        $response->assertJsonPath('ca_tiers.1.ca_threshold', 100_000);
        $response->assertJsonPath('ca_tiers.1.bonus_amount', 25_000);
    }

    public function test_ca_tiers_returns_global_tiers_when_work_period_has_no_custom_tiers(): void
    {
        $this->makeWorkPeriodCoveringToday();
        $this->createGlobalTier(1, 75_000, 15_000);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonCount(1, 'ca_tiers');
        $response->assertJsonPath('ca_tiers.0.ca_threshold', 75_000);
        $response->assertJsonPath('ca_tiers.0.bonus_amount', 15_000);
    }

    public function test_custom_work_period_tiers_take_precedence_over_global_tiers_in_response(): void
    {
        $workPeriod = $this->makeWorkPeriodCoveringToday();
        // Global tier would give a big bonus — must be ignored when custom tiers exist.
        $this->createGlobalTier(1, 10_000, 999_999);
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 80_000, bonusAmount: 5_000);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonCount(1, 'ca_tiers');
        $response->assertJsonPath('ca_tiers.0.ca_threshold', 80_000);
        $response->assertJsonPath('ca_tiers.0.bonus_amount', 5_000);
    }

    public function test_ca_tiers_contains_all_tiers_from_the_current_work_period(): void
    {
        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 100_000, bonusAmount: 2_000);
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 2, caThreshold: 150_000, bonusAmount: 5_000);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonCount(2, 'ca_tiers');
        $response->assertJsonPath('ca_tiers.0.tier_level', 1);
        $response->assertJsonPath('ca_tiers.0.ca_threshold', 100_000);
        $response->assertJsonPath('ca_tiers.0.bonus_amount', 2_000);
        $response->assertJsonPath('ca_tiers.1.tier_level', 2);
        $response->assertJsonPath('ca_tiers.1.ca_threshold', 150_000);
        $response->assertJsonPath('ca_tiers.1.bonus_amount', 5_000);
    }

    public function test_ca_tiers_are_ordered_by_ca_threshold_ascending(): void
    {
        $workPeriod = $this->makeWorkPeriodCoveringToday();
        // Insert in reverse order to confirm sorting is applied
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 3, caThreshold: 200_000, bonusAmount: 10_000);
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 100_000, bonusAmount: 2_000);
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 2, caThreshold: 150_000, bonusAmount: 5_000);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonPath('ca_tiers.0.ca_threshold', 100_000);
        $response->assertJsonPath('ca_tiers.1.ca_threshold', 150_000);
        $response->assertJsonPath('ca_tiers.2.ca_threshold', 200_000);
    }

    public function test_ca_tiers_description_is_formatted_in_french_with_the_threshold_amount(): void
    {
        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 100_000, bonusAmount: 2_000);

        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('ca_tiers.0.description', 'Atteindre 100 000 F de CA journalier');
    }

    public function test_ca_tiers_does_not_include_tiers_from_past_work_periods(): void
    {
        // Create a past period (last week) with a tier — must not appear in today's response
        $lastWeekMonday = Carbon::today()->startOfWeek()->subWeek();
        $pastPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $lastWeekMonday->toDateString(),
            'period_end_date' => $lastWeekMonday->copy()->endOfWeek()->toDateString(),
        ]);
        $this->addTierToWorkPeriod($pastPeriod, tierLevel: 1, caThreshold: 50_000, bonusAmount: 1_000);

        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('ca_tiers', []);
    }

    // =========================================================================
    // new_customer_bonuses
    // =========================================================================

    public function test_new_customer_bonuses_is_empty_when_no_setting_exists_for_the_commercial(): void
    {
        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('new_customer_bonuses', []);
    }

    public function test_new_customer_bonuses_contains_confirmed_and_prospect_entries_when_setting_exists(): void
    {
        $this->makeNewCustomerSetting(confirmedBonus: 1_000, prospectBonus: 500);

        $response = $this->callEndpoint()->assertStatus(200);

        $response->assertJsonCount(2, 'new_customer_bonuses');
        $response->assertJsonPath('new_customer_bonuses.0.customer_type', 'confirmed');
        $response->assertJsonPath('new_customer_bonuses.0.bonus_amount', 1_000);
        $response->assertJsonPath('new_customer_bonuses.0.description', 'Nouveau client confirmé');
        $response->assertJsonPath('new_customer_bonuses.1.customer_type', 'prospect');
        $response->assertJsonPath('new_customer_bonuses.1.bonus_amount', 500);
        $response->assertJsonPath('new_customer_bonuses.1.description', 'Nouveau prospect');
    }

    // =========================================================================
    // mandatory_daily_threshold
    // =========================================================================

    public function test_mandatory_daily_threshold_is_zero_when_no_active_car_load_exists(): void
    {
        // No car load → DailyCommissionService returns threshold = 0
        $this->callEndpoint()
            ->assertStatus(200)
            ->assertJsonPath('mandatory_daily_threshold', 0);
    }
}
