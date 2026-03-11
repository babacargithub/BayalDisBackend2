<?php

namespace Tests\Feature\SalesInvoice;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SalesInvoiceService per-invoice calculation methods.
 *
 * Each of the four methods — calculateTotalAmountForInvoice,
 * calculateTotalEstimatedProfitForInvoice, calculateTotalPaymentsForInvoice,
 * calculateTotalRealizedProfitForInvoice — is verified against:
 *   - the zero / empty case (no items or no payments)
 *   - the single-record happy path
 *   - accumulation across multiple records
 *   - isolation (data from other invoices must not contaminate the result)
 *
 * These tests cover the underlying SQL queries independently of the
 * recalculateStoredTotals() hook mechanism.
 */
class SalesInvoiceServiceCalculationMethodsTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoiceStatsService $salesInvoiceStatsService;

    private Customer $defaultCustomer;

    private Commercial $defaultCommercial;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceStatsService = app(SalesInvoiceStatsService::class);

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->defaultCommercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
        ]);
        $this->defaultCommercial->team()->associate($team);
        $this->defaultCommercial->save();

        $this->defaultCustomer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->defaultCommercial->id,
        ]);

        $this->defaultProduct = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeEmptyInvoice(): SalesInvoice
    {
        return SalesInvoice::create([
            'customer_id' => $this->defaultCustomer->id,
            'commercial_id' => $this->defaultCommercial->id,
        ]);
    }

    /**
     * Insert a vente directly into the DB without triggering the Vente::saved hook,
     * so we can test the calculation methods in isolation without side-effects.
     */
    private function insertInvoiceItemDirectly(
        SalesInvoice $invoice,
        int $price,
        int $quantity,
        int $profit,
    ): void {
        \Illuminate\Support\Facades\DB::table('ventes')->insert([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => $profit,
            'type' => Vente::TYPE_INVOICE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert a payment directly into the DB without triggering model events,
     * so we can control the profit value precisely for realized-profit tests.
     */
    private function insertPaymentDirectly(SalesInvoice $invoice, int $amount, int $profit): void
    {
        \Illuminate\Support\Facades\DB::table('payments')->insert([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'profit' => $profit,
            'payment_method' => 'Cash',
            'user_id' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================================
    // calculateTotalAmountForInvoice
    // =========================================================================

    public function test_calculate_total_amount_for_invoice_returns_zero_when_invoice_has_no_items(): void
    {
        $invoice = $this->makeEmptyInvoice();

        $this->assertSame(0, $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice));
    }

    public function test_calculate_total_amount_for_invoice_returns_price_times_quantity_for_single_item(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 3, profit: 600);

        $this->assertSame(6000, $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice));
    }

    public function test_calculate_total_amount_for_invoice_accumulates_across_multiple_items(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 1000, quantity: 2, profit: 200); // 2 000
        $this->insertInvoiceItemDirectly($invoice, price: 500, quantity: 4, profit: 100);  // 2 000

        $this->assertSame(4000, $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice));
    }

    public function test_calculate_total_amount_for_invoice_is_isolated_from_other_invoices(): void
    {
        $invoiceUnderTest = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoiceUnderTest, price: 1000, quantity: 1, profit: 200);

        $otherInvoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($otherInvoice, price: 9999, quantity: 99, profit: 5000);

        $this->assertSame(1000, $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoiceUnderTest));
    }

    // =========================================================================
    // calculateTotalEstimatedProfitForInvoice
    // =========================================================================

    public function test_calculate_total_estimated_profit_for_invoice_returns_zero_when_invoice_has_no_items(): void
    {
        $invoice = $this->makeEmptyInvoice();

        $this->assertSame(0, $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice));
    }

    public function test_calculate_total_estimated_profit_for_invoice_returns_profit_for_single_item(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);

        $this->assertSame(600, $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice));
    }

    public function test_calculate_total_estimated_profit_for_invoice_accumulates_across_multiple_items(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 1000, quantity: 2, profit: 200);
        $this->insertInvoiceItemDirectly($invoice, price: 500, quantity: 4, profit: 300);

        $this->assertSame(500, $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice));
    }

    public function test_calculate_total_estimated_profit_for_invoice_is_isolated_from_other_invoices(): void
    {
        $invoiceUnderTest = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoiceUnderTest, price: 1000, quantity: 1, profit: 200);

        $otherInvoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($otherInvoice, price: 2000, quantity: 1, profit: 9999);

        $this->assertSame(200, $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoiceUnderTest));
    }

    // =========================================================================
    // calculateTotalPaymentsForInvoice
    // =========================================================================

    public function test_calculate_total_payments_for_invoice_returns_zero_when_no_payments_exist(): void
    {
        $invoice = $this->makeEmptyInvoice();

        $this->assertSame(0, $this->salesInvoiceStatsService->calculateTotalPaymentsForInvoice($invoice));
    }

    public function test_calculate_total_payments_for_invoice_returns_amount_for_single_payment(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($invoice, amount: 1500, profit: 300);

        $this->assertSame(1500, $this->salesInvoiceStatsService->calculateTotalPaymentsForInvoice($invoice));
    }

    public function test_calculate_total_payments_for_invoice_accumulates_across_multiple_payments(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($invoice, amount: 800, profit: 160);
        $this->insertPaymentDirectly($invoice, amount: 700, profit: 140);

        $this->assertSame(1500, $this->salesInvoiceStatsService->calculateTotalPaymentsForInvoice($invoice));
    }

    public function test_calculate_total_payments_for_invoice_is_isolated_from_other_invoices(): void
    {
        $invoiceUnderTest = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($invoiceUnderTest, amount: 500, profit: 100);

        $otherInvoice = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($otherInvoice, amount: 99999, profit: 9999);

        $this->assertSame(500, $this->salesInvoiceStatsService->calculateTotalPaymentsForInvoice($invoiceUnderTest));
    }

    // =========================================================================
    // calculateTotalRealizedProfitForInvoice
    // =========================================================================

    public function test_calculate_total_realized_profit_for_invoice_returns_zero_when_no_payments_exist(): void
    {
        $invoice = $this->makeEmptyInvoice();

        $this->assertSame(0, $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice));
    }

    public function test_calculate_total_realized_profit_for_invoice_returns_profit_for_single_payment(): void
    {
        $invoice = $this->makeEmptyInvoice();
        // profit column on the payment is set explicitly to verify the method sums it correctly
        $this->insertPaymentDirectly($invoice, amount: 1000, profit: 300);

        $this->assertSame(300, $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice));
    }

    public function test_calculate_total_realized_profit_for_invoice_accumulates_across_multiple_payments(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($invoice, amount: 1000, profit: 300);
        $this->insertPaymentDirectly($invoice, amount: 500, profit: 150);

        $this->assertSame(450, $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice));
    }

    public function test_calculate_total_realized_profit_for_invoice_is_isolated_from_other_invoices(): void
    {
        $invoiceUnderTest = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($invoiceUnderTest, amount: 1000, profit: 300);

        $otherInvoice = $this->makeEmptyInvoice();
        $this->insertPaymentDirectly($otherInvoice, amount: 9999, profit: 9999);

        $this->assertSame(300, $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoiceUnderTest));
    }

    public function test_calculate_total_realized_profit_for_invoice_returns_zero_when_payments_have_zero_profit(): void
    {
        $invoice = $this->makeEmptyInvoice();
        // A payment on an invoice with no items would yield 0 profit (division-by-zero guard)
        $this->insertPaymentDirectly($invoice, amount: 1000, profit: 0);

        $this->assertSame(0, $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice));
    }

    // =========================================================================
    // Financial invariants
    // =========================================================================

    public function test_realized_profit_never_exceeds_estimated_profit_with_partial_payment(): void
    {
        // total = 2000, estimated profit = 600 (30 % margin); half payment → 300 realized
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);
        $this->insertPaymentDirectly($invoice, amount: 1000, profit: 300);

        $estimatedProfit = $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice);
        $realizedProfit = $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice);

        $this->assertLessThanOrEqual(
            $estimatedProfit,
            $realizedProfit,
            "Realized profit ({$realizedProfit}) must never exceed estimated profit ({$estimatedProfit}).",
        );
    }

    public function test_realized_profit_equals_estimated_profit_but_does_not_exceed_it_when_fully_paid(): void
    {
        // A fully-paid invoice: realized profit should converge to (and not exceed) estimated profit.
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);
        $this->insertPaymentDirectly($invoice, amount: 2000, profit: 600);

        $estimatedProfit = $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice);
        $realizedProfit = $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice);

        $this->assertSame($estimatedProfit, $realizedProfit);
        $this->assertLessThanOrEqual(
            $estimatedProfit,
            $realizedProfit,
            "Realized profit ({$realizedProfit}) must never exceed estimated profit ({$estimatedProfit}).",
        );
    }

    public function test_realized_profit_never_exceeds_estimated_profit_across_multiple_payments(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);
        // Two partial payments whose profits sum to exactly the estimated profit
        $this->insertPaymentDirectly($invoice, amount: 1200, profit: 360);
        $this->insertPaymentDirectly($invoice, amount: 800, profit: 240);

        $estimatedProfit = $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice);
        $realizedProfit = $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice);

        $this->assertLessThanOrEqual($estimatedProfit, $realizedProfit);
    }

    public function test_estimated_profit_is_strictly_less_than_total_amount_when_cost_price_is_positive(): void
    {
        // Profit = price - cost_price; as long as cost_price > 0, profit < total_amount.
        // price = 2000, profit = 600 → 600 < 2000
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);

        $totalAmount = $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice);
        $estimatedProfit = $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice);

        $this->assertGreaterThan(
            $estimatedProfit,
            $totalAmount,
            "Total amount ({$totalAmount}) must be strictly greater than estimated profit ({$estimatedProfit}).",
        );
    }

    public function test_estimated_profit_is_strictly_less_than_total_amount_across_multiple_items(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 1000, quantity: 2, profit: 200); // amount 2000, profit 200
        $this->insertInvoiceItemDirectly($invoice, price: 500, quantity: 4, profit: 300);  // amount 2000, profit 300

        $totalAmount = $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice);       // 4000
        $estimatedProfit = $this->salesInvoiceStatsService->calculateTotalEstimatedProfitForInvoice($invoice); // 500

        $this->assertGreaterThan($estimatedProfit, $totalAmount);
    }

    public function test_realized_profit_is_strictly_less_than_total_amount_for_partial_payment(): void
    {
        // A partial payment's realized profit is only a fraction of the total amount.
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);
        $this->insertPaymentDirectly($invoice, amount: 1000, profit: 300);

        $totalAmount = $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice);
        $realizedProfit = $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice);

        $this->assertGreaterThan(
            $realizedProfit,
            $totalAmount,
            "Total amount ({$totalAmount}) must be strictly greater than realized profit ({$realizedProfit}).",
        );
    }

    public function test_realized_profit_is_strictly_less_than_total_amount_when_fully_paid(): void
    {
        // Even when fully paid, realized profit < total amount (margin < 100 %).
        $invoice = $this->makeEmptyInvoice();
        $this->insertInvoiceItemDirectly($invoice, price: 2000, quantity: 1, profit: 600);
        $this->insertPaymentDirectly($invoice, amount: 2000, profit: 600);

        $totalAmount = $this->salesInvoiceStatsService->calculateTotalAmountForInvoice($invoice);
        $realizedProfit = $this->salesInvoiceStatsService->calculateTotalRealizedProfitForInvoice($invoice);

        $this->assertGreaterThan($realizedProfit, $totalAmount);
    }
}
