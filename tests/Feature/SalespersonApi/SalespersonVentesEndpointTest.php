<?php

namespace Tests\Feature\SalespersonApi;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Enums\SalesInvoiceStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\CommercialObjectiveTier;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Payment;
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
 * Integration tests for GET /api/salesperson/ventes.
 *
 * Verifies that the response includes the three new commission fields
 * (mandatory_daily_sales, mandatory_daily_threshold, commissions_earned)
 * alongside the existing ventes/invoices/payments/total/total_payments fields.
 */
class SalespersonVentesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/salesperson/ventes';

    private User $user;

    private Commercial $commercial;

    private CommercialWorkPeriod $workPeriod;

    private Customer $customer;

    private Product $product;

    private string $workDay = '2026-03-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $team = Team::create([
            'name' => 'Équipe Test Ventes',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Ventes Test',
            'phone_number' => '221700000010',
            'gender' => 'male',
            'user_id' => $this->user->id,
            'team_id' => $team->id,
        ]);

        $carLoad = CarLoad::create([
            'name' => 'Chargement Ventes Test',
            'load_date' => Carbon::parse('2026-03-01'),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
        ]);

        $this->workPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => '2026-03-02',
            'period_end_date' => '2026-03-07',
        ]);

        $this->product = Product::create([
            'name' => 'Produit Test',
            'price' => 10_000,
            'cost_price' => 7_000,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $this->product->id,
            'quantity_loaded' => 999,
            'quantity_left' => 999,
            'cost_price_per_unit' => 7_000,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $this->product->id,
            'rate' => 0.0200, // 2 %
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000011',
            'owner_number' => '221700000011',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertStatus(401);
    }

    public function test_endpoint_returns_all_expected_keys_in_response(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200)
            ->assertJsonStructure([
                'ventes',
                'invoices' => [
                    '*' => [
                        'id',
                        'invoice_number',
                        'customer',
                        'items',
                        'total',
                        'status',
                        'payment_method',
                        'should_be_paid_at',
                        'created_at',
                    ],
                ],
                'payments',
                'total',
                'total_payments',
                'mandatory_daily_sales',
                'mandatory_daily_threshold',
                'commissions_earned',
            ]);
    }

    public function test_endpoint_returns_zeros_for_commission_fields_when_no_sales_exist(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $response->assertJsonFragment([
            'total' => 0,
            'total_payments' => 0,
            'mandatory_daily_sales' => 0,
            'mandatory_daily_threshold' => 0,
            'commissions_earned' => 0,
        ]);
    }

    public function test_total_and_mandatory_daily_sales_match_invoiced_amounts_for_the_day(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

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
                'price' => 10_000,
                'quantity' => 3,
                'profit' => 9_000,
                'paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $invoice->recalculateStoredTotals();

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $response->assertJsonFragment([
            'total' => 30_000,
            'mandatory_daily_sales' => 30_000,
        ]);

        // total and mandatory_daily_sales must always be equal
        $data = $response->json();
        $this->assertSame($data['total'], $data['mandatory_daily_sales']);
    }

    public function test_commissions_earned_reflects_net_commission_from_daily_commission_record(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => $this->workDay,
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

        $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200)
            ->assertJsonFragment(['commissions_earned' => 400]);
    }

    public function test_mandatory_daily_threshold_reflects_stored_value_from_daily_commission_record(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => $this->workDay,
            'base_commission' => 0,
            'basket_bonus' => 0,
            'objective_bonus' => 0,
            'total_penalties' => 0,
            'net_commission' => 0,
            'mandatory_daily_threshold' => 50_000,
            'mandatory_threshold_reached' => false,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => null,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200)
            ->assertJsonFragment(['mandatory_daily_threshold' => 50_000]);
    }

    public function test_invoices_list_is_scoped_to_the_requested_date(): void
    {
        Carbon::setTestNow($this->workDay.' 09:00:00');

        $invoiceOnDay = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Draft,
        ]);

        Carbon::setTestNow('2026-03-04 09:00:00');

        SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $invoiceIds = collect($response->json('invoices'))->pluck('id');
        $this->assertCount(1, $invoiceIds);
        $this->assertTrue($invoiceIds->contains($invoiceOnDay->id));
    }

    public function test_endpoint_defaults_to_today_when_no_date_param_is_provided(): void
    {
        Carbon::setTestNow('2026-03-05 11:00:00');

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT)
            ->assertStatus(200);

        $this->assertCount(0, $response->json('invoices'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_payments_list_is_scoped_to_authenticated_user_and_date(): void
    {
        // Create the invoice on a previous day so it is not filtered out by the
        // same-day exclusion rule (payments for same-day invoices are excluded).
        Carbon::setTestNow('2026-03-02 09:00:00');

        $previousDayInvoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Issued,
        ]);

        Carbon::setTestNow($this->workDay.' 10:00:00');

        $otherUser = User::factory()->create();

        Payment::create([
            'sales_invoice_id' => $previousDayInvoice->id,
            'user_id' => $this->user->id,
            'amount' => 5_000,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Payment::create([
            'sales_invoice_id' => $previousDayInvoice->id,
            'user_id' => $otherUser->id,
            'amount' => 3_000,
            'payment_method' => 'wave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $paymentAmounts = collect($response->json('payments'))->pluck('amount');
        $this->assertCount(1, $paymentAmounts);
        $this->assertTrue($paymentAmounts->contains(5_000));
        $this->assertFalse($paymentAmounts->contains(3_000));
    }

    public function test_user_with_no_commercial_profile_gets_zero_commission_fields(): void
    {
        $userWithNoCommercial = User::factory()->create();

        Sanctum::actingAs($userWithNoCommercial);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $response->assertJsonFragment([
            'mandatory_daily_sales' => 0,
            'mandatory_daily_threshold' => 0,
            'commissions_earned' => 0,
        ]);
    }

    public function test_objective_tier_bonus_is_included_in_commissions_earned(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        CommercialObjectiveTier::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'tier_level' => 1,
            'ca_threshold' => 100_000,
            'bonus_amount' => 5_000,
        ]);

        DailyCommission::create([
            'commercial_work_period_id' => $this->workPeriod->id,
            'work_day' => $this->workDay,
            'base_commission' => 2_000,
            'basket_bonus' => 0,
            'objective_bonus' => 5_000,
            'total_penalties' => 0,
            'net_commission' => 7_000,
            'basket_achieved' => false,
            'basket_multiplier_applied' => null,
            'achieved_tier_level' => 1,
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200)
            ->assertJsonFragment(['commissions_earned' => 7_000]);
    }

    public function test_invoice_status_value_is_returned_instead_of_paid_boolean(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Issued,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $invoiceData = collect($response->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertArrayHasKey('status', $invoiceData);
        $this->assertArrayNotHasKey('paid', $invoiceData);
        $this->assertSame(\App\Enums\SalesInvoiceStatus::Issued->value, $invoiceData['status']);
    }

    public function test_invoice_payment_method_reflects_first_payment(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::FullyPaid,
        ]);

        Payment::create([
            'sales_invoice_id' => $invoice->id,
            'user_id' => $this->user->id,
            'amount' => 10_000,
            'payment_method' => 'wave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $invoiceData = collect($response->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertSame('wave', $invoiceData['payment_method']);
    }

    public function test_invoice_payment_method_is_null_when_no_payment_exists(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $invoiceData = collect($response->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertNull($invoiceData['payment_method']);
    }

    public function test_invoices_from_other_commercials_are_not_returned(): void
    {
        Carbon::setTestNow($this->workDay.' 10:00:00');

        $otherUser = User::factory()->create();

        $otherTeam = Team::create([
            'name' => 'Autre Équipe',
            'user_id' => $otherUser->id,
        ]);

        $otherCommercial = Commercial::create([
            'name' => 'Autre Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);

        $otherCustomer = Customer::create([
            'name' => 'Autre Client',
            'phone_number' => '221700000098',
            'owner_number' => '221700000098',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $otherCommercial->id,
        ]);

        $myInvoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => SalesInvoiceStatus::Draft,
        ]);

        $otherInvoice = SalesInvoice::create([
            'customer_id' => $otherCustomer->id,
            'commercial_id' => $otherCommercial->id,
            'user_id' => $otherUser->id,
            'status' => SalesInvoiceStatus::Draft,
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $invoiceIds = collect($response->json('invoices'))->pluck('id');
        $this->assertCount(1, $invoiceIds);
        $this->assertTrue($invoiceIds->contains($myInvoice->id));
        $this->assertFalse($invoiceIds->contains($otherInvoice->id),
            'Invoices from other commercials must not be returned');
    }

    public function test_payments_for_same_day_invoices_are_excluded_from_payments_list(): void
    {
        // Invoice created on a PREVIOUS day — payments against it should appear
        Carbon::setTestNow('2026-03-02 09:00:00');

        $previousDayInvoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Issued,
        ]);

        // Invoice created TODAY — payments against it should be excluded
        Carbon::setTestNow($this->workDay.' 10:00:00');

        $todayInvoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'user_id' => $this->user->id,
            'status' => \App\Enums\SalesInvoiceStatus::Issued,
        ]);

        $paymentForTodayInvoice = Payment::create([
            'sales_invoice_id' => $todayInvoice->id,
            'user_id' => $this->user->id,
            'amount' => 8_000,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $debtCollectionPayment = Payment::create([
            'sales_invoice_id' => $previousDayInvoice->id,
            'user_id' => $this->user->id,
            'amount' => 5_000,
            'payment_method' => 'wave',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::ENDPOINT.'?date='.$this->workDay)
            ->assertStatus(200);

        $paymentIds = collect($response->json('payments'))->pluck('id');
        $this->assertFalse($paymentIds->contains($paymentForTodayInvoice->id),
            'Payment for a same-day invoice must be excluded from the payments list');
        $this->assertTrue($paymentIds->contains($debtCollectionPayment->id),
            'Payment for a previous-day invoice must appear in the payments list');
    }
}
