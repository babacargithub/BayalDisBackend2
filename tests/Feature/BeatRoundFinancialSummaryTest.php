<?php

namespace Tests\Feature;

use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\BeatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the financial summary fields in BeatService::getRoundCustomers():
 *   - total_debt_to_collect: sum of pre-round outstanding invoice balances
 *   - total_collected: sum of sales (price × quantity) recorded on the round date
 *   - remaining_to_collect: total_debt_to_collect − total_collected
 *
 * "Pre-round" means invoices created BEFORE the round date. Invoices created ON the
 * round date are excluded from debt (they belong to the current trip's sales).
 *
 * total_collected is computed via SalesInvoiceStatsService::totalSales(), which sums
 * ventes.price * quantity for ventes created on the round date for the round's customers.
 *
 * ROUND_DATE (2025-01-15) is a Wednesday, matching DayOfWeek::Wednesday on the test beat.
 */
class BeatRoundFinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2025-01-15';

    private BeatService $beatService;

    private Beat $beat;

    private Commercial $commercial;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beatService = app(BeatService::class);
        $this->product = $this->makeProduct();

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
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

        // Rounds must be explicitly created — they are never auto-generated.
        $this->beatService->createRound($this->beat, self::ROUND_DATE);
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_financial_summary_with_mixed_debts_and_sales_on_round_date(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->addTemplateStop($customerA);
        $this->addTemplateStop($customerB);

        // Customer A: full unpaid invoice → owes 5 000
        $this->createPreRoundInvoice($customerA, price: 5000, quantity: 1, alreadyPaid: 0);

        // Customer B: invoice for 3 000 with 1 000 already paid → owes 2 000
        $this->createPreRoundInvoice($customerB, price: 3000, quantity: 1, alreadyPaid: 1000);

        // Round-date sales: Customer A buys 2 000, Customer B buys 1 500
        $this->createSaleOnDate($customerA, price: 2000, quantity: 1, date: Carbon::parse(self::ROUND_DATE)->startOfDay());
        $this->createSaleOnDate($customerB, price: 1500, quantity: 1, date: Carbon::parse(self::ROUND_DATE)->startOfDay());

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(7000, $result['total_debt_to_collect']);
        $this->assertSame(3500, $result['total_collected']);
        $this->assertSame(3500, $result['remaining_to_collect']);
    }

    public function test_financial_summary_is_zero_when_customers_have_no_pre_round_debt(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->addTemplateStop($customerA);
        $this->addTemplateStop($customerB);

        // No pre-round invoices and no round-date sales
        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(0, $result['total_debt_to_collect']);
        $this->assertSame(0, $result['total_collected']);
        $this->assertSame(0, $result['remaining_to_collect']);
    }

    public function test_total_collected_is_zero_when_no_sales_occur_on_round_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addTemplateStop($customer);

        // Pre-round debt exists but no sales happen on the round date
        $this->createPreRoundInvoice($customer, price: 4000, quantity: 1, alreadyPaid: 0);

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(4000, $result['total_debt_to_collect']);
        $this->assertSame(0, $result['total_collected']);
        $this->assertSame(4000, $result['remaining_to_collect']);
    }

    public function test_sales_recorded_on_different_date_are_excluded_from_total_collected(): void
    {
        $customer = $this->makeCustomer();
        $this->addTemplateStop($customer);

        $this->createPreRoundInvoice($customer, price: 3000, quantity: 1, alreadyPaid: 0);

        // Sale happens the day AFTER the round — must not count toward total_collected
        $dayAfterRound = Carbon::parse(self::ROUND_DATE)->addDay()->startOfDay();
        $this->createSaleOnDate($customer, price: 2500, quantity: 1, date: $dayAfterRound);

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(3000, $result['total_debt_to_collect']);
        $this->assertSame(0, $result['total_collected']);
        $this->assertSame(3000, $result['remaining_to_collect']);
    }

    // =========================================================================
    // Strike rate tests
    // =========================================================================

    public function test_strike_rate_is_100_percent_when_all_customers_bought(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->addTemplateStop($customerA);
        $this->addTemplateStop($customerB);

        $this->createSaleOnDate($customerA, price: 2000, quantity: 1, date: Carbon::parse(self::ROUND_DATE)->startOfDay());
        $this->createSaleOnDate($customerB, price: 1500, quantity: 1, date: Carbon::parse(self::ROUND_DATE)->startOfDay());

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(2, $result['buying_customers_count']);
        $this->assertSame(100.0, $result['strike_rate']);
    }

    public function test_strike_rate_is_zero_when_no_customers_bought(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();

        $this->addTemplateStop($customerA);
        $this->addTemplateStop($customerB);

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(0, $result['buying_customers_count']);
        $this->assertSame(0.0, $result['strike_rate']);
    }

    public function test_strike_rate_reflects_partial_buying_among_stops(): void
    {
        $customerA = $this->makeCustomer();
        $customerB = $this->makeCustomer();
        $customerC = $this->makeCustomer();
        $customerD = $this->makeCustomer();

        $this->addTemplateStop($customerA);
        $this->addTemplateStop($customerB);
        $this->addTemplateStop($customerC);
        $this->addTemplateStop($customerD);

        // Only 1 out of 4 customers buys → 25 %
        $this->createSaleOnDate($customerA, price: 3000, quantity: 1, date: Carbon::parse(self::ROUND_DATE)->startOfDay());

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(1, $result['buying_customers_count']);
        $this->assertSame(25.0, $result['strike_rate']);
    }

    public function test_strike_rate_counts_customer_once_even_with_multiple_invoices_on_round_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addTemplateStop($customer);

        // Two separate invoices on the same day for the same customer
        $roundDayStart = Carbon::parse(self::ROUND_DATE)->startOfDay();
        $this->createSaleOnDate($customer, price: 1000, quantity: 1, date: $roundDayStart);
        $this->createSaleOnDate($customer, price: 500, quantity: 2, date: $roundDayStart);

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        // 1 distinct customer, 1 stop → 100 %
        $this->assertSame(1, $result['buying_customers_count']);
        $this->assertSame(100.0, $result['strike_rate']);
    }

    public function test_strike_rate_is_zero_when_round_has_no_stops(): void
    {
        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(0, $result['buying_customers_count']);
        $this->assertSame(0.0, $result['strike_rate']);
    }

    public function test_strike_rate_counts_customer_who_only_paid_a_due_invoice_without_new_purchase(): void
    {
        $customerWithPaymentOnly = $this->makeCustomer();
        $customerWithNoActivity = $this->makeCustomer();

        $this->addTemplateStop($customerWithPaymentOnly);
        $this->addTemplateStop($customerWithNoActivity);

        // Customer has a pre-existing unpaid invoice
        $existingInvoice = $this->createPreRoundInvoice($customerWithPaymentOnly, price: 4000, quantity: 1, alreadyPaid: 0);

        // On the round date the customer pays the old invoice — no new invoice created
        $this->createPaymentOnDate($existingInvoice, amount: 4000, date: Carbon::parse(self::ROUND_DATE));

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        // 1 out of 2 customers engaged → 50 %
        $this->assertSame(1, $result['buying_customers_count']);
        $this->assertSame(50.0, $result['strike_rate']);
    }

    public function test_strike_rate_counts_customer_once_who_both_bought_and_paid_on_round_date(): void
    {
        $customer = $this->makeCustomer();
        $this->addTemplateStop($customer);

        // A pre-existing invoice the customer will pay
        $oldInvoice = $this->createPreRoundInvoice($customer, price: 2000, quantity: 1, alreadyPaid: 0);

        $roundDay = Carbon::parse(self::ROUND_DATE)->startOfDay();

        // Also makes a new purchase on the round date
        $this->createSaleOnDate($customer, price: 1000, quantity: 1, date: $roundDay);

        // And pays the old invoice on the same day
        $this->createPaymentOnDate($oldInvoice, amount: 2000, date: Carbon::parse(self::ROUND_DATE));

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        // 1 customer engaged in two ways → still counted once → 100 %
        $this->assertSame(1, $result['buying_customers_count']);
        $this->assertSame(100.0, $result['strike_rate']);
    }

    public function test_sales_on_different_date_do_not_count_toward_strike_rate(): void
    {
        $customer = $this->makeCustomer();
        $this->addTemplateStop($customer);

        // Sale on the day AFTER the round — must not affect strike rate for the round date
        $dayAfterRound = Carbon::parse(self::ROUND_DATE)->addDay()->startOfDay();
        $this->createSaleOnDate($customer, price: 2000, quantity: 1, date: $dayAfterRound);

        $result = $this->beatService->getCustomersOfBeatRound($this->beat, self::ROUND_DATE);

        $this->assertSame(0, $result['buying_customers_count']);
        $this->assertSame(0.0, $result['strike_rate']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(int $price = 1000, int $costPrice = 400): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
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

    private function addTemplateStop(Customer $customer): void
    {
        BeatStop::create([
            'beat_id' => $this->beat->id,
            'customer_id' => $customer->id,
            'status' => BeatStop::STATUS_PLANNED,
        ]);
    }

    /**
     * Create a SalesInvoice + Vente backdated to the day before the round date,
     * simulating a pre-existing outstanding invoice the customer owes at round time.
     *
     * Vente creation triggers recalculateStoredTotals() which sets total_amount = price × quantity.
     * To simulate a partial payment without creating a full Payment record, total_payments is
     * written directly to the database — sufficient because total_remaining is computed
     * in PHP as total_amount − total_payments on the eagerly-loaded model instance.
     */
    private function createPreRoundInvoice(
        Customer $customer,
        int $price,
        int $quantity,
        int $alreadyPaid,
    ): SalesInvoice {
        $backdatedAt = Carbon::parse(self::ROUND_DATE)->subDay()->startOfDay()->toDateTimeString();

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        DB::table('sales_invoices')
            ->where('id', $invoice->id)
            ->update(['created_at' => $backdatedAt, 'updated_at' => $backdatedAt]);

        $vente = Vente::create([
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => ($price - $this->product->cost_price) * $quantity,
            'type' => Vente::TYPE_INVOICE,
        ]);

        DB::table('ventes')
            ->where('id', $vente->id)
            ->update(['created_at' => $backdatedAt, 'updated_at' => $backdatedAt]);

        if ($alreadyPaid > 0) {
            DB::table('sales_invoices')
                ->where('id', $invoice->id)
                ->update(['total_payments' => $alreadyPaid]);
        }

        return $invoice->fresh();
    }

    /**
     * Create a Payment against an existing invoice, backdated to the given date.
     *
     * Used to simulate a customer settling a due invoice during a beat round visit,
     * without creating any new SalesInvoice on that date.
     */
    private function createPaymentOnDate(SalesInvoice $invoice, int $amount, Carbon $date): Payment
    {
        $dateTimeString = $date->startOfDay()->toDateTimeString();

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

    /**
     * Create a SalesInvoice + Vente stamped on the given date.
     *
     * Vente records created on the round date are picked up by
     * SalesInvoiceStatsService::totalSales() and contribute to total_collected.
     */
    private function createSaleOnDate(Customer $customer, int $price, int $quantity, Carbon $date): SalesInvoice
    {
        $dateTimeString = $date->toDateTimeString();

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
            'price' => $price,
            'quantity' => $quantity,
            'profit' => ($price - $this->product->cost_price) * $quantity,
            'type' => Vente::TYPE_INVOICE,
        ]);

        DB::table('ventes')
            ->where('id', $vente->id)
            ->update(['created_at' => $dateTimeString, 'updated_at' => $dateTimeString]);

        return $invoice->fresh();
    }
}
