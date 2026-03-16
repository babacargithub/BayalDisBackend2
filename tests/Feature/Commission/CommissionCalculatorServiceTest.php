<?php

namespace Tests\Feature\Commission;

use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialProductCommissionRate;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\Commission\CommissionCalculatorService;
use App\Services\Commission\CommissionRateResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommissionCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommissionCalculatorService $service;

    private User $user;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CommissionCalculatorService(new CommissionRateResolverService);

        $this->user = User::factory()->create();
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);
    }

    private function makeCategory(string $name): ProductCategory
    {
        return ProductCategory::create(['name' => $name]);
    }

    private function makeProduct(?ProductCategory $category = null, int $price = 5000): Product
    {
        return Product::create([
            'name' => 'Produit '.rand(1, 999),
            'price' => $price,
            'cost_price' => 3000,
            'product_category_id' => $category?->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000999',
            'owner_number' => '221700000998',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    private function makeInvoiceWithItems(array $itemsData): SalesInvoice
    {
        $customer = $this->makeCustomer();
        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

        foreach ($itemsData as $itemData) {
            Vente::create([
                'sales_invoice_id' => $invoice->id,
                'product_id' => $itemData['product']->id,
                'quantity' => $itemData['quantity'],
                'price' => $itemData['price'],
                'profit' => 0,
                'type' => Vente::TYPE_INVOICE,
            ]);
        }

        return $invoice->fresh();
    }

    private function makePayment(SalesInvoice $invoice, int $amount): Payment
    {
        return Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'user_id' => $this->user->id,
        ]);
    }

    public function test_returns_empty_array_for_payment_with_no_sales_invoice(): void
    {
        // A payment without a linked invoice generates no commission lines.
        $payment = new Payment(['amount' => 50_000]);
        $payment->sales_invoice_id = null;

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $this->assertEmpty($paymentLines);
    }

    public function test_returns_one_line_for_single_product_invoice_with_known_rate(): void
    {
        $category = $this->makeCategory('ALM');
        $product = $this->makeProduct($category, 10_000);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100, // 1%
        ]);

        $invoice = $this->makeInvoiceWithItems([
            ['product' => $product, 'quantity' => 2, 'price' => 10_000],
        ]);

        $payment = $this->makePayment($invoice, 20_000); // full payment

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $this->assertCount(1, $paymentLines);
        $this->assertEquals($product->id, $paymentLines[0]->productId);
        $this->assertEqualsWithDelta(0.0100, $paymentLines[0]->rateApplied, 0.00001);
        $this->assertEquals(20_000, $paymentLines[0]->paymentAmountAllocated);
        $this->assertEquals(200, $paymentLines[0]->commissionAmount); // 20_000 × 1%
    }

    public function test_commission_is_zero_for_product_with_no_configured_rate(): void
    {
        $product = $this->makeProduct(null, 5_000); // no category, no rate

        $invoice = $this->makeInvoiceWithItems([
            ['product' => $product, 'quantity' => 4, 'price' => 5_000],
        ]);

        $payment = $this->makePayment($invoice, 20_000);

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $this->assertCount(1, $paymentLines);
        $this->assertEquals(0.0, $paymentLines[0]->rateApplied);
        $this->assertEquals(0, $paymentLines[0]->commissionAmount);
    }

    public function test_payment_is_allocated_proportionally_across_multiple_products(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 10_000); // subtotal = 40_000 (40%)
        $productJet = $this->makeProduct($categoryJet, 15_000); // subtotal = 60_000 (60%)

        CommercialCategoryCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_category_id' => $categoryAlm->id,
            'rate' => 0.0100, // 1%
        ]);
        CommercialCategoryCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_category_id' => $categoryJet->id,
            'rate' => 0.0200, // 2%
        ]);

        // Invoice total = 40_000 + 60_000 = 100_000
        $invoice = $this->makeInvoiceWithItems([
            ['product' => $productAlm, 'quantity' => 4, 'price' => 10_000],
            ['product' => $productJet, 'quantity' => 4, 'price' => 15_000],
        ]);

        $payment = $this->makePayment($invoice, 100_000);

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $this->assertCount(2, $paymentLines);

        // ALM: 40% of 100_000 = 40_000 × 1% = 400
        $almLine = collect($paymentLines)->firstWhere('productId', $productAlm->id);
        $this->assertNotNull($almLine);
        $this->assertEquals(40_000, $almLine->paymentAmountAllocated);
        $this->assertEquals(400, $almLine->commissionAmount);

        // JET: 60% of 100_000 = 60_000 × 2% = 1_200
        $jetLine = collect($paymentLines)->firstWhere('productId', $productJet->id);
        $this->assertNotNull($jetLine);
        $this->assertEquals(60_000, $jetLine->paymentAmountAllocated);
        $this->assertEquals(1_200, $jetLine->commissionAmount);
    }

    public function test_partial_payment_allocates_proportionally_to_each_product(): void
    {
        $category = $this->makeCategory('ALM');
        $productA = $this->makeProduct($category, 20_000);
        $productB = $this->makeProduct($category, 20_000);

        CommercialCategoryCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_category_id' => $category->id,
            'rate' => 0.0100, // 1%
        ]);

        // Invoice total = 40_000, but only 20_000 paid (50%)
        $invoice = $this->makeInvoiceWithItems([
            ['product' => $productA, 'quantity' => 1, 'price' => 20_000],
            ['product' => $productB, 'quantity' => 1, 'price' => 20_000],
        ]);

        $payment = $this->makePayment($invoice, 20_000); // 50% payment

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $this->assertCount(2, $paymentLines);

        // Each product gets 10_000 (50% of 20_000), commission = 10_000 × 1% = 100
        foreach ($paymentLines as $paymentLine) {
            $this->assertEquals(10_000, $paymentLine->paymentAmountAllocated);
            $this->assertEquals(100, $paymentLine->commissionAmount);
        }
    }

    public function test_both_product_ids_appear_in_payment_lines_for_multi_product_invoice(): void
    {
        $categoryAlm = $this->makeCategory('ALM');
        $categoryJet = $this->makeCategory('JET');

        $productAlm = $this->makeProduct($categoryAlm, 10_000);
        $productJet = $this->makeProduct($categoryJet, 10_000);

        $invoice = $this->makeInvoiceWithItems([
            ['product' => $productAlm, 'quantity' => 1, 'price' => 10_000],
            ['product' => $productJet, 'quantity' => 1, 'price' => 10_000],
        ]);

        $payment = $this->makePayment($invoice, 20_000);

        $paymentLines = $this->service->computePaymentLinesForCommercial($payment, $this->commercial);

        $productIds = collect($paymentLines)->pluck('productId')->toArray();

        $this->assertContains($productAlm->id, $productIds);
        $this->assertContains($productJet->id, $productIds);
    }
}
