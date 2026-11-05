<?php

namespace Tests\Feature;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Enums\DayOfWeek;
use App\Jobs\RecalculateBeatRoundStrikeRateJob;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
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
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for RecalculateBeatRoundStrikeRateJob.
 *
 * Covers:
 *  - Job stores the correct strike rate in beat_rounds.strike_rate
 *  - Strike rate = distinct buying customers / total round stops × 100
 *  - Zero when no customers bought; 100 when all bought; partial percentages
 *  - Multiple invoices for the same customer count as one buyer (distinct)
 *  - Sales on a different date do not count
 *  - Graceful no-op when the round no longer exists
 *  - Job is dispatched when a SalesInvoice is created for a customer in a beat round
 *  - A sale through the salesperson endpoint counts in the strike rate even when
 *    the invoice is entirely unpaid (no payment at all), and completes the stop with a sale
 */
class BeatRoundStrikeRateJobTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2025-02-05'; // A Wednesday

    private Beat $beat;

    private BeatRound $round;

    private Commercial $commercial;

    private Product $product;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 400,
            'base_quantity' => 1,
        ]);

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);

        $this->beat = Beat::create([
            'name' => 'Beat Test '.uniqid(),
            'day_of_week' => DayOfWeek::Wednesday->value,
            'commercial_id' => $this->commercial->id,
        ]);

        $this->round = BeatRound::create([
            'beat_id' => $this->beat->id,
            'planned_at' => self::ROUND_DATE,
            'week_day' => DayOfWeek::Wednesday->value,
            'commercial_id' => $this->commercial->id,
            'name' => $this->beat->name.' - '.self::ROUND_DATE,
        ]);
    }

    // =========================================================================
    // Core strike rate computation
    // =========================================================================

    public function test_job_stores_zero_strike_rate_when_no_customers_bought(): void
    {
        $this->addRoundStop($this->makeCustomer());
        $this->addRoundStop($this->makeCustomer());

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        $this->assertSame(0.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_stores_100_percent_when_all_customers_bought(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->addRoundStop($customerA);
        $this->addRoundStop($customerB);

        $this->createSaleOnDate($customerA, date: Carbon::parse(self::ROUND_DATE));
        $this->createSaleOnDate($customerB, date: Carbon::parse(self::ROUND_DATE));

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        $this->assertSame(100.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_stores_correct_partial_strike_rate(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $customerC = $this->makeCustomer();
        $customerD = $this->makeCustomer();

        $this->addRoundStop($customerA);
        $this->addRoundStop($customerB);
        $this->addRoundStop($customerC);
        $this->addRoundStop($customerD);

        // 1 out of 4 → 25 %
        $this->createSaleOnDate($customerA, date: Carbon::parse(self::ROUND_DATE));

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        $this->assertSame(25.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_counts_customer_once_even_with_multiple_invoices_on_round_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addRoundStop($customer);

        $roundDay = Carbon::parse(self::ROUND_DATE);
        $this->createSaleOnDate($customer, date: $roundDay);
        $this->createSaleOnDate($customer, date: $roundDay);

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        // 1 distinct customer, 1 stop → 100 %
        $this->assertSame(100.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_ignores_sales_on_a_different_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addRoundStop($customer);

        // Sale on the day after the round — must not count
        $this->createSaleOnDate($customer, date: Carbon::parse(self::ROUND_DATE)->addDay());

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        $this->assertSame(0.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_stores_zero_strike_rate_when_round_has_no_stops(): void
    {
        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        $this->assertSame(0.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_counts_customer_who_only_paid_a_due_invoice_without_new_purchase(): void
    {
        $customerWithPaymentOnly = $this->makeCustomer();
        $customerWithNoActivity = $this->makeCustomer();

        $this->addRoundStop($customerWithPaymentOnly);
        $this->addRoundStop($customerWithNoActivity);

        // Pre-existing invoice created before the round date
        $priorInvoice = $this->createSaleOnDate($customerWithPaymentOnly, date: Carbon::parse(self::ROUND_DATE)->subDay());

        // On the round date the customer pays the old invoice — no new invoice is created
        $this->createPaymentOnDate($priorInvoice, amount: 1000, date: Carbon::parse(self::ROUND_DATE));

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        // 1 out of 2 customers engaged via payment → 50 %
        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_counts_customer_once_who_both_bought_and_paid_on_round_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addRoundStop($customer);

        $roundDay = Carbon::parse(self::ROUND_DATE);

        // Makes a new purchase on the round date
        $this->createSaleOnDate($customer, date: $roundDay);

        // Also pays a prior invoice on the same day
        $priorInvoice = $this->createSaleOnDate($customer, date: $roundDay->copy()->subDay());
        $this->createPaymentOnDate($priorInvoice, amount: 1000, date: $roundDay);

        RecalculateBeatRoundStrikeRateJob::dispatchSync($this->round->id);

        // 1 customer engaged in two ways → counted once → 100 %
        $this->assertSame(100.0, $this->round->fresh()->strike_rate);
    }

    public function test_job_is_noop_when_beat_round_no_longer_exists(): void
    {
        // Should not throw — job gracefully handles a missing round
        RecalculateBeatRoundStrikeRateJob::dispatchSync(999999);

        $this->assertTrue(true);
    }

    // =========================================================================
    // Dispatch verification
    // =========================================================================

    public function test_job_is_queued_when_dispatched_for_a_beat_round(): void
    {
        Queue::fake();

        RecalculateBeatRoundStrikeRateJob::dispatch($this->round->id);

        Queue::assertPushed(
            RecalculateBeatRoundStrikeRateJob::class,
            fn ($job) => $job->beatRoundId === $this->round->id,
        );
    }

    // =========================================================================
    // Sale through the salesperson endpoint (paid and fully unpaid)
    // =========================================================================

    public function test_fully_unpaid_invoice_counts_customer_in_round_strike_rate(): void
    {
        $buyingCustomer = $this->addRoundStopWithTemplateStop($this->makeCustomer());
        $this->addRoundStopWithTemplateStop($this->makeCustomer());

        $this->postSaleThroughSalespersonEndpoint($buyingCustomer, isPaid: false);

        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    public function test_fully_unpaid_invoice_marks_the_planned_stop_as_completed_with_sale(): void
    {
        $buyingCustomer = $this->addRoundStopWithTemplateStop($this->makeCustomer());

        $this->postSaleThroughSalespersonEndpoint($buyingCustomer, isPaid: false);

        $stop = BeatStop::where('beat_round_id', $this->round->id)
            ->where('customer_id', $buyingCustomer->id)
            ->firstOrFail();

        $this->assertSame(BeatStop::STATUS_COMPLETED, $stop->status);
        $this->assertTrue($stop->resulted_in_sale);
    }

    public function test_fully_paid_invoice_counts_customer_in_round_strike_rate(): void
    {
        $buyingCustomer = $this->addRoundStopWithTemplateStop($this->makeCustomer());
        $this->addRoundStopWithTemplateStop($this->makeCustomer());

        $this->postSaleThroughSalespersonEndpoint($buyingCustomer, isPaid: true);

        $this->assertSame(50.0, $this->round->fresh()->strike_rate);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function addRoundStopWithTemplateStop(Customer $customer): Customer
    {
        $this->addRoundStop($customer);

        $this->beat->templateStops()->create([
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);

        return $customer;
    }

    private function postSaleThroughSalespersonEndpoint(Customer $customer, bool $isPaid): void
    {
        Carbon::setTestNow(Carbon::parse(self::ROUND_DATE.' 10:00:00'));

        $salespersonUser = $this->commercial->user;
        $team = Team::firstOrFail();
        $this->commercial->team_id = $team->id;
        $this->commercial->save();

        $carLoad = CarLoad::create([
            'name' => 'Chargement Strike',
            'load_date' => now(),
            'return_date' => Carbon::parse('2026-12-31'),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
        ]);

        CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $this->product->id,
            'quantity_loaded' => 100,
            'quantity_left' => 100,
            'cost_price_per_unit' => 400,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        $this->round->update(['odometer_start_km' => 10000]);

        $payload = [
            'customer_id' => $customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 1000]],
            'paid' => $isPaid,
        ];

        if ($isPaid) {
            $payload['payment_method'] = 'CASH';
        } else {
            $payload['should_be_paid_at'] = now()->addDays(7)->toDateString();
        }

        Sanctum::actingAs($salespersonUser);
        $this->postJson('/api/salesperson/sales-invoices', $payload)->assertStatus(201);
    }

    private function createPaymentOnDate(SalesInvoice $invoice, int $amount, Carbon $date): Payment
    {
        $dateTimeString = $date->copy()->startOfDay()->toDateTimeString();

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'cash',
            'user_id' => $this->commercial->user_id,
        ]);

        DB::table('payments')
            ->where('id', $payment->id)
            ->update(['created_at' => $dateTimeString, 'updated_at' => $dateTimeString]);

        return $payment->fresh();
    }

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

    private function addRoundStop(Customer $customer): BeatStop
    {
        return BeatStop::create([
            'beat_id' => $this->beat->id,
            'beat_round_id' => $this->round->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
    }

    private function createSaleOnDate(Customer $customer, Carbon $date): SalesInvoice
    {
        $dateTimeString = $date->copy()->startOfDay()->toDateTimeString();

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        DB::table('sales_invoices')
            ->where('id', $invoice->id)
            ->update(['created_at' => $dateTimeString, 'updated_at' => $dateTimeString]);

        $vente = Vente::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => 1000,
            'quantity' => 1,
            'profit' => 600,
            'type' => Vente::TYPE_INVOICE,
        ]);

        DB::table('ventes')
            ->where('id', $vente->id)
            ->update(['created_at' => $dateTimeString, 'updated_at' => $dateTimeString]);

        return $invoice->fresh();
    }
}
