<?php

namespace Tests\Feature\ActivityReport;

use App\Data\ActivityReport\CommercialActivityReportDTO;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for SalesInvoiceService::buildCommercialActivityReport().
 *
 * Verifies that all financial totals are derived from sales_invoices stored columns
 * (total_amount, total_payments) and the payments table — never raw vente queries.
 *
 * Each test covers a distinct scenario so regressions are easy to locate.
 */
class CommercialActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceStatsService $salesInvoiceStatsService;

    private Commercial $commercial;

    private Customer $customer;

    private Product $product;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceStatsService = app(SalesInvoiceStatsService::class);
        $this->today = Carbon::today();
        $this->product = $this->makeProduct();
        $team = $this->makeTeam();
        $this->commercial = $this->makeCommercial($team);
        $this->customer = $this->makeCustomer($this->commercial);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeProduct(int $price = 1000, int $costPrice = 500): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
    }

    private function makeTeam(): Team
    {
        return Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeCommercial(Team $team): Commercial
    {
        $commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $commercial->team()->associate($team);
        $commercial->save();

        return $commercial;
    }

    private function makeCustomer(Commercial $commercial, bool $isProspect = false): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
            'is_prospect' => $isProspect,
        ]);
    }

    /**
     * Create a SalesInvoice with vente items, returning it with refreshed stored totals.
     *
     * created_at is force-set via DB because it is not in $fillable and Eloquent would
     * otherwise override it with now(), breaking date-boundary tests.
     *
     * @param  array<array{quantity: int, price: int}>  $items
     */
    private function makeInvoiceWithItems(
        Commercial $commercial,
        array $items,
        Carbon $createdAt,
        ?Customer $customer = null,
    ): SalesInvoice {
        $invoice = SalesInvoice::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'commercial_id' => $commercial->id,
        ]);

        // Force-set created_at since it is not mass-assignable.
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['created_at' => $createdAt->toDateTimeString()]);

        foreach ($items as $item) {
            Vente::create([
                'sales_invoice_id' => $invoice->id,
                'product_id' => $this->product->id,
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'profit' => ($item['price'] - 500) * $item['quantity'],
                'type' => Vente::TYPE_INVOICE,
            ]);
        }

        return $invoice->fresh();
    }

    /**
     * Create a Payment against an invoice using the given payment method and collection date.
     *
     * created_at is force-set via DB because it is not in $fillable.
     */
    private function makePayment(SalesInvoice $invoice, int $amount, string $paymentMethod, Carbon $collectedAt): Payment
    {
        $payment = Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'user_id' => User::factory()->create()->id,
        ]);

        DB::table('payments')->where('id', $payment->id)->update(['created_at' => $collectedAt->toDateTimeString()]);

        return $payment->fresh();
    }

    private function buildReport(?Commercial $commercial = null): CommercialActivityReportDTO
    {
        return $this->salesInvoiceStatsService->buildCommercialActivityReport(
            $commercial ?? $this->commercial,
            $this->today->copy()->startOfDay(),
            $this->today->copy()->endOfDay(),
        );
    }

    // =========================================================================
    // totalSales
    // =========================================================================

    public function test_total_sales_sums_stored_total_amount_across_invoices_in_period(): void
    {
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 2, 'price' => 1000]], $this->today);
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 3, 'price' => 500]], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2 * 1000 + 3 * 500, $report->totalSales);
    }

    public function test_total_sales_is_zero_when_no_invoices_exist_in_period(): void
    {
        $report = $this->buildReport();

        $this->assertEquals(0, $report->totalSales);
    }

    public function test_total_sales_excludes_invoices_created_outside_the_period(): void
    {
        $yesterday = $this->today->copy()->subDay();
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 5, 'price' => 1000]], $yesterday);
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 2, 'price' => 1000]], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2 * 1000, $report->totalSales);
    }

    public function test_total_sales_excludes_invoices_belonging_to_another_commercial(): void
    {
        $otherCommercial = $this->makeCommercial($this->makeTeam());
        $otherCustomer = $this->makeCustomer($otherCommercial);
        $this->makeInvoiceWithItems($otherCommercial, [['quantity' => 10, 'price' => 1000]], $this->today, $otherCustomer);
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 2, 'price' => 1000]], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2 * 1000, $report->totalSales);
    }

    public function test_total_sales_sums_multiple_line_items_on_a_single_invoice(): void
    {
        $this->makeInvoiceWithItems($this->commercial, [
            ['quantity' => 1, 'price' => 2000],
            ['quantity' => 4, 'price' => 500],
        ], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(1 * 2000 + 4 * 500, $report->totalSales);
    }

    // =========================================================================
    // totalPayments
    // =========================================================================

    public function test_total_payments_sums_all_payment_amounts_collected_in_period(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 3000]], $this->today);
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH, $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_WAVE, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(3000, $report->totalPayments);
    }

    public function test_total_payments_is_zero_when_no_payments_exist_in_period(): void
    {
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 1000]], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(0, $report->totalPayments);
    }

    public function test_total_payments_excludes_payments_collected_outside_the_period(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 3000]], $this->today);
        $this->makePayment($invoice, 1500, Vente::PAYMENT_METHOD_CASH, $this->today->copy()->subDay());
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(1000, $report->totalPayments);
    }

    public function test_total_payments_excludes_payments_for_another_commercials_invoices(): void
    {
        $otherCommercial = $this->makeCommercial($this->makeTeam());
        $otherCustomer = $this->makeCustomer($otherCommercial);
        $otherInvoice = $this->makeInvoiceWithItems($otherCommercial, [['quantity' => 1, 'price' => 5000]], $this->today, $otherCustomer);
        $this->makePayment($otherInvoice, 5000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 2000]], $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_WAVE, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2000, $report->totalPayments);
    }

    // =========================================================================
    // totalUnpaidAmount
    // =========================================================================

    public function test_total_unpaid_amount_is_the_difference_between_invoice_total_and_payments(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 5000]], $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(5000 - 2000, $report->totalUnpaidAmount);
    }

    public function test_total_unpaid_amount_is_zero_when_all_invoices_are_fully_paid(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 3000]], $this->today);
        $this->makePayment($invoice, 3000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(0, $report->totalUnpaidAmount);
    }

    public function test_total_unpaid_amount_equals_total_sales_when_no_payment_is_made(): void
    {
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 2, 'price' => 1500]], $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2 * 1500, $report->totalUnpaidAmount);
        $this->assertEquals($report->totalSales, $report->totalUnpaidAmount);
    }

    // =========================================================================
    // Payment method breakdown
    // =========================================================================

    public function test_payment_method_totals_are_broken_down_by_cash_wave_and_om(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 6000]], $this->today);
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH, $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_WAVE, $this->today);
        $this->makePayment($invoice, 3000, Vente::PAYMENT_METHOD_OM, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(1000, $report->totalPaymentsCash);
        $this->assertEquals(2000, $report->totalPaymentsWave);
        $this->assertEquals(3000, $report->totalPaymentsOm);
    }

    public function test_payment_method_total_is_zero_when_no_payment_with_that_method_exists(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 2000]], $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(2000, $report->totalPaymentsCash);
        $this->assertEquals(0, $report->totalPaymentsWave);
        $this->assertEquals(0, $report->totalPaymentsOm);
    }

    public function test_payment_method_totals_sum_equals_total_payments(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 9000]], $this->today);
        $this->makePayment($invoice, 4000, Vente::PAYMENT_METHOD_CASH, $this->today);
        $this->makePayment($invoice, 3000, Vente::PAYMENT_METHOD_WAVE, $this->today);
        $this->makePayment($invoice, 2000, Vente::PAYMENT_METHOD_OM, $this->today);

        $report = $this->buildReport();

        $expectedMethodsSum = $report->totalPaymentsCash + $report->totalPaymentsWave + $report->totalPaymentsOm;
        $this->assertEquals($expectedMethodsSum, $report->totalPayments);
    }

    /**
     * This test intentionally uses the literal strings sent by the mobile app ('CASH', 'WAVE', 'OM')
     * rather than the Vente constants. If a constant value ever diverges from what the app sends,
     * the breakdown will silently return 0 while totalPayments remains correct — this test catches
     * that exact mismatch before it reaches production.
     */
    public function test_payment_method_breakdown_uses_the_exact_strings_stored_by_the_mobile_app(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 9000]], $this->today);
        $this->makePayment($invoice, 4000, 'CASH', $this->today);
        $this->makePayment($invoice, 3000, 'WAVE', $this->today);
        $this->makePayment($invoice, 2000, 'OM', $this->today);

        $report = $this->buildReport();

        $this->assertEquals(4000, $report->totalPaymentsCash);
        $this->assertEquals(3000, $report->totalPaymentsWave);
        $this->assertEquals(2000, $report->totalPaymentsOm);
        $this->assertEquals(
            $report->totalPaymentsCash + $report->totalPaymentsWave + $report->totalPaymentsOm,
            $report->totalPayments,
            'Sum of per-method totals must equal totalPayments — a mismatch means a payment method constant does not match what is stored in the database.',
        );
    }

    public function test_payment_method_breakdown_excludes_payments_outside_the_period(): void
    {
        $invoice = $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 5000]], $this->today);
        $this->makePayment($invoice, 3000, Vente::PAYMENT_METHOD_WAVE, $this->today->copy()->subDay());
        $this->makePayment($invoice, 1000, Vente::PAYMENT_METHOD_CASH, $this->today);

        $report = $this->buildReport();

        $this->assertEquals(1000, $report->totalPaymentsCash);
        $this->assertEquals(0, $report->totalPaymentsWave);
    }

    // =========================================================================
    // Customer counts
    // =========================================================================

    public function test_new_confirmed_customers_count_includes_only_non_prospect_customers_created_in_period(): void
    {
        Customer::where('commercial_id', $this->commercial->id)->delete();

        $this->makeCustomer($this->commercial, false); // confirmed
        $this->makeCustomer($this->commercial, false); // confirmed
        $this->makeCustomer($this->commercial, true);  // prospect

        $report = $this->buildReport();

        $this->assertEquals(2, $report->newConfirmedCustomersCount);
    }

    public function test_new_prospect_customers_count_includes_only_prospect_customers_created_in_period(): void
    {
        Customer::where('commercial_id', $this->commercial->id)->delete();

        $this->makeCustomer($this->commercial, false); // confirmed
        $this->makeCustomer($this->commercial, true);  // prospect
        $this->makeCustomer($this->commercial, true);  // prospect

        $report = $this->buildReport();

        $this->assertEquals(2, $report->newProspectCustomersCount);
    }

    public function test_customer_counts_exclude_customers_created_before_the_period(): void
    {
        Customer::where('commercial_id', $this->commercial->id)->delete();

        // Create an old customer and force-set its created_at to 5 days ago.
        $oldCustomer = Customer::create([
            'name' => 'Old Confirmed Customer',
            'address' => 'Address',
            'phone_number' => '221700000001',
            'owner_number' => '221700000001',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
            'is_prospect' => false,
        ]);
        DB::table('customers')->where('id', $oldCustomer->id)->update([
            'created_at' => $this->today->copy()->subDays(5)->toDateTimeString(),
        ]);

        $this->makeCustomer($this->commercial, false); // confirmed, created today

        $report = $this->buildReport();

        $this->assertEquals(1, $report->newConfirmedCustomersCount);
    }

    public function test_customer_counts_exclude_customers_belonging_to_other_commercials(): void
    {
        Customer::where('commercial_id', $this->commercial->id)->delete();

        $otherCommercial = $this->makeCommercial($this->makeTeam());
        $this->makeCustomer($otherCommercial, false);
        $this->makeCustomer($otherCommercial, true);

        $this->makeCustomer($this->commercial, false); // only this one belongs to our commercial

        $report = $this->buildReport();

        $this->assertEquals(1, $report->newConfirmedCustomersCount);
        $this->assertEquals(0, $report->newProspectCustomersCount);
    }

    // =========================================================================
    // DTO structure
    // =========================================================================

    public function test_to_snake_case_array_returns_all_dto_fields_with_snake_case_keys(): void
    {
        $report = $this->buildReport();
        $array = $report->toSnakeCaseArray();

        $this->assertArrayHasKey('total_sales', $array);
        $this->assertArrayHasKey('total_payments', $array);
        $this->assertArrayHasKey('new_confirmed_customers_count', $array);
        $this->assertArrayHasKey('new_prospect_customers_count', $array);
        $this->assertArrayHasKey('total_unpaid_amount', $array);
        $this->assertArrayHasKey('total_payments_wave', $array);
        $this->assertArrayHasKey('total_payments_om', $array);
        $this->assertArrayHasKey('total_payments_cash', $array);
    }

    // =========================================================================
    // Weekly period
    // =========================================================================

    public function test_weekly_period_aggregates_invoices_across_all_days_in_the_week(): void
    {
        $monday = $this->today->copy()->startOfWeek();

        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 1000]], $monday);
        $this->makeInvoiceWithItems($this->commercial, [['quantity' => 1, 'price' => 2000]], $monday->copy()->addDays(3));

        $report = $this->salesInvoiceStatsService->buildCommercialActivityReport(
            $this->commercial,
            $monday->copy()->startOfDay(),
            $monday->copy()->endOfWeek(),
        );

        $this->assertEquals(3000, $report->totalSales);
    }
}
