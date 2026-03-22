<?php

namespace Tests\Feature\Commission;

use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for GET /api/salesperson/commission-detail?type=daily|weekly|monthly&date=YYYY-MM-DD.
 *
 * Verifies:
 *  - Authentication required
 *  - 404 when no commercial profile
 *  - 422 when type is missing or invalid
 *  - Response structure: period, summary, days sections
 *  - daily type: returns only the single matching day
 *  - weekly type: returns the Mon–Sun week containing the date
 *  - monthly type: returns the calendar month containing the date
 *  - summary correctly sums all DailyCommission fields across the period
 *  - mandatory_threshold_reached in summary: true only if ALL days with a threshold reached it
 *  - days array includes only days with DailyCommission records (no zero-filling)
 *  - days array is ordered latest-first
 *  - date defaults to today when not provided
 *  - mandatory_daily_sales sourced from SalesInvoice totals
 */
class CommissionDetailEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/commission-detail';

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $workPeriod;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-03-22 12:00:00');

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Detail Test',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Detail Test',
            'phone_number' => '221700000077',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);

        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-01',
            'period_end_date' => '2026-03-31',
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Detail Test',
            'phone_number' => '221700000078',
            'owner_number' => '221700000078',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->product = Product::create([
            'name' => 'Produit Detail Test',
            'price' => 30_000,
            'cost_price' => 15_000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Authentication & validation ─────────────────────────────────────────────

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(401);
    }

    public function test_endpoint_returns_404_when_user_has_no_commercial_profile(): void
    {
        $userWithoutCommercialProfile = User::factory()->create();

        Sanctum::actingAs($userWithoutCommercialProfile);

        $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'Aucun profil commercial lié à cet utilisateur.']);
    }

    public function test_endpoint_returns_422_when_type_is_missing(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?date=2026-03-22')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Le paramètre type doit être daily, weekly ou monthly.']);
    }

    public function test_endpoint_returns_422_when_type_is_invalid(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?type=yearly&date=2026-03-22')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Le paramètre type doit être daily, weekly ou monthly.']);
    }

    // ─── Response structure ───────────────────────────────────────────────────────

    public function test_response_contains_period_summary_and_days_sections(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['type', 'start_date', 'end_date'],
                'summary' => [
                    'base_commission',
                    'basket_bonus',
                    'objective_bonus',
                    'new_confirmed_customers_bonus',
                    'new_prospect_customers_bonus',
                    'total_penalties',
                    'net_commission',
                    'mandatory_daily_sales',
                    'mandatory_daily_threshold',
                    'mandatory_threshold_reached',
                ],
                'days',
            ]);
    }

    // ─── daily type ───────────────────────────────────────────────────────────────

    public function test_daily_type_sets_period_start_and_end_to_the_same_date(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $this->assertSame('daily', $response->json('period.type'));
        $this->assertSame('2026-03-22', $response->json('period.start_date'));
        $this->assertSame('2026-03-22', $response->json('period.end_date'));
    }

    public function test_daily_type_returns_empty_days_when_no_commission_record_exists(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('days'));
    }

    public function test_daily_type_returns_single_day_entry_when_record_exists(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-22',
            'base_commission' => 3_500,
            'basket_bonus' => 500,
            'objective_bonus' => 1_000,
            'new_confirmed_customers_bonus' => 200,
            'new_prospect_customers_bonus' => 100,
            'total_penalties' => 300,
            'net_commission' => 5_000,
            'mandatory_daily_threshold' => 57_000,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => true,
            'basket_multiplier_applied' => 1.30,
            'achieved_tier_level' => 1,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('days'));

        $dayEntry = $response->json('days.0');
        $this->assertSame('2026-03-22', $dayEntry['date']);
        $this->assertSame(3_500, $dayEntry['base_commission']);
        $this->assertSame(500, $dayEntry['basket_bonus']);
        $this->assertSame(1_000, $dayEntry['objective_bonus']);
        $this->assertSame(200, $dayEntry['new_confirmed_customers_bonus']);
        $this->assertSame(100, $dayEntry['new_prospect_customers_bonus']);
        $this->assertSame(300, $dayEntry['total_penalties']);
        $this->assertSame(5_000, $dayEntry['net_commission']);
        $this->assertSame(57_000, $dayEntry['mandatory_daily_threshold']);
        $this->assertTrue($dayEntry['mandatory_threshold_reached']);
    }

    public function test_daily_type_summary_matches_single_day_values(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-22',
            'base_commission' => 3_500,
            'basket_bonus' => 500,
            'objective_bonus' => 1_000,
            'new_confirmed_customers_bonus' => 200,
            'new_prospect_customers_bonus' => 100,
            'total_penalties' => 300,
            'net_commission' => 5_000,
            'mandatory_daily_threshold' => 57_000,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => true,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => 1,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertSame(3_500, $summary['base_commission']);
        $this->assertSame(500, $summary['basket_bonus']);
        $this->assertSame(1_000, $summary['objective_bonus']);
        $this->assertSame(200, $summary['new_confirmed_customers_bonus']);
        $this->assertSame(100, $summary['new_prospect_customers_bonus']);
        $this->assertSame(300, $summary['total_penalties']);
        $this->assertSame(5_000, $summary['net_commission']);
        $this->assertSame(57_000, $summary['mandatory_daily_threshold']);
        $this->assertTrue($summary['mandatory_threshold_reached']);
    }

    // ─── weekly type ──────────────────────────────────────────────────────────────

    public function test_weekly_type_sets_period_to_the_mon_sun_week_containing_the_date(): void
    {
        // 2026-03-22 is a Sunday → week is Mon Mar 16 – Sun Mar 22.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        $this->assertSame('weekly', $response->json('period.type'));
        $this->assertSame('2026-03-16', $response->json('period.start_date'));
        $this->assertSame('2026-03-22', $response->json('period.end_date'));
    }

    public function test_weekly_type_returns_only_days_with_commission_records(): void
    {
        // Create records on Mon and Wed of the week; Tue, Thu–Sun have no records.
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-16',
            'base_commission' => 1_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-18',
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 2_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('days'));
    }

    public function test_weekly_type_days_are_ordered_latest_first(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-16',
            'base_commission' => 1_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-18',
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 2_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        $dates = collect($response->json('days'))->pluck('date');
        $this->assertSame('2026-03-18', $dates->first());
        $this->assertSame('2026-03-16', $dates->last());
    }

    public function test_weekly_type_summary_sums_all_fields_across_the_week(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-16',
            'base_commission' => 1_000,
            'basket_bonus' => 200,
            'objective_bonus' => 500,
            'new_confirmed_customers_bonus' => 100,
            'new_prospect_customers_bonus' => 50,
            'total_penalties' => 150,
            'net_commission' => 1_700,
            'mandatory_daily_threshold' => 40_000,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-18',
            'base_commission' => 2_500,
            'basket_bonus' => 300,
            'objective_bonus' => 1_000,
            'new_confirmed_customers_bonus' => 200,
            'new_prospect_customers_bonus' => 100,
            'total_penalties' => 250,
            'net_commission' => 3_850,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertSame(1_000 + 2_500, $summary['base_commission']);
        $this->assertSame(200 + 300, $summary['basket_bonus']);
        $this->assertSame(500 + 1_000, $summary['objective_bonus']);
        $this->assertSame(100 + 200, $summary['new_confirmed_customers_bonus']);
        $this->assertSame(50 + 100, $summary['new_prospect_customers_bonus']);
        $this->assertSame(150 + 250, $summary['total_penalties']);
        $this->assertSame(1_700 + 3_850, $summary['net_commission']);
        $this->assertSame(40_000 + 50_000, $summary['mandatory_daily_threshold']);
        $this->assertTrue($summary['mandatory_threshold_reached']);
    }

    // ─── monthly type ─────────────────────────────────────────────────────────────

    public function test_monthly_type_sets_period_to_the_full_calendar_month(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=monthly&date=2026-03-22')
            ->assertStatus(200);

        $this->assertSame('monthly', $response->json('period.type'));
        $this->assertSame('2026-03-01', $response->json('period.start_date'));
        $this->assertSame('2026-03-31', $response->json('period.end_date'));
    }

    public function test_monthly_type_excludes_records_outside_the_month(): void
    {
        // Record in February — must not appear in a March monthly query.
        $februaryWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-02-01',
            'period_end_date' => '2026-02-28',
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $februaryWorkPeriod->id,
            'work_day' => '2026-02-15',
            'base_commission' => 9_999,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 9_999,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-05',
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

        $response = $this->getJson(self::ENDPOINT.'?type=monthly&date=2026-03-22')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('days'));
        $this->assertSame('2026-03-05', $response->json('days.0.date'));
        $this->assertSame(1_000, $response->json('summary.net_commission'));
    }

    // ─── mandatory_threshold_reached in summary ───────────────────────────────────

    public function test_summary_mandatory_threshold_reached_is_false_when_any_day_missed_it(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-16',
            'base_commission' => 1_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_000,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-18',
            'base_commission' => 500,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 500,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => false, // did NOT reach it
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        $this->assertFalse($response->json('summary.mandatory_threshold_reached'));
    }

    public function test_summary_mandatory_threshold_reached_is_true_when_no_day_has_a_threshold(): void
    {
        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-22',
            'base_commission' => 1_000,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_000,
            'mandatory_daily_threshold' => 0,
            'mandatory_threshold_reached' => true,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $this->assertTrue($response->json('summary.mandatory_threshold_reached'));
    }

    // ─── mandatory_daily_sales from SalesInvoice ─────────────────────────────────

    public function test_mandatory_daily_sales_in_summary_reflects_sales_invoice_totals(): void
    {
        Carbon::setTestNow('2026-03-22 10:00:00');

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Issued,
        ]);

        Vente::insert([
            [
                'product_id' => $this->product->id,
                'customer_id' => $this->customer->id,
                'sales_invoice_id' => $invoice->id,
                'price' => 30_000,
                'quantity' => 2,
                'profit' => 10_000,
                'paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $invoice->recalculateStoredTotals();

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily&date=2026-03-22')
            ->assertStatus(200);

        $this->assertSame(60_000, $response->json('summary.mandatory_daily_sales'));
    }

    public function test_mandatory_daily_sales_per_day_entry_reflects_that_days_invoices(): void
    {
        // Mar 22: 60k invoice, Mar 18: 20k invoice.
        Carbon::setTestNow('2026-03-22 10:00:00');

        $invoice22 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Issued,
        ]);
        Vente::insert([[
            'product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'sales_invoice_id' => $invoice22->id,
            'price' => 30_000,
            'quantity' => 2,
            'profit' => 10_000,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        $invoice22->recalculateStoredTotals();

        Carbon::setTestNow('2026-03-18 10:00:00');

        $invoice18 = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Issued,
        ]);
        Vente::insert([[
            'product_id' => $this->product->id,
            'customer_id' => $this->customer->id,
            'sales_invoice_id' => $invoice18->id,
            'price' => 20_000,
            'quantity' => 1,
            'profit' => 5_000,
            'paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
        $invoice18->recalculateStoredTotals();

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-22',
            'base_commission' => 1_200,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 1_200,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => '2026-03-18',
            'base_commission' => 400,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 400,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=weekly&date=2026-03-22')
            ->assertStatus(200);

        // Summary: 60k + 20k = 80k
        $this->assertSame(80_000, $response->json('summary.mandatory_daily_sales'));

        // Per-day entries (latest first: Mar 22, Mar 18)
        $days = collect($response->json('days'));
        $this->assertSame(60_000, $days->firstWhere('date', '2026-03-22')['mandatory_daily_sales']);
        $this->assertSame(20_000, $days->firstWhere('date', '2026-03-18')['mandatory_daily_sales']);
    }

    // ─── Default date ─────────────────────────────────────────────────────────────

    public function test_date_defaults_to_today_when_not_provided(): void
    {
        // Today is 2026-03-22.
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?type=daily')
            ->assertStatus(200);

        $this->assertSame('2026-03-22', $response->json('period.start_date'));
        $this->assertSame('2026-03-22', $response->json('period.end_date'));
    }
}
