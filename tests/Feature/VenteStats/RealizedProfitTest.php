<?php

namespace Tests\Feature\VenteStats;

use App\Data\Vente\VenteStatsFilter;
use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for realized profit calculation:
 *
 *  1. SalesInvoice::computeRealizedProfitForPaymentAmount() — the core formula
 *  2. Payment::profit auto-population via the creating model event
 *  3. SalesInvoiceService::totalRealizedProfits() — the aggregate query
 *
 * "Realized profit" = the profit actually earned from money received,
 * proportional to the share of the invoice that has been paid.
 * Formula: invoice_total_profit / invoice_total × payment_amount
 */
class RealizedProfitTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceService $salesInvoiceService;

    private Team $defaultTeam;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceService = new SalesInvoiceService;
        $this->defaultTeam = $this->makeTeamWithManager();
        $this->defaultCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $this->defaultCustomer = $this->makeCustomerForCommercial($this->defaultCommercial);
        $this->defaultProduct = $this->makeProduct(price: 1000, costPrice: 600);
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

    private function makeTeamWithManager(): Team
    {
        return Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeCommercialForTeam(Team $team): Commercial
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

    private function makeCustomerForCommercial(Commercial $commercial): Customer
    {
        return Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);
    }

    private function makeActiveCarLoadForTeam(Team $team): CarLoad
    {
        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => 'ACTIVE',
            'load_date' => Carbon::now()->subDays(2),
            'return_date' => Carbon::now()->addDays(2),
            'returned' => false,
        ]);
    }

    /**
     * Create a SalesInvoice with one INVOICE_ITEM vente.
     * Returns the invoice with items loaded.
     *
     * @param  int  $price  Sale price per unit
     * @param  int  $quantity  Number of units
     * @param  int  $profit  Profit stored on the vente (price - cost) × qty
     */
    private function makeInvoiceWithOneItem(
        int $price,
        int $quantity,
        int $profit,
        ?Commercial $commercial = null,
        ?Customer $customer = null,
        ?CarLoad $carLoad = null,
    ): SalesInvoice {
        $commercial ??= $this->defaultCommercial;
        $customer ??= $this->defaultCustomer;

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'paid' => false,
            'car_load_id' => $carLoad?->id,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'commercial_id' => $commercial->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => $profit,
            'paid' => false,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh(['items']);
    }

    /**
     * Create a Payment directly against a SalesInvoice (bypasses Service layer intentionally
     * to test that the model event fires regardless of the code path).
     */
    private function makePayment(SalesInvoice $invoice, int $amount): Payment
    {
        return Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makePaymentOnDate(SalesInvoice $invoice, int $amount, Carbon $date): Payment
    {
        $payment = $this->makePayment($invoice, $amount);
        $payment->created_at = $date->copy()->startOfDay()->addHours(9);
        $payment->save();

        return $payment;
    }

    // =========================================================================
    // SalesInvoice::computeRealizedProfitForPaymentAmount()
    // =========================================================================

    public function test_compute_realized_profit_returns_full_profit_when_full_invoice_is_paid(): void
    {
        // Invoice: 2 × 1000 = 2000 total, profit = 800 (40% margin)
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 800);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(2000);

        $this->assertSame(800, $realizedProfit);
    }

    public function test_compute_realized_profit_is_proportional_for_partial_payment(): void
    {
        // Invoice: 4000 total, profit = 1200 (30% margin)
        // Payment of 1000 = 25% of invoice → realized profit = 300
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1200);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(1000);

        $this->assertSame(300, $realizedProfit);
    }

    public function test_compute_realized_profit_returns_zero_for_zero_payment_amount(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 400);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(0);

        $this->assertSame(0, $realizedProfit);
    }

    public function test_compute_realized_profit_returns_zero_when_invoice_total_is_zero(): void
    {
        // Empty invoice — no items → total = 0, must not divide by zero
        $invoice = SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $this->defaultCommercial->id,
            'paid' => false,
        ]);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(500);

        $this->assertSame(0, $realizedProfit);
    }

    public function test_compute_realized_profit_returns_zero_when_invoice_has_zero_profit_margin(): void
    {
        // Selling at cost — profit = 0
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 0);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(2000);

        $this->assertSame(0, $realizedProfit);
    }

    public function test_compute_realized_profit_rounds_to_nearest_integer(): void
    {
        // Invoice: 3000 total, profit = 1000 (33.33...% margin)
        // Payment of 1000 → 1000/3000 × 1000 = 333.33... → rounds to 333
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 3, profit: 1000);

        $realizedProfit = $invoice->computeRealizedProfitForPaymentAmount(1000);

        $this->assertSame(333, $realizedProfit);
    }

    public function test_compute_realized_profit_three_partial_payments_sum_close_to_total_profit(): void
    {
        // Invoice: 3000 total, profit = 900 (30% margin)
        // Three equal payments of 1000 each → each realizes 300 → total = 900
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 3, profit: 900);

        $first = $invoice->computeRealizedProfitForPaymentAmount(1000);
        $second = $invoice->computeRealizedProfitForPaymentAmount(1000);
        $third = $invoice->computeRealizedProfitForPaymentAmount(1000);

        $this->assertSame(300, $first);
        $this->assertSame(300, $second);
        $this->assertSame(300, $third);
        $this->assertSame(900, $first + $second + $third);
    }

    // =========================================================================
    // Payment::profit auto-population (model creating event)
    // =========================================================================

    public function test_payment_profit_is_auto_populated_on_creation(): void
    {
        // Invoice: 2000 total, 800 profit (40% margin)
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 800);

        $payment = $this->makePayment($invoice, 2000);

        $this->assertSame(800, $payment->fresh()->profit);
    }

    public function test_payment_profit_is_proportional_for_partial_payment(): void
    {
        // Invoice: 4000 total, 1200 profit (30% margin)
        // Pay 1000 (25%) → realized profit = 300
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1200);

        $payment = $this->makePayment($invoice, 1000);

        $this->assertSame(300, $payment->fresh()->profit);
    }

    public function test_payment_profit_is_zero_for_invoice_with_no_profit_margin(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 0);

        $payment = $this->makePayment($invoice, 2000);

        $this->assertSame(0, $payment->fresh()->profit);
    }

    public function test_payment_profit_is_zero_when_no_sales_invoice_is_attached(): void
    {
        // Order payment — no sales_invoice_id
        $payment = Payment::create([
            'order_id' => null,
            'sales_invoice_id' => null,
            'amount' => 5000,
            'payment_method' => 'Cash',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertSame(0, $payment->fresh()->profit);
    }

    public function test_multiple_partial_payments_each_get_correct_profit(): void
    {
        // Invoice: 4000 total, 1000 profit (25% margin)
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);

        $firstPayment = $this->makePayment($invoice, 2000); // 50% → 500
        $secondPayment = $this->makePayment($invoice, 2000); // 50% → 500

        $this->assertSame(500, $firstPayment->fresh()->profit);
        $this->assertSame(500, $secondPayment->fresh()->profit);
        $this->assertSame(1000, $firstPayment->fresh()->profit + $secondPayment->fresh()->profit);
    }

    // =========================================================================
    // SalesInvoiceService::totalRealizedProfits()
    // =========================================================================

    public function test_total_realized_profits_returns_zero_when_no_payments_exist(): void
    {
        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(0, $result);
    }

    public function test_total_realized_profits_returns_full_profit_when_invoice_fully_paid(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 600);
        $this->makePayment($invoice, 2000);

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(600, $result);
    }

    public function test_total_realized_profits_returns_partial_profit_for_partial_payment(): void
    {
        // Invoice: 4000 total, 1200 profit. Pay half.
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1200);
        $this->makePayment($invoice, 2000); // 50% of 4000

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(600, $result);
    }

    public function test_total_realized_profits_sums_across_multiple_payments_on_same_invoice(): void
    {
        // Invoice: 4000 total, 1000 profit. Two payments of 2000 each.
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);
        $this->makePayment($invoice, 2000); // 500
        $this->makePayment($invoice, 2000); // 500

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(1000, $result);
    }

    public function test_total_realized_profits_sums_across_multiple_invoices(): void
    {
        $invoiceA = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 400); // 2000 total, 400 profit
        $invoiceB = $this->makeInvoiceWithOneItem(price: 3000, quantity: 1, profit: 900); // 3000 total, 900 profit

        $this->makePayment($invoiceA, 2000); // full → 400
        $this->makePayment($invoiceB, 1500); // 50% → 450

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(850, $result);
    }

    public function test_total_realized_profits_always_returns_an_integer(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 400);
        $this->makePayment($invoice, 1000);

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertIsInt($result);
    }

    // =========================================================================
    // totalRealizedProfits — date range filters
    // =========================================================================

    public function test_total_realized_profits_date_range_excludes_payments_outside_range(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);

        $this->makePaymentOnDate($invoice, 2000, Carbon::now()->subDays(10)); // outside → 500
        $this->makePaymentOnDate($invoice, 2000, Carbon::now()->subDays(2));  // within  → 500

        $result = $this->salesInvoiceService->totalRealizedProfits(
            Carbon::now()->subDays(5), Carbon::now(),
            VenteStatsFilter::new()
        );

        $this->assertSame(500, $result);
    }

    public function test_total_realized_profits_null_dates_include_all_payments(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 600);

        $this->makePaymentOnDate($invoice, 1000, Carbon::now()->subYears(1));
        $this->makePaymentOnDate($invoice, 1000, Carbon::now());

        $result = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(600, $result);
    }

    // =========================================================================
    // totalRealizedProfits — commercialId filter
    // =========================================================================

    public function test_total_realized_profits_commercial_id_filter_scopes_to_that_commercial(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $invoiceA = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 400);
        $invoiceB = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 800, commercial: $otherCommercial, customer: $otherCustomer);

        $this->makePayment($invoiceA, 2000); // 400
        $this->makePayment($invoiceB, 2000); // 800

        $result = $this->salesInvoiceService->totalRealizedProfits(
            null, null,
            VenteStatsFilter::new()->thatAreMadeByCommercial($this->defaultCommercial->id)
        );

        $this->assertSame(400, $result);
    }

    // =========================================================================
    // totalRealizedProfits — customerId filter
    // =========================================================================

    public function test_total_realized_profits_customer_id_filter_scopes_to_that_customer(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $invoiceA = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 400);
        $invoiceB = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 600, commercial: $otherCommercial, customer: $otherCustomer);

        $this->makePayment($invoiceA, 2000); // 400
        $this->makePayment($invoiceB, 2000); // 600

        $result = $this->salesInvoiceService->totalRealizedProfits(
            null, null,
            VenteStatsFilter::new()->forCustomer($this->defaultCustomer->id)
        );

        $this->assertSame(400, $result);
    }

    // =========================================================================
    // totalRealizedProfits — carLoadId filter
    // =========================================================================

    public function test_total_realized_profits_car_load_id_filter_scopes_to_invoices_belonging_to_that_car_load(): void
    {
        $targetCarLoad = $this->makeActiveCarLoadForTeam($this->defaultTeam);

        $invoiceA = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 400, carLoad: $targetCarLoad); // ✓ linked to target car load
        $invoiceB = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 800); // ✗ no car_load_id

        $this->makePayment($invoiceA, 2000); // realized profit: 400
        $this->makePayment($invoiceB, 2000); // realized profit: 800 — must be excluded

        $result = $this->salesInvoiceService->totalRealizedProfits(
            null, null,
            VenteStatsFilter::new()->thatAreInCarLoad($targetCarLoad->id)
        );

        $this->assertSame(400, $result);
    }

    // =========================================================================
    // totalRealizedProfits — combined filters
    // =========================================================================

    public function test_total_realized_profits_combined_commercial_and_date_range(): void
    {
        $otherCommercial = $this->makeCommercialForTeam($this->defaultTeam);
        $otherCustomer = $this->makeCustomerForCommercial($otherCommercial);

        $invoiceA = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 600);
        $invoiceB = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 999, commercial: $otherCommercial, customer: $otherCustomer);

        $this->makePaymentOnDate($invoiceA, 2000, Carbon::now()->subDays(2));  // ✓ within range, right commercial → 600
        $this->makePaymentOnDate($invoiceA, 2000, Carbon::now()->subDays(15)); // ✗ outside range
        $this->makePaymentOnDate($invoiceB, 2000, Carbon::now()->subDays(2));  // ✗ wrong commercial

        $result = $this->salesInvoiceService->totalRealizedProfits(
            Carbon::now()->subDays(5), Carbon::now(),
            VenteStatsFilter::new()->thatAreMadeByCommercial($this->defaultCommercial->id)
        );

        $this->assertSame(600, $result);
    }

    // =========================================================================
    // Relationship: totalProfits vs totalRealizedProfits
    // =========================================================================

    public function test_realized_profits_is_always_less_than_or_equal_to_total_potential_profits(): void
    {
        // Invoice half paid — realized must be ≤ potential
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);
        $this->makePayment($invoice, 2000); // 50% → 500 realized

        $potential = $this->salesInvoiceService->totalEstimatedProfits(null, null, VenteStatsFilter::new());
        $realized = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(1000, $potential);
        $this->assertSame(500, $realized);
        $this->assertLessThanOrEqual($potential, $realized);
    }

    public function test_realized_profits_equals_total_profits_when_all_invoices_are_fully_paid(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 600);
        $this->makePayment($invoice, 2000); // full payment

        $potential = $this->salesInvoiceService->totalEstimatedProfits(null, null, VenteStatsFilter::new());
        $realized = $this->salesInvoiceService->totalRealizedProfits(null, null, VenteStatsFilter::new());

        $this->assertSame(600, $potential);
        $this->assertSame(600, $realized);
    }
}
