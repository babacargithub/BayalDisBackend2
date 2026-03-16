<?php

namespace Tests\Feature\Commission;

use App\Data\Commission\CommissionPeriodData;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialWorkPeriod;
use App\Models\Commission;
use App\Models\CommissionPeriodSetting;
use App\Models\User;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommercialWorkPeriodService;
use App\Services\Commission\CommissionRateResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests for CommissionPeriodData consistency validation (startDate <= endDate)
 * and overlap detection on CommissionPeriodSetting and CommercialWorkPeriod.
 */
class CommissionPeriodValidationTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────
    // CommissionPeriodData — constructor consistency validation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_commission_period_data_accepts_valid_period_where_start_is_before_end(): void
    {
        $period = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $this->assertTrue($period->startDate->isBefore($period->endDate));
    }

    public function test_commission_period_data_accepts_single_day_period_where_start_equals_end(): void
    {
        $singleDay = CarbonImmutable::parse('2026-03-05');

        $period = new CommissionPeriodData($singleDay, $singleDay);

        $this->assertTrue($period->startDate->equalTo($period->endDate));
    }

    public function test_commission_period_data_throws_when_start_date_is_after_end_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not be after/');

        new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-07'), // later
            CarbonImmutable::parse('2026-03-02'), // earlier
        );
    }

    public function test_weekly_factory_always_produces_a_valid_period(): void
    {
        $period = CommissionPeriodData::weekly(CarbonImmutable::parse('2026-03-04'));

        $this->assertFalse($period->startDate->isAfter($period->endDate));
        $this->assertEquals('2026-03-02', $period->startDate->toDateString()); // Monday
        $this->assertEquals('2026-03-07', $period->endDate->toDateString());   // Saturday
    }

    public function test_monthly_factory_always_produces_a_valid_period(): void
    {
        $period = CommissionPeriodData::monthly(2026, 3);

        $this->assertFalse($period->startDate->isAfter($period->endDate));
        $this->assertEquals('2026-03-01', $period->startDate->toDateString());
        $this->assertEquals('2026-03-31', $period->endDate->toDateString());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CommissionPeriodSetting — overlap detection
    // ──────────────────────────────────────────────────────────────────────────

    public function test_period_setting_has_no_overlap_when_table_is_empty(): void
    {
        $period = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $this->assertFalse(CommissionPeriodSetting::hasOverlappingPeriod($period));
    }

    public function test_period_setting_has_no_overlap_for_adjacent_non_overlapping_periods(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [],
        ]);

        $nextWeekPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-09'), // Monday of next week
            CarbonImmutable::parse('2026-03-14'),
        );

        $this->assertFalse(CommissionPeriodSetting::hasOverlappingPeriod($nextWeekPeriod));
    }

    public function test_period_setting_detects_overlap_when_new_period_starts_inside_existing_one(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [],
        ]);

        $overlappingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-05'), // starts inside the existing period
            CarbonImmutable::parse('2026-03-12'),
        );

        $this->assertTrue(CommissionPeriodSetting::hasOverlappingPeriod($overlappingPeriod));
    }

    public function test_period_setting_detects_overlap_when_new_period_completely_contains_existing_one(): void
    {
        CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [],
        ]);

        $surroundingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-02-28'), // before existing start
            CarbonImmutable::parse('2026-03-14'), // after existing end
        );

        $this->assertTrue(CommissionPeriodSetting::hasOverlappingPeriod($surroundingPeriod));
    }

    public function test_period_setting_update_does_not_flag_itself_as_overlap(): void
    {
        $setting = CommissionPeriodSetting::create([
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
            'basket_multiplier' => 1.30,
            'required_category_ids' => [],
        ]);

        $samePeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        // Without excludeId it would flag itself.
        $this->assertTrue(CommissionPeriodSetting::hasOverlappingPeriod($samePeriod));

        // With excludeId it should not flag itself.
        $this->assertFalse(CommissionPeriodSetting::hasOverlappingPeriod($samePeriod, $setting->id));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CommercialWorkPeriod — overlap detection
    // ──────────────────────────────────────────────────────────────────────────

    private function makeCommercial(): Commercial
    {
        $user = User::factory()->create();

        return Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => $user->id,
        ]);
    }

    private function makeWorkPeriodForCommercial(
        Commercial $commercial,
        string $startDate,
        string $endDate,
    ): CommercialWorkPeriod {
        return CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => $startDate,
            'period_end_date' => $endDate,
        ]);
    }

    public function test_work_period_has_no_overlap_when_no_periods_exist_for_commercial(): void
    {
        $commercial = $this->makeCommercial();

        $period = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $this->assertFalse(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $period));
    }

    public function test_work_period_has_no_overlap_for_adjacent_non_overlapping_periods(): void
    {
        $commercial = $this->makeCommercial();

        $this->makeWorkPeriodForCommercial($commercial, '2026-03-02', '2026-03-07');

        $nextWeekPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-09'),
            CarbonImmutable::parse('2026-03-14'),
        );

        $this->assertFalse(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $nextWeekPeriod));
    }

    public function test_work_period_detects_overlap_when_new_period_starts_inside_existing_one(): void
    {
        $commercial = $this->makeCommercial();

        $this->makeWorkPeriodForCommercial($commercial, '2026-03-02', '2026-03-07');

        $overlappingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-05'), // starts inside the existing period
            CarbonImmutable::parse('2026-03-12'),
        );

        $this->assertTrue(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $overlappingPeriod));
    }

    public function test_work_period_detects_overlap_when_new_period_completely_contains_existing_one(): void
    {
        $commercial = $this->makeCommercial();

        $this->makeWorkPeriodForCommercial($commercial, '2026-03-02', '2026-03-07');

        $surroundingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-02-28'), // before existing start
            CarbonImmutable::parse('2026-03-14'), // after existing end
        );

        $this->assertTrue(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $surroundingPeriod));
    }

    public function test_work_period_update_does_not_flag_itself_as_overlap(): void
    {
        $commercial = $this->makeCommercial();

        $workPeriod = $this->makeWorkPeriodForCommercial($commercial, '2026-03-02', '2026-03-07');

        $samePeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        // Without excludeId it would flag itself.
        $this->assertTrue(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $samePeriod));

        // With excludeId it should not flag itself.
        $this->assertFalse(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercial->id, $samePeriod, $workPeriod->id));
    }

    public function test_work_period_overlap_is_scoped_per_commercial(): void
    {
        $commercialA = $this->makeCommercial();
        $commercialB = $this->makeCommercial();

        $this->makeWorkPeriodForCommercial($commercialA, '2026-03-02', '2026-03-07');

        $overlappingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-05'),
            CarbonImmutable::parse('2026-03-12'),
        );

        // Commercial B should not be affected by Commercial A's work periods.
        $this->assertFalse(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercialB->id, $overlappingPeriod));
    }

    public function test_work_period_allows_same_dates_for_different_commercials(): void
    {
        $commercialA = $this->makeCommercial();
        $commercialB = $this->makeCommercial();

        $this->makeWorkPeriodForCommercial($commercialA, '2026-03-02', '2026-03-07');

        $samePeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        // Commercial B can have the same period dates as Commercial A.
        $this->assertFalse(CommercialWorkPeriod::hasOverlappingPeriodForCommercial($commercialB->id, $samePeriod));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CommercialObjectiveTier — multiple tiers per work period are allowed
    // ──────────────────────────────────────────────────────────────────────────

    public function test_multiple_objective_tier_levels_can_belong_to_the_same_work_period(): void
    {
        $commercial = $this->makeCommercial();

        $workPeriod = $this->makeWorkPeriodForCommercial($commercial, '2026-03-02', '2026-03-07');

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 10_000,
        ]);

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $workPeriod->id,
            'tier_level' => 2,
            'ca_threshold' => 200_000,
            'bonus_amount' => 25_000,
        ]);

        $this->assertEquals(2, $workPeriod->objectiveTiers()->count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CommissionPeriodService — overlap enforcement
    // ──────────────────────────────────────────────────────────────────────────

    public function test_compute_commission_throws_when_period_overlaps_with_existing_commission(): void
    {
        $service = new CommercialWorkPeriodService(
            new CommissionCalculatorService(new CommissionRateResolverService)
        );

        $commercial = $this->makeCommercial();

        // Seed an existing work period + commission for week 1.
        $existingWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $commercial->id,
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
        ]);

        Commission::create([
            'commercial_work_period_id' => $existingWorkPeriod->id,
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'basket_achieved' => false,
            'is_finalized' => false,
        ]);

        // Try to compute a period that overlaps (starts inside week 1).
        $overlappingPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-05'),
            CarbonImmutable::parse('2026-03-12'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/overlaps with an existing commission period/');

        $service->computeOrRefreshCommissionForPeriod($commercial, $overlappingPeriod);
    }

    public function test_compute_commission_allows_refresh_of_the_exact_same_period(): void
    {
        $service = new CommercialWorkPeriodService(
            new CommissionCalculatorService(new CommissionRateResolverService)
        );

        $commercial = $this->makeCommercial();

        $weeklyPeriod = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        // First compute — no payments, so net_commission = 0.
        $firstCommission = $service->computeOrRefreshCommissionForPeriod($commercial, $weeklyPeriod);

        // Second compute (refresh) for the exact same period should NOT throw.
        $refreshedCommission = $service->computeOrRefreshCommissionForPeriod($commercial, $weeklyPeriod);

        $this->assertEquals($firstCommission->id, $refreshedCommission->id);
    }

    public function test_compute_commission_allows_non_overlapping_periods_for_the_same_commercial(): void
    {
        $service = new CommercialWorkPeriodService(
            new CommissionCalculatorService(new CommissionRateResolverService)
        );

        $commercial = $this->makeCommercial();

        $week1 = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-07'),
        );

        $week2 = new CommissionPeriodData(
            CarbonImmutable::parse('2026-03-09'),
            CarbonImmutable::parse('2026-03-14'),
        );

        $service->computeOrRefreshCommissionForPeriod($commercial, $week1);

        // Week 2 does not overlap with week 1 — must succeed.
        $week2Commission = $service->computeOrRefreshCommissionForPeriod($commercial, $week2);

        $this->assertNotNull($week2Commission->id);
        $this->assertEquals(2, CommercialWorkPeriod::where('commercial_id', $commercial->id)->count());
        $this->assertEquals(2, Commission::whereHas('workPeriod', fn ($q) => $q->where('commercial_id', $commercial->id))->count());
    }
}
