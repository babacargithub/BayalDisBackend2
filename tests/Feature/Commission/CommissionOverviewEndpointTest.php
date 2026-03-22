<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\DailyCommission;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for GET /api/salesperson/commission-overview.
 *
 * Verifies:
 *  - Endpoint requires authentication
 *  - Returns 404 when authenticated user has no commercial profile
 *  - Response structure: daily, weekly, monthly sections
 *  - Daily section covers days from the 1st of the month up to today (no future days)
 *  - Weekly section includes all Mon–Sun weeks from the 1st up to the current week
 *    (future weeks are excluded; weeks bleeding into the previous month are included)
 *  - Monthly section includes all months from the earliest commission to now
 *  - All three sections are ordered latest-first
 *  - Commission values and penalties are correctly aggregated per section
 */
class CommissionOverviewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/commission-overview';

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $workPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to March 15, 2026 for all tests in this class.
        Carbon::setTestNow('2026-03-15 12:00:00');

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Overview Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Overview Test',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);

        // Work period spanning Feb–March so we can test cross-month scenarios.
        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-02-01',
            'period_end_date' => '2026-03-31',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Authentication & authorisation ──────────────────────────────────────────

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertStatus(401);
    }

    public function test_endpoint_returns_404_when_user_has_no_commercial_profile(): void
    {
        $userWithoutCommercialProfile = User::factory()->create();

        Sanctum::actingAs($userWithoutCommercialProfile);

        $this->getJson(self::ENDPOINT)
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Aucun profil commercial lié à cet utilisateur.']);
    }

    // ─── Response structure ───────────────────────────────────────────────────────

    public function test_response_contains_daily_weekly_and_monthly_sections(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonStructure([
                'daily',
                'weekly',
                'monthly',
            ]);
    }

    public function test_daily_section_has_correct_structure_per_entry(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $firstEntry = $response->json('daily.0');

        $this->assertArrayHasKey('date', $firstEntry);
        $this->assertArrayHasKey('commissions_earned', $firstEntry);
        $this->assertArrayHasKey('total_penalties', $firstEntry);
    }

    public function test_weekly_section_has_correct_structure_per_entry(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $firstEntry = $response->json('weekly.0');

        $this->assertArrayHasKey('start_date', $firstEntry);
        $this->assertArrayHasKey('end_date', $firstEntry);
        $this->assertArrayHasKey('commissions_earned', $firstEntry);
        $this->assertArrayHasKey('total_penalties', $firstEntry);
    }

    public function test_monthly_section_has_correct_structure_per_entry(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $firstEntry = $response->json('monthly.0');

        $this->assertArrayHasKey('year', $firstEntry);
        $this->assertArrayHasKey('month', $firstEntry);
        $this->assertArrayHasKey('commissions_earned', $firstEntry);
        $this->assertArrayHasKey('total_penalties', $firstEntry);
    }

    // ─── Daily section ────────────────────────────────────────────────────────────

    public function test_daily_section_contains_entries_only_up_to_today(): void
    {
        // Today is March 15, so the daily section must have exactly 15 entries (Mar 1–15).
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $this->assertCount(15, $response->json('daily'));
    }

    public function test_daily_section_is_ordered_latest_first(): void
    {
        // Today (index 0) should be Mar 15; the oldest entry should be Mar 1.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $dailyDates = collect($response->json('daily'))->pluck('date');

        $this->assertSame('2026-03-15', $dailyDates->first());
        $this->assertSame('2026-03-01', $dailyDates->last());
    }

    public function test_daily_section_returns_zero_values_when_no_commissions_exist(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $allZero = collect($response->json('daily'))->every(
            fn (array $entry) => $entry['commissions_earned'] === 0 && $entry['total_penalties'] === 0
        );

        $this->assertTrue($allZero, 'All daily entries should be zero when no DailyCommission records exist');
    }

    public function test_daily_section_shows_correct_commissions_and_penalties_for_a_day_with_records(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-10',
            'base_commission' => 3_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 500,
            'net_commission' => 2_500,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $march10Entry = collect($response->json('daily'))->firstWhere('date', '2026-03-10');

        $this->assertSame(2_500, $march10Entry['commissions_earned']);
        $this->assertSame(500, $march10Entry['total_penalties']);
    }

    public function test_daily_section_does_not_include_days_after_today(): void
    {
        // Today is Mar 15; Mar 16 and beyond must not appear.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $futureDates = collect($response->json('daily'))
            ->pluck('date')
            ->filter(fn (string $date) => $date > '2026-03-15');

        $this->assertTrue($futureDates->isEmpty(), 'No future dates should be present in the daily section');
    }

    // ─── Weekly section ───────────────────────────────────────────────────────────

    public function test_weekly_section_contains_three_weeks_when_today_is_march_15(): void
    {
        // March 1, 2026 is a Sunday → first week is Mon Feb 23 – Sun Mar 1.
        // March 15 is a Sunday → current week is Mon Mar 9 – Sun Mar 15.
        // Weeks: Feb 23–Mar 1, Mar 2–8, Mar 9–15 → 3 weeks.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $this->assertCount(3, $response->json('weekly'));
    }

    public function test_weekly_section_is_ordered_latest_first(): void
    {
        // Current week (Mar 9–15) should be at index 0.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $firstWeek = $response->json('weekly.0');
        $lastWeek = $response->json('weekly.2');

        $this->assertSame('2026-03-09', $firstWeek['start_date']);
        $this->assertSame('2026-03-15', $firstWeek['end_date']);

        $this->assertSame('2026-02-23', $lastWeek['start_date']);
        $this->assertSame('2026-03-01', $lastWeek['end_date']);
    }

    public function test_weekly_section_first_week_starts_on_the_monday_before_march_1(): void
    {
        // March 1, 2026 is a Sunday.  The containing week starts Mon Feb 23.
        // In latest-first order this is the last entry.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $weeks = $response->json('weekly');
        $lastWeek = end($weeks);

        $this->assertSame('2026-02-23', $lastWeek['start_date']);
        $this->assertSame('2026-03-01', $lastWeek['end_date']);
    }

    public function test_weekly_section_does_not_include_weeks_after_the_current_week(): void
    {
        // Today is Mar 15 (last day of the Mar 9–15 week).
        // Mar 16–22, Mar 23–29, etc. must not appear.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $futureWeeks = collect($response->json('weekly'))
            ->filter(fn (array $week) => $week['start_date'] > '2026-03-15');

        $this->assertTrue($futureWeeks->isEmpty(), 'No future weeks should appear in the weekly section');
    }

    public function test_weekly_section_includes_commission_from_previous_month_day_in_overlapping_week(): void
    {
        // Feb 27 falls in the week Feb 23–Mar 1, which overlaps March.
        // Its commission must appear in that week's total (last entry in latest-first order).
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-02-27',
            'base_commission' => 1_500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 100,
            'net_commission' => 1_400,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $weeks = $response->json('weekly');
        $firstMonthOverlapWeek = end($weeks); // Feb 23–Mar 1

        $this->assertSame('2026-02-23', $firstMonthOverlapWeek['start_date']);
        $this->assertSame(1_400, $firstMonthOverlapWeek['commissions_earned']);
        $this->assertSame(100, $firstMonthOverlapWeek['total_penalties']);
    }

    public function test_weekly_section_sums_multiple_days_within_the_same_week(): void
    {
        // March 3 (Tuesday) and March 4 (Wednesday) are in the week Mar 2–8.
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-03',
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 200,
            'net_commission' => 1_800,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-04',
            'base_commission' => 3_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 300,
            'net_commission' => 2_700,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        // In latest-first order: index 0 = Mar 9–15, index 1 = Mar 2–8.
        $march2To8Week = $response->json('weekly.1');

        $this->assertSame('2026-03-02', $march2To8Week['start_date']);
        $this->assertSame('2026-03-08', $march2To8Week['end_date']);
        $this->assertSame(1_800 + 2_700, $march2To8Week['commissions_earned']);
        $this->assertSame(200 + 300, $march2To8Week['total_penalties']);
    }

    // ─── Monthly section ──────────────────────────────────────────────────────────

    public function test_monthly_section_returns_only_current_month_when_no_history_exists(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $monthlyEntries = $response->json('monthly');

        $this->assertCount(1, $monthlyEntries);
        $this->assertSame(2026, $monthlyEntries[0]['year']);
        $this->assertSame(3, $monthlyEntries[0]['month']);
    }

    public function test_monthly_section_is_ordered_latest_first(): void
    {
        // Commission in February — current month (March) must be at index 0.
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-02-15',
            'base_commission' => 4_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 4_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $monthlyEntries = $response->json('monthly');

        $this->assertCount(2, $monthlyEntries);

        // Index 0 = current month (March).
        $this->assertSame(2026, $monthlyEntries[0]['year']);
        $this->assertSame(3, $monthlyEntries[0]['month']);

        // Index 1 = earlier month (February).
        $this->assertSame(2026, $monthlyEntries[1]['year']);
        $this->assertSame(2, $monthlyEntries[1]['month']);
    }

    public function test_monthly_section_correctly_aggregates_multiple_days_within_a_month(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-05',
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 200,
            'net_commission' => 1_800,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-10',
            'base_commission' => 5_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 400,
            'net_commission' => 4_600,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        // March is the only (and first) monthly entry.
        $marchEntry = $response->json('monthly.0');

        $this->assertSame(2026, $marchEntry['year']);
        $this->assertSame(1_800 + 4_600, $marchEntry['commissions_earned']);
        $this->assertSame(200 + 400, $marchEntry['total_penalties']);
    }

    public function test_monthly_entries_for_months_with_no_commissions_show_zero_values(): void
    {
        // Only January has a record; February and March should show zeros.
        $januaryWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-01-01',
            'period_end_date' => '2026-01-31',
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $januaryWorkPeriod->id,
            'work_day' => '2026-01-15',
            'base_commission' => 1_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)->assertStatus(200);

        $monthlyEntries = collect($response->json('monthly'));
        $this->assertCount(3, $monthlyEntries); // Jan, Feb, Mar (latest-first: Mar, Feb, Jan)

        $februaryEntry = $monthlyEntries->firstWhere('month', 2);
        $this->assertSame(0, $februaryEntry['commissions_earned']);
        $this->assertSame(0, $februaryEntry['total_penalties']);
    }
}
