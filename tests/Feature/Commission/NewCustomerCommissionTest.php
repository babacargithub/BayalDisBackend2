<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialNewCustomerCommissionSetting;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\User;
use App\Services\Abc\AbcVehicleCostService;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use App\Services\Commission\DailyCommissionService;
use App\Services\SalesInvoiceStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Tests for the new-customer commission bonus feature.
 *
 * Covers:
 *  - computeNewCustomerBonusesForDay() correctness for confirmed and prospect customers
 *  - Boundary conditions: customer created before/after the work day
 *  - Customer belonging to a different commercial is not counted
 *  - Graceful fallback when no CommercialNewCustomerCommissionSetting exists
 *  - Mix of confirmed + prospect customers on the same day
 *  - net_commission on DailyCommission correctly includes both new customer bonuses
 *  - Period-level aggregation (totalNewConfirmedCustomersBonus / totalNewProspectCustomersBonus)
 *  - recalculateDailyCommissionForWorkDay persists new_confirmed_customers_bonus and new_prospect_customers_bonus
 */
class NewCustomerCommissionTest extends TestCase
{
    use RefreshDatabase;

    private DailyCommissionService $service;

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $workPeriod;

    private string $periodStart = '2026-03-02';

    private string $periodEnd = '2026-03-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DailyCommissionService(
            new CommissionCalculatorService(new CommissionRateResolverService),
            new AbcVehicleCostService,
            new SalesInvoiceStatsService(new CommissionRateResolverService),
        );

        $this->user = User::factory()->create();

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);

        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);
    }

    private function createSetting(int $confirmedBonus, int $prospectBonus): CommercialNewCustomerCommissionSetting
    {
        return CommercialNewCustomerCommissionSetting::create([
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => $confirmedBonus,
            'prospect_customer_bonus' => $prospectBonus,
        ]);
    }

    private function createCustomer(bool $isProspect, string $createdAt, ?int $commercialId = null): Customer
    {
        $customer = Customer::create([
            'name' => 'Client '.uniqid(),
            'phone_number' => '2217'.rand(10000000, 99999999),
            'owner_number' => '2217'.rand(10000000, 99999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercialId ?? $this->commercial->id,
            'is_prospect' => $isProspect,
        ]);

        $customer->created_at = $createdAt;
        $customer->save();

        return $customer;
    }

    // ─── computeNewCustomerBonusesForDay — unit-level tests ───────────────────

    public function test_confirmed_customer_created_on_work_day_yields_correct_bonus(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(500, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_prospect_customer_created_on_work_day_yields_correct_bonus(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 14:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(200, $bonuses['prospect']);
    }

    public function test_multiple_confirmed_customers_on_same_day_multiplies_bonus(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 09:00:00');
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 11:00:00');
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 15:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(1_500, $bonuses['confirmed']); // 3 × 500
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_mix_of_confirmed_and_prospect_customers_on_same_day_are_counted_independently(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 09:00:00');
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 11:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(1_000, $bonuses['confirmed']); // 2 × 500
        $this->assertSame(200, $bonuses['prospect']);    // 1 × 200
    }

    public function test_customer_created_before_work_day_is_not_counted(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-02 23:59:59'); // day before
        $this->createCustomer(isProspect: true, createdAt: '2026-03-02 20:00:00');  // day before

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_customer_created_after_work_day_is_not_counted(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-04 00:00:01'); // day after
        $this->createCustomer(isProspect: true, createdAt: '2026-03-04 08:00:00');  // day after

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_customer_belonging_to_a_different_commercial_is_not_counted(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);

        $otherUser = User::factory()->create();
        $otherCommercial = Commercial::create([
            'name' => 'Autre Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $otherUser->id,
        ]);

        // Customer belongs to otherCommercial, NOT to $this->commercial
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00', commercialId: $otherCommercial->id);

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_no_exception_and_both_bonuses_are_zero_when_no_setting_exists(): void
    {
        // No CommercialNewCustomerCommissionSetting created for the commercial
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    public function test_zero_bonus_per_customer_returns_zero_even_when_customers_exist(): void
    {
        $this->createSetting(confirmedBonus: 0, prospectBonus: 0);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 11:00:00');

        $bonuses = $this->service->computeNewCustomerBonusesForDay($this->workPeriod, '2026-03-03');

        $this->assertSame(0, $bonuses['confirmed']);
        $this->assertSame(0, $bonuses['prospect']);
    }

    // ─── recalculateDailyCommissionForWorkDay persists bonuses ────────────────

    /**
     * @throws Throwable
     */
    public function test_daily_commission_record_stores_new_customer_bonuses_after_recalculation(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 11:00:00');

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            '2026-03-03',
        );

        $this->assertSame(500, $dailyCommission->new_confirmed_customers_bonus);
        $this->assertSame(200, $dailyCommission->new_prospect_customers_bonus);
    }

    /**
     * @throws Throwable
     */
    public function test_new_customer_bonuses_are_included_in_net_commission(): void
    {
        // No payments → base_commission = 0
        // new_confirmed_customers_bonus = 1 × 500 = 500
        // new_prospect_customers_bonus = 2 × 200 = 400
        // net_commission = 0 + 500 + 400 = 900
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 09:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 10:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-03 11:00:00');

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            '2026-03-03',
        );

        $this->assertSame(500, $dailyCommission->new_confirmed_customers_bonus);
        $this->assertSame(400, $dailyCommission->new_prospect_customers_bonus);
        $this->assertSame(900, $dailyCommission->net_commission);
    }

    /**
     * @throws Throwable
     */
    public function test_net_commission_is_never_negative_even_with_large_penalties(): void
    {
        // Large penalty, no payments, no new customers → net = max(0, ...) = 0
        $this->workPeriod->penalties()->create([
            'work_day' => '2026-03-03',
            'amount' => 99_999,
            'reason' => 'Test large penalty',
        ]);

        $this->createSetting(confirmedBonus: 100, prospectBonus: 50);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00'); // +100

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            '2026-03-03',
        );

        $this->assertSame(0, $dailyCommission->net_commission);
    }

    public function test_daily_commission_stores_zeros_when_no_setting_exists(): void
    {
        // No setting → bonuses should be 0 and not throw
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');

        $dailyCommission = $this->service->recalculateDailyCommissionForWorkDay(
            $this->commercial,
            $this->workPeriod,
            '2026-03-03',
        );

        $this->assertSame(0, $dailyCommission->new_confirmed_customers_bonus);
        $this->assertSame(0, $dailyCommission->new_prospect_customers_bonus);
    }

    // ─── Period-level aggregation ─────────────────────────────────────────────

    /**
     * @throws Throwable
     */
    public function test_period_summary_aggregates_new_customer_bonuses_across_multiple_days(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);

        // Day 1: 1 confirmed → 500
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');
        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->workPeriod, '2026-03-03');

        // Day 2: 2 confirmed + 1 prospect → 1_000 + 200 = 1_200
        $this->createCustomer(isProspect: false, createdAt: '2026-03-04 09:00:00');
        $this->createCustomer(isProspect: false, createdAt: '2026-03-04 10:00:00');
        $this->createCustomer(isProspect: true, createdAt: '2026-03-04 11:00:00');
        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->workPeriod, '2026-03-04');

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $this->assertSame(1_500, $summary->totalNewConfirmedCustomersBonus); // 500 + 1_000
        $this->assertSame(200, $summary->totalNewProspectCustomersBonus);    // 0 + 200
        $this->assertSame(3, $summary->totalNewConfirmedCustomersCount);     // 1 + 2
        $this->assertSame(1, $summary->totalNewProspectCustomersCount);      // 0 + 1
    }

    public function test_period_summary_new_customer_totals_are_zero_with_no_customers(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $this->assertSame(0, $summary->totalNewConfirmedCustomersBonus);
        $this->assertSame(0, $summary->totalNewProspectCustomersBonus);
        $this->assertSame(0, $summary->totalNewConfirmedCustomersCount);
        $this->assertSame(0, $summary->totalNewProspectCustomersCount);
    }

    public function test_period_summary_new_customer_counts_are_per_day_in_days_array(): void
    {
        $this->createSetting(confirmedBonus: 500, prospectBonus: 200);
        $this->createCustomer(isProspect: false, createdAt: '2026-03-03 10:00:00');
        $this->service->recalculateDailyCommissionForWorkDay($this->commercial, $this->workPeriod, '2026-03-03');

        $summary = $this->service->getCommissionSummaryForDateRange(
            $this->commercial,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
        );

        // Day index 1 = 2026-03-03 (Mon=0, Tue=1)
        $tuesdayEntry = $summary->days[1];
        $this->assertSame('2026-03-03', $tuesdayEntry['date']);
        $this->assertSame(1, $tuesdayEntry['new_confirmed_customers_count']);
        $this->assertSame(0, $tuesdayEntry['new_prospect_customers_count']);
        $this->assertSame(500, $tuesdayEntry['new_confirmed_customers_bonus']);
        $this->assertSame(0, $tuesdayEntry['new_prospect_customers_bonus']);

        // Day index 0 = 2026-03-02 (Monday — no customers)
        $mondayEntry = $summary->days[0];
        $this->assertSame(0, $mondayEntry['new_confirmed_customers_count']);
        $this->assertSame(0, $mondayEntry['new_prospect_customers_count']);
        $this->assertSame(0, $mondayEntry['new_confirmed_customers_bonus']);
        $this->assertSame(0, $mondayEntry['new_prospect_customers_bonus']);
    }

    // ─── Settings model — upsert via HTTP ─────────────────────────────────────

    public function test_upsert_new_customer_commission_setting_creates_record_for_commercial(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('commissions.new-customer-settings.upsert'), [
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => 750,
            'prospect_customer_bonus' => 300,
        ])->assertRedirect();

        $this->assertDatabaseHas('commercial_new_customer_commission_settings', [
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => 750,
            'prospect_customer_bonus' => 300,
        ]);
    }

    public function test_upsert_new_customer_commission_setting_updates_existing_record(): void
    {
        $this->actingAs(User::factory()->create());

        $this->createSetting(confirmedBonus: 100, prospectBonus: 50);

        $this->post(route('commissions.new-customer-settings.upsert'), [
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => 750,
            'prospect_customer_bonus' => 300,
        ])->assertRedirect();

        $this->assertDatabaseHas('commercial_new_customer_commission_settings', [
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => 750,
            'prospect_customer_bonus' => 300,
        ]);

        $this->assertDatabaseCount('commercial_new_customer_commission_settings', 1);
    }

    public function test_upsert_validates_that_commercial_id_must_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('commissions.new-customer-settings.upsert'), [
            'commercial_id' => 99999,
            'confirmed_customer_bonus' => 500,
            'prospect_customer_bonus' => 200,
        ])->assertSessionHasErrors('commercial_id');
    }

    public function test_upsert_validates_that_bonuses_must_be_non_negative_integers(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('commissions.new-customer-settings.upsert'), [
            'commercial_id' => $this->commercial->id,
            'confirmed_customer_bonus' => -1,
            'prospect_customer_bonus' => -50,
        ])->assertSessionHasErrors(['confirmed_customer_bonus', 'prospect_customer_bonus']);
    }
}
