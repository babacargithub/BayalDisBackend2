<?php

namespace Tests\Feature\ActivityReport;

use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HTTP endpoint tests for GET /api/salesperson/activity_report.
 *
 * Covers authentication, input validation, response shape, and correct values
 * for all DTO fields returned in the JSON payload.
 */
class ActivityReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/activity_report';

    private User $user;

    private Commercial $commercial;

    private Customer $customer;

    private Product $product;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = Carbon::today();
        $this->user = User::factory()->create();
        $team = Team::create(['name' => 'Team', 'user_id' => $this->user->id]);
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);
        $this->customer = Customer::create([
            'name' => 'Customer Test',
            'address' => 'Address',
            'phone_number' => '221700000002',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
            'is_prospect' => false,
        ]);
        $this->product = Product::create([
            'name' => 'Product Test',
            'price' => 1000,
            'cost_price' => 500,
            'base_quantity' => 1,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeInvoice(int $price, int $quantity, ?Carbon $createdAt = null): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        $effectiveDate = $createdAt ?? $this->today;
        DB::table('sales_invoices')
            ->where('id', $invoice->id)
            ->update(['created_at' => $effectiveDate->toDateTimeString()]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => ($price - 500) * $quantity,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh();
    }

    private function makePayment(SalesInvoice $invoice, int $amount, string $paymentMethod, ?Carbon $collectedAt = null): Payment
    {
        $payment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'user_id' => $this->user->id,
        ]);

        DB::table('payments')
            ->where('id', $payment->id)
            ->update(['created_at' => ($collectedAt ?? $this->today)->toDateTimeString()]);

        return $payment->fresh();
    }

    private function callEndpoint(string $date, string $type = 'daily'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->getJson(self::ENDPOINT.'?date='.$date.'&type='.$type);
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson(self::ENDPOINT.'?date='.$this->today->toDateString().'&type=daily');

        $response->assertStatus(401);
    }

    public function test_user_without_commercial_returns_404(): void
    {
        $userWithoutCommercial = User::factory()->create();

        $response = $this->actingAs($userWithoutCommercial, 'sanctum')
            ->getJson(self::ENDPOINT.'?date='.$this->today->toDateString().'&type=daily');

        $response->assertStatus(404)
            ->assertJson(['message' => 'Commercial not found']);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_missing_date_parameter_returns_422(): void
    {
        $response = $this->callEndpoint('', 'daily');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_invalid_type_parameter_returns_422(): void
    {
        $response = $this->callEndpoint($this->today->toDateString(), 'monthly');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    public function test_response_contains_period_and_data_keys(): void
    {
        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'period' => ['start', 'end', 'type'],
                'data' => [
                    'total_sales',
                    'total_payments',
                    'new_confirmed_customers_count',
                    'new_prospect_customers_count',
                    'total_unpaid_amount',
                    'total_payments_wave',
                    'total_payments_om',
                    'total_payments_cash',
                ],
            ]);
    }

    public function test_period_reflects_the_requested_daily_date(): void
    {
        $date = $this->today->toDateString();

        $response = $this->callEndpoint($date);

        $response->assertStatus(200)
            ->assertJsonPath('period.type', 'daily')
            ->assertJsonPath('period.start', $this->today->copy()->startOfDay()->toDateTimeString())
            ->assertJsonPath('period.end', $this->today->copy()->endOfDay()->toDateTimeString());
    }

    // =========================================================================
    // totalSales
    // =========================================================================

    public function test_total_sales_reflects_sum_of_invoice_total_amounts_for_the_day(): void
    {
        $this->makeInvoice(price: 2000, quantity: 3); // 6000
        $this->makeInvoice(price: 1000, quantity: 2); // 2000

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_sales', 8000);
    }

    public function test_total_sales_is_zero_on_a_day_with_no_invoices(): void
    {
        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_sales', 0);
    }

    // =========================================================================
    // totalPayments
    // =========================================================================

    public function test_total_payments_reflects_cash_collected_during_the_day(): void
    {
        $invoice = $this->makeInvoice(price: 5000, quantity: 1);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_CASH);
        $this->makePayment($invoice, 1500, Vente::PAYMENT_METHOD_WAVE);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_payments', 3500);
    }

    public function test_total_payments_is_zero_when_no_payment_is_made_today(): void
    {
        $this->makeInvoice(price: 3000, quantity: 1);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_payments', 0);
    }

    // =========================================================================
    // totalUnpaidAmount
    // =========================================================================

    public function test_total_unpaid_amount_is_the_outstanding_balance_on_period_invoices(): void
    {
        $invoice = $this->makeInvoice(price: 4000, quantity: 1);
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_unpaid_amount', 3000);
    }

    // =========================================================================
    // Payment method breakdown
    // =========================================================================

    public function test_payment_method_totals_are_correctly_split_in_response(): void
    {
        $invoice = $this->makeInvoice(price: 6000, quantity: 1);
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_WAVE);
        $this->makePayment($invoice, 3000, Vente::PAYMENT_METHOD_OM);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.total_payments_cash', 1000)
            ->assertJsonPath('data.total_payments_wave', 2000)
            ->assertJsonPath('data.total_payments_om', 3000)
            ->assertJsonPath('data.total_payments', 6000);
    }

    // =========================================================================
    // Customer counts
    // =========================================================================

    public function test_new_confirmed_customers_count_reflects_non_prospects_created_today(): void
    {
        Customer::where('commercial_id', $this->commercial->id)->delete();

        Customer::create([
            'name' => 'Confirmed A', 'address' => 'Addr',
            'phone_number' => '221700000010', 'owner_number' => '221700000010',
            'gps_coordinates' => '0,0', 'commercial_id' => $this->commercial->id,
            'is_prospect' => false,
        ]);
        Customer::create([
            'name' => 'Prospect B', 'address' => 'Addr',
            'phone_number' => '221700000011', 'owner_number' => '221700000011',
            'gps_coordinates' => '0,0', 'commercial_id' => $this->commercial->id,
            'is_prospect' => true,
        ]);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.new_confirmed_customers_count', 1)
            ->assertJsonPath('data.new_prospect_customers_count', 1);
    }

    // =========================================================================
    // Weekly period
    // =========================================================================

    public function test_weekly_type_aggregates_invoices_and_payments_across_the_full_week(): void
    {
        $monday = $this->today->copy()->startOfWeek();

        $invoice1 = $this->makeInvoice(price: 3000, quantity: 1, createdAt: $monday);
        $invoice2 = $this->makeInvoice(price: 2000, quantity: 1, createdAt: $monday->copy()->addDays(4));

        $this->makePayment($invoice1, 1500, Vente::PAYMENT_METHOD_CASH, $monday);
        $this->makePayment($invoice2, 2000, Vente::PAYMENT_METHOD_WAVE, $monday->copy()->addDays(4));

        $response = $this->callEndpoint($monday->toDateString(), 'weekly');

        $response->assertStatus(200)
            ->assertJsonPath('period.type', 'weekly')
            ->assertJsonPath('data.total_sales', 5000)
            ->assertJsonPath('data.total_payments', 3500)
            ->assertJsonPath('data.total_payments_cash', 1500)
            ->assertJsonPath('data.total_payments_wave', 2000);
    }

    // =========================================================================
    // next_tier_progress — included when mandatory threshold is reached
    //
    // No CarLoad is set up in these tests, so the mandatory threshold is 0
    // (always considered reached). Tests focus on the tier selection and
    // missing_amount computation.
    // =========================================================================

    private function makeWorkPeriodCoveringToday(): CommercialWorkPeriod
    {
        // Use findOrCreate so that payment events (which also call this) do not
        // cause a unique-constraint violation when the period already exists.
        return CommercialWorkPeriod::findOrCreateWeeklyPeriodForCommercialOnDate(
            commercialId: $this->commercial->id,
            date: $this->today->toDateString(),
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

    public function test_next_tier_progress_is_null_when_no_work_period_exists_for_the_day(): void
    {
        // No work period → no tiers → next_tier_progress must be null
        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress', null);
    }

    public function test_next_tier_progress_is_null_when_work_period_has_no_tiers(): void
    {
        $this->makeWorkPeriodCoveringToday(); // no tiers added

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress', null);
    }

    public function test_next_tier_progress_returns_the_nearest_unachieved_tier(): void
    {
        // Payments collected today = 2000 (below tier 2 threshold of 3000)
        $invoice = $this->makeInvoice(price: 2000, quantity: 1);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_CASH);

        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 1000, bonusAmount: 5000);  // already achieved
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 2, caThreshold: 3000, bonusAmount: 10000); // next
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 3, caThreshold: 6000, bonusAmount: 20000); // beyond

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress.tier_level', 2)
            ->assertJsonPath('data.next_tier_progress.ca_threshold', 3000)
            ->assertJsonPath('data.next_tier_progress.bonus_amount', 10000)
            ->assertJsonPath('data.next_tier_progress.missing_amount', 1000); // 3000 - 2000
    }

    public function test_next_tier_progress_missing_amount_equals_threshold_minus_current_payments(): void
    {
        $invoice = $this->makeInvoice(price: 4000, quantity: 1);
        $this->makePayment($invoice, 1500, Vente::PAYMENT_METHOD_CASH);

        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 5000, bonusAmount: 15000);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress.missing_amount', 3500); // 5000 - 1500
    }

    public function test_next_tier_progress_is_null_when_all_tiers_are_already_achieved(): void
    {
        // Payments = 8000, only tier has ca_threshold = 5000 → already achieved
        $invoice = $this->makeInvoice(price: 8000, quantity: 1);
        $this->makePayment($invoice, 8000, Vente::PAYMENT_METHOD_CASH);

        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 5000, bonusAmount: 10000);

        $response = $this->callEndpoint($this->today->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress', null);
    }

    public function test_next_tier_progress_is_null_for_weekly_type_even_when_tiers_exist(): void
    {
        $workPeriod = $this->makeWorkPeriodCoveringToday();
        $this->addTierToWorkPeriod($workPeriod, tierLevel: 1, caThreshold: 1000, bonusAmount: 5000);

        $response = $this->callEndpoint($this->today->toDateString(), 'weekly');

        $response->assertStatus(200)
            ->assertJsonPath('data.next_tier_progress', null);
    }
}
