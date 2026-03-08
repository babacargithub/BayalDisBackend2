<?php /** @noinspection PhpRedundantOptionalArgumentInspection */

namespace Tests\Feature\Dashboard;

use App\Data\Dashboard\DashboardStats;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Depense;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\TypeDepense;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SalesInvoiceService::buildStatsForPeriod() — the method that powers
 * all four Dashboard stat blocks (daily / weekly / monthly / all-time).
 *
 * Every field in DashboardStats is covered:
 *  - salesInvoicesCount
 *  - fullyPaidSalesInvoicesCount
 *  - partiallyPaidSalesInvoicesCount
 *  - unpaidSalesInvoicesCount
 *  - totalSales
 *  - totalEstimatedProfit
 *  - totalRealizedProfit
 *  - totalPaymentsReceived
 *  - totalExpenses
 *  - totalCustomers / totalProspects / totalConfirmedCustomers
 *
 * These values are the single source of truth for what is displayed in the UI.
 * A regression in any field means the dashboard shows incorrect financial data.
 */
class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceService $salesInvoiceService;

    private Commercial $defaultCommercial;

    private Customer $defaultCustomer;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceService = new SalesInvoiceService;
        $defaultTeam = $this->makeTeamWithManager();
        $this->defaultCommercial = $this->makeCommercialForTeam($defaultTeam);
        $this->defaultCustomer = $this->makeCustomerForCommercial($this->defaultCommercial);
        $this->defaultProduct = $this->makeProduct(price: 1000, costPrice: 600);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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

    private function makeCustomerForCommercial(Commercial $commercial, bool $isProspect = false): Customer
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

    private function makeProduct(int $price = 1000, int $costPrice = 500): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => 1,
        ]);
    }

    /**
     * Create a SalesInvoice with a single INVOICE_ITEM vente.
     * Returns the invoice fresh with items and payments loaded.
     */
    private function makeInvoiceWithOneItem(
        int $price,
        int $quantity,
        int $profit,
        bool $paid = false,
        ?Commercial $commercial = null,
        ?Customer $customer = null,
    ): SalesInvoice {
        $commercial ??= $this->defaultCommercial;
        $customer ??= $this->defaultCustomer;

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $commercial->id,
            'paid' => $paid,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'commercial_id' => $commercial->id,
            'quantity' => $quantity,
            'price' => $price,
            'profit' => $profit,
            'paid' => $paid,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh(['items', 'payments']);
    }

    /**
     * Backdate an invoice and all its vente items to a specific date.
     */
    private function backdateInvoice(SalesInvoice $invoice, Carbon $date): SalesInvoice
    {
        $timestamp = $date->copy()->startOfDay()->addHours(9);
        $invoice->created_at = $timestamp;
        $invoice->save();

        $invoice->items()->each(function (Vente $vente) use ($timestamp) {
            $vente->created_at = $timestamp;
            $vente->save();
        });

        return $invoice->fresh(['items', 'payments']);
    }

    private function makePayment(SalesInvoice $invoice, int $amount): Payment
    {
        return Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function backdatePayment(Payment $payment, Carbon $date): Payment
    {
        $payment->created_at = $date->copy()->startOfDay()->addHours(10);
        $payment->save();

        return $payment->fresh();
    }

    private function makeDepense(int $amount): Depense
    {
        $typeDepense = TypeDepense::firstOrCreate(['name' => 'Test Type']);

        return Depense::create([
            'amount' => $amount,
            'type_depense_id' => $typeDepense->id,
            'comment' => 'Test expense',
        ]);
    }

    private function backdateDepense(Depense $depense, Carbon $date): Depense
    {
        $depense->created_at = $date->copy()->startOfDay()->addHours(8);
        $depense->save();

        return $depense->fresh();
    }

    private function buildStats(?Carbon $startDate, ?Carbon $endDate): DashboardStats
    {
        return $this->salesInvoiceService->buildStatsForPeriod($startDate, $endDate);
    }

    // =========================================================================
    // Returns a DashboardStats instance
    // =========================================================================

    public function test_build_stats_for_period_returns_a_dashboard_stats_instance(): void
    {
        $result = $this->buildStats(null, null);

        /** @noinspection PhpConditionAlreadyCheckedInspection */
        $this->assertInstanceOf(DashboardStats::class, $result);
    }

    // =========================================================================
    // Zero state — empty database
    // =========================================================================

    public function test_all_financial_fields_are_zero_when_no_invoices_payments_or_expenses_exist(): void
    {
        // setUp() creates one customer, commercial, and team — financial fields must still be zero.
        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->salesInvoicesCount);
        $this->assertSame(0, $stats->fullyPaidSalesInvoicesCount);
        $this->assertSame(0, $stats->partiallyPaidSalesInvoicesCount);
        $this->assertSame(0, $stats->unpaidSalesInvoicesCount);
        $this->assertSame(0, $stats->totalSales);
        $this->assertSame(0, $stats->totalEstimatedProfit);
        $this->assertSame(0, $stats->totalRealizedProfit);
        $this->assertSame(0, $stats->totalPaymentsReceived);
        $this->assertSame(0, $stats->totalExpenses);
    }

    // =========================================================================
    // salesInvoicesCount
    // =========================================================================

    public function test_sales_invoices_count_reflects_total_number_of_invoices(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 400);

        $stats = $this->buildStats(null, null);

        $this->assertSame(2, $stats->salesInvoicesCount);
    }

    public function test_sales_invoices_count_excludes_invoices_outside_date_range(): void
    {
        $insideRange = Carbon::now()->subDays(2);
        $outsideRange = Carbon::now()->subDays(20);

        $invoiceInside = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceInside, $insideRange);

        $invoiceOutside = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceOutside, $outsideRange);

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(1, $stats->salesInvoicesCount);
    }

    // =========================================================================
    // fullyPaidSalesInvoicesCount
    // =========================================================================

    public function test_fully_paid_sales_invoices_count_only_counts_paid_true_invoices(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(2, $stats->fullyPaidSalesInvoicesCount);
    }

    public function test_fully_paid_sales_invoices_count_is_zero_when_no_paid_invoices_exist(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->fullyPaidSalesInvoicesCount);
    }

    // =========================================================================
    // partiallyPaidSalesInvoicesCount
    // =========================================================================

    public function test_partially_paid_sales_invoices_count_requires_paid_false_with_at_least_one_payment(): void
    {
        // paid=false + has payment → partially paid
        $invoicePartial = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: false);
        $this->makePayment($invoicePartial, 1000);

        // paid=false + no payment → unpaid, not partially paid
        $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: false);

        // paid=true → fully paid, not partially paid
        $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: true);

        $stats = $this->buildStats(null, null);

        $this->assertSame(1, $stats->partiallyPaidSalesInvoicesCount);
    }

    public function test_partially_paid_sales_invoices_count_is_zero_when_no_partial_invoices_exist(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->partiallyPaidSalesInvoicesCount);
    }

    // =========================================================================
    // unpaidSalesInvoicesCount
    // =========================================================================

    public function test_unpaid_sales_invoices_count_requires_paid_false_with_no_payments(): void
    {
        // paid=false + no payment → unpaid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        // paid=false + has payment → partially paid, not unpaid
        $invoicePartial = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);
        $this->makePayment($invoicePartial, 500);

        // paid=true → fully paid, not unpaid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);

        $stats = $this->buildStats(null, null);

        $this->assertSame(2, $stats->unpaidSalesInvoicesCount);
    }

    public function test_unpaid_sales_invoices_count_is_zero_when_all_invoices_have_a_payment_or_are_paid(): void
    {
        $invoicePartial = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: false);
        $this->makePayment($invoicePartial, 1000);

        $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: true);

        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->unpaidSalesInvoicesCount);
    }

    // =========================================================================
    // Invoice payment status counts are mutually exclusive and sum to total
    // =========================================================================

    /** @noinspection PhpRedundantOptionalArgumentInspection */
    public function test_fully_paid_partially_paid_and_unpaid_counts_sum_to_total_invoices_count(): void
    {
        // 2 fully paid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: true);

        // 1 partially paid
        $invoicePartial = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400, paid: false);
        $this->makePayment($invoicePartial, 1000);

        // 2 unpaid
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(5, $stats->salesInvoicesCount);
        $this->assertSame(2, $stats->fullyPaidSalesInvoicesCount);
        $this->assertSame(1, $stats->partiallyPaidSalesInvoicesCount);
        $this->assertSame(2, $stats->unpaidSalesInvoicesCount);
        $this->assertSame(
            $stats->salesInvoicesCount,
            $stats->fullyPaidSalesInvoicesCount
            + $stats->partiallyPaidSalesInvoicesCount
            + $stats->unpaidSalesInvoicesCount,
            'The three invoice payment status counts must sum to the total invoice count'
        );
    }

    // =========================================================================
    // totalSales
    // =========================================================================

    public function test_total_sales_equals_sum_of_price_times_quantity_across_all_invoice_items(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 200); // 2 000
        $this->makeInvoiceWithOneItem(price: 500, quantity: 4, profit: 100); // 2 000

        $stats = $this->buildStats(null, null);

        $this->assertSame(4000, $stats->totalSales);
    }

    public function test_total_sales_is_zero_when_no_invoices_exist(): void
    {
        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->totalSales);
    }

    public function test_total_sales_excludes_invoice_items_outside_date_range(): void
    {
        $insideRange = Carbon::now()->subDays(2);
        $outsideRange = Carbon::now()->subDays(20);

        $invoiceInside = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($invoiceInside, $insideRange);

        $invoiceOutside = $this->makeInvoiceWithOneItem(price: 9000, quantity: 1, profit: 999);
        $this->backdateInvoice($invoiceOutside, $outsideRange);

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(1000, $stats->totalSales);
    }

    // =========================================================================
    // totalEstimatedProfit
    // =========================================================================

    public function test_total_estimated_profit_equals_sum_of_profit_across_all_vente_items(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 300);
        $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 700);

        $stats = $this->buildStats(null, null);

        $this->assertSame(1000, $stats->totalEstimatedProfit);
    }

    public function test_total_estimated_profit_includes_both_paid_and_unpaid_invoices(): void
    {
        // Profit is potential — it must include invoices regardless of payment status
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 300, paid: true);
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 500, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(800, $stats->totalEstimatedProfit);
    }

    public function test_total_estimated_profit_excludes_items_outside_date_range(): void
    {
        $insideRange = Carbon::now()->subDays(2);
        $outsideRange = Carbon::now()->subDays(20);

        $invoiceInside = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 300);
        $this->backdateInvoice($invoiceInside, $insideRange);

        $invoiceOutside = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 999);
        $this->backdateInvoice($invoiceOutside, $outsideRange);

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(300, $stats->totalEstimatedProfit);
    }

    // =========================================================================
    // totalRealizedProfit
    // =========================================================================

    public function test_total_realized_profit_equals_sum_of_payment_profits(): void
    {
        // Invoice: 2000 total, 800 profit (40% margin). Full payment → 800 realized.
        $invoiceA = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 800);
        $this->makePayment($invoiceA, 2000);

        // Invoice: 3000 total, 600 profit (20% margin). Half-payment → 300 realized.
        $invoiceB = $this->makeInvoiceWithOneItem(price: 1000, quantity: 3, profit: 600);
        $this->makePayment($invoiceB, 1500);

        $stats = $this->buildStats(null, null);

        $this->assertSame(1100, $stats->totalRealizedProfit);
    }

    public function test_total_realized_profit_is_zero_when_no_payments_have_been_made(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 400, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->totalRealizedProfit);
    }

    public function test_total_realized_profit_is_always_less_than_or_equal_to_total_estimated_profit(): void
    {
        // Half-paid invoice — realized must be ≤ estimated
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);
        $this->makePayment($invoice, 2000); // 50% of 4000

        $stats = $this->buildStats(null, null);

        $this->assertSame(1000, $stats->totalEstimatedProfit);
        $this->assertSame(500, $stats->totalRealizedProfit);
        $this->assertLessThanOrEqual($stats->totalEstimatedProfit, $stats->totalRealizedProfit);
    }

    public function test_total_realized_profit_equals_estimated_profit_when_invoice_is_fully_paid(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 2, profit: 600);
        $this->makePayment($invoice, 2000); // full payment

        $stats = $this->buildStats(null, null);

        $this->assertSame(600, $stats->totalEstimatedProfit);
        $this->assertSame(600, $stats->totalRealizedProfit);
    }

    public function test_total_realized_profit_excludes_payments_outside_date_range(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 1000);

        $paymentInside = $this->makePayment($invoice, 2000);
        $this->backdatePayment($paymentInside, Carbon::now()->subDays(2)); // within → 500

        $paymentOutside = $this->makePayment($invoice, 2000);
        $this->backdatePayment($paymentOutside, Carbon::now()->subDays(20)); // outside → excluded

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(500, $stats->totalRealizedProfit);
    }

    // =========================================================================
    // totalPaymentsReceived
    // =========================================================================

    public function test_total_payments_received_equals_sum_of_all_payment_amounts_in_period(): void
    {
        $invoiceA = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $invoiceB = $this->makeInvoiceWithOneItem(price: 3000, quantity: 1, profit: 600);

        $this->makePayment($invoiceA, 2000);
        $this->makePayment($invoiceB, 1500);

        $stats = $this->buildStats(null, null);

        $this->assertSame(3500, $stats->totalPaymentsReceived);
    }

    public function test_total_payments_received_is_zero_when_no_payments_exist(): void
    {
        $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200, paid: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->totalPaymentsReceived);
    }

    public function test_total_payments_received_excludes_payments_outside_date_range(): void
    {
        $invoice = $this->makeInvoiceWithOneItem(price: 3000, quantity: 1, profit: 600);

        $paymentInside = $this->makePayment($invoice, 2000);
        $this->backdatePayment($paymentInside, Carbon::now()->subDays(2));

        $paymentOutside = $this->makePayment($invoice, 1000);
        $this->backdatePayment($paymentOutside, Carbon::now()->subDays(20));

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(2000, $stats->totalPaymentsReceived);
    }

    public function test_total_payments_received_is_always_greater_than_or_equal_to_total_realized_profit(): void
    {
        // Cash received always ≥ profit since profit is a fraction of revenue
        $invoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 600);
        $this->makePayment($invoice, 2000);

        $stats = $this->buildStats(null, null);

        $this->assertGreaterThanOrEqual($stats->totalRealizedProfit, $stats->totalPaymentsReceived);
    }

    // =========================================================================
    // totalExpenses
    // =========================================================================

    public function test_total_expenses_equals_sum_of_all_depense_amounts_in_period(): void
    {
        $this->makeDepense(5000);
        $this->makeDepense(3000);

        $stats = $this->buildStats(null, null);

        $this->assertSame(8000, $stats->totalExpenses);
    }

    public function test_total_expenses_is_zero_when_no_depenses_exist(): void
    {
        $stats = $this->buildStats(null, null);

        $this->assertSame(0, $stats->totalExpenses);
    }

    public function test_total_expenses_excludes_depenses_outside_date_range(): void
    {
        $depenseInside = $this->makeDepense(4000);
        $this->backdateDepense($depenseInside, Carbon::now()->subDays(2));

        $depenseOutside = $this->makeDepense(9000);
        $this->backdateDepense($depenseOutside, Carbon::now()->subDays(20));

        $stats = $this->buildStats(Carbon::now()->subDays(5), Carbon::now());

        $this->assertSame(4000, $stats->totalExpenses);
    }

    // =========================================================================
    // totalCustomers / totalProspects / totalConfirmedCustomers
    // =========================================================================

    public function test_total_customers_counts_all_customers_created_in_period(): void
    {
        // setUp() creates one customer; create two more
        $this->makeCustomerForCommercial($this->defaultCommercial);
        $this->makeCustomerForCommercial($this->defaultCommercial);

        $stats = $this->buildStats(null, null);

        $this->assertSame(3, $stats->totalCustomers);
    }

    public function test_total_prospects_counts_only_is_prospect_true_customers(): void
    {
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: true);
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(1, $stats->totalProspects);
    }

    public function test_total_confirmed_customers_counts_only_is_prospect_false_customers(): void
    {
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: true);
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: false);

        $stats = $this->buildStats(null, null);

        // setUp() creates one non-prospect plus one more here = 2
        $this->assertSame(2, $stats->totalConfirmedCustomers);
    }

    public function test_prospects_and_confirmed_customers_always_sum_to_total_customers(): void
    {
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: true);
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: true);
        $this->makeCustomerForCommercial($this->defaultCommercial, isProspect: false);

        $stats = $this->buildStats(null, null);

        $this->assertSame(
            $stats->totalCustomers,
            $stats->totalProspects + $stats->totalConfirmedCustomers,
            'Prospects + confirmed customers must always equal total customers'
        );
    }

    // =========================================================================
    // Full period isolation — data outside the range must never bleed in
    // =========================================================================

    public function test_all_financial_fields_are_zero_when_all_data_is_outside_the_given_date_range(): void
    {
        $outsideRange = Carbon::now()->subDays(30);
        $rangeStart = Carbon::now()->subDays(5);
        $rangeEnd = Carbon::now();

        $invoice = $this->makeInvoiceWithOneItem(price: 5000, quantity: 2, profit: 2000);
        $this->backdateInvoice($invoice, $outsideRange);

        $payment = $this->makePayment($invoice->fresh(), 10000);
        $this->backdatePayment($payment, $outsideRange);

        $depense = $this->makeDepense(8000);
        $this->backdateDepense($depense, $outsideRange);

        $stats = $this->buildStats($rangeStart, $rangeEnd);

        $this->assertSame(0, $stats->salesInvoicesCount);
        $this->assertSame(0, $stats->fullyPaidSalesInvoicesCount);
        $this->assertSame(0, $stats->partiallyPaidSalesInvoicesCount);
        $this->assertSame(0, $stats->unpaidSalesInvoicesCount);
        $this->assertSame(0, $stats->totalSales);
        $this->assertSame(0, $stats->totalEstimatedProfit);
        $this->assertSame(0, $stats->totalRealizedProfit);
        $this->assertSame(0, $stats->totalPaymentsReceived);
        $this->assertSame(0, $stats->totalExpenses);
    }

    // =========================================================================
    // All-time (null dates) — returns all data regardless of creation date
    // =========================================================================

    public function test_null_dates_include_data_from_all_time(): void
    {
        $veryOldDate = Carbon::now()->subYears(2);
        $recentDate = Carbon::now()->subDays(2);

        $oldInvoice = $this->makeInvoiceWithOneItem(price: 1000, quantity: 1, profit: 200);
        $this->backdateInvoice($oldInvoice, $veryOldDate);

        $recentInvoice = $this->makeInvoiceWithOneItem(price: 2000, quantity: 1, profit: 400);
        $this->backdateInvoice($recentInvoice, $recentDate);

        $stats = $this->buildStats(null, null);

        $this->assertSame(2, $stats->salesInvoicesCount);
        $this->assertSame(3000, $stats->totalSales);
        $this->assertSame(600, $stats->totalEstimatedProfit);
    }

    // =========================================================================
    // toSnakeCaseArray — keys and values must match what the Vue Dashboard expects
    // =========================================================================

    public function test_to_snake_case_array_contains_all_keys_that_the_vue_dashboard_component_uses(): void
    {
        $stats = $this->buildStats(null, null);
        $array = $stats->toSnakeCaseArray();

        $expectedKeys = [
            'total_customers',
            'total_prospects',
            'total_confirmed_customers',
            'sales_invoices_count',
            'fully_paid_sales_invoices_count',
            'partially_paid_sales_invoices_count',
            'unpaid_sales_invoices_count',
            'total_sales',
            'total_estimated_profit',
            'total_realized_profit',
            'total_payments_received',
            'total_expenses',
        ];

        foreach ($expectedKeys as $expectedKey) {
            $this->assertArrayHasKey(
                $expectedKey,
                $array,
                "Missing key '$expectedKey' — Vue Dashboard will receive null and display nothing for this field"
            );
        }
    }

    public function test_to_snake_case_array_values_match_the_dto_properties(): void
    {
        $invoiceFullyPaid = $this->makeInvoiceWithOneItem(price: 2000, quantity: 2, profit: 800, paid: true);
        $this->makePayment($invoiceFullyPaid, 4000);
        $this->makeDepense(3000);

        $stats = $this->buildStats(null, null);
        $array = $stats->toSnakeCaseArray();

        $this->assertSame($stats->salesInvoicesCount, $array['sales_invoices_count']);
        $this->assertSame($stats->fullyPaidSalesInvoicesCount, $array['fully_paid_sales_invoices_count']);
        $this->assertSame($stats->partiallyPaidSalesInvoicesCount, $array['partially_paid_sales_invoices_count']);
        $this->assertSame($stats->unpaidSalesInvoicesCount, $array['unpaid_sales_invoices_count']);
        $this->assertSame($stats->totalSales, $array['total_sales']);
        $this->assertSame($stats->totalEstimatedProfit, $array['total_estimated_profit']);
        $this->assertSame($stats->totalRealizedProfit, $array['total_realized_profit']);
        $this->assertSame($stats->totalPaymentsReceived, $array['total_payments_received']);
        $this->assertSame($stats->totalExpenses, $array['total_expenses']);
        $this->assertSame($stats->totalCustomers, $array['total_customers']);
        $this->assertSame($stats->totalProspects, $array['total_prospects']);
        $this->assertSame($stats->totalConfirmedCustomers, $array['total_confirmed_customers']);
    }
}
