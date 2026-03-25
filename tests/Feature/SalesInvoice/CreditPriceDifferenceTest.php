<?php

namespace Tests\Feature\SalesInvoice;

use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\PricingPolicy;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the credit_price_difference column on SalesInvoice.
 *
 * Verifies that invoices correctly store the difference between
 * credit prices and normal prices when the active pricing policy
 * has apply_credit_price enabled.
 */
class CreditPriceDifferenceTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT_CREATE_INVOICE = '/api/salesperson/sales-invoices';

    private const NORMAL_PRICE = 1000;

    private const CREDIT_PRICE = 1200;

    private const COST_PRICE = 600;

    private User $user;

    private Commercial $commercial;

    private Team $team;

    private Product $productWithCreditPrice;

    private Product $productWithoutCreditPrice;

    private CarLoad $carLoad;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Test Team',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221770000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);
        $this->commercial->team()->associate($this->team);
        $this->commercial->save();

        $this->productWithCreditPrice = Product::create([
            'name' => 'Produit Avec Credit',
            'price' => self::NORMAL_PRICE,
            'credit_price' => self::CREDIT_PRICE,
            'cost_price' => self::COST_PRICE,
            'base_quantity' => 1,
        ]);

        $this->productWithoutCreditPrice = Product::create([
            'name' => 'Produit Sans Credit',
            'price' => 500,
            'credit_price' => null,
            'cost_price' => 300,
            'base_quantity' => 1,
        ]);

        $this->carLoad = CarLoad::create([
            'name' => 'Chargement Test',
            'team_id' => $this->team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => today()->subDay(),
            'return_date' => today()->addDays(30),
            'returned' => false,
        ]);

        $this->loadStockIntoCarLoad($this->carLoad, $this->productWithCreditPrice, 100);
        $this->loadStockIntoCarLoad($this->carLoad, $this->productWithoutCreditPrice, 100);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'address' => 'Adresse Test',
            'phone_number' => '221770000099',
            'owner_number' => '221770000099',
            'gps_coordinates' => '9.00,13.70',
            'commercial_id' => $this->commercial->id,
        ]);
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_credit_price_difference_is_correctly_computed_for_unpaid_invoice_when_policy_is_enabled(): void
    {
        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'surcharge_percent' => 0,
            'active' => true,
        ]);

        // 3 units at normal price 1000, credit price 1200 → difference = (1200 - 1000) × 3 = 600
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => false,
                'should_be_paid_at' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->productWithCreditPrice->id,
                        'quantity' => 3,
                        'price' => self::NORMAL_PRICE,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        $this->assertSame(600, $invoice->credit_price_difference);
        // total_amount should use the credit price: 1200 × 3 = 3600
        $this->assertSame(3600, $invoice->total_amount);
    }

    public function test_credit_price_difference_is_zero_when_invoice_is_paid(): void
    {
        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'surcharge_percent' => 0,
            'active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => true,
                'payment_method' => 'CASH',
                'items' => [
                    [
                        'product_id' => $this->productWithCreditPrice->id,
                        'quantity' => 2,
                        'price' => self::NORMAL_PRICE,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        $this->assertSame(0, $invoice->credit_price_difference);
        // total_amount should use normal price: 1000 × 2 = 2000
        $this->assertSame(2000, $invoice->total_amount);
    }

    public function test_credit_price_difference_is_zero_when_policy_has_apply_credit_price_disabled(): void
    {
        PricingPolicy::factory()->create([
            'apply_credit_price' => false,
            'surcharge_percent' => 0,
            'active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => false,
                'should_be_paid_at' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->productWithCreditPrice->id,
                        'quantity' => 5,
                        'price' => self::NORMAL_PRICE,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        $this->assertSame(0, $invoice->credit_price_difference);
        $this->assertSame(5000, $invoice->total_amount);
    }

    public function test_credit_price_difference_is_zero_when_no_active_policy_exists(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => false,
                'should_be_paid_at' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->productWithCreditPrice->id,
                        'quantity' => 1,
                        'price' => self::NORMAL_PRICE,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        $this->assertSame(0, $invoice->credit_price_difference);
    }

    public function test_credit_price_difference_is_zero_when_product_has_no_credit_price(): void
    {
        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'surcharge_percent' => 0,
            'active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => false,
                'should_be_paid_at' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->productWithoutCreditPrice->id,
                        'quantity' => 4,
                        'price' => 500,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        $this->assertSame(0, $invoice->credit_price_difference);
        $this->assertSame(2000, $invoice->total_amount);
    }

    public function test_credit_price_difference_accounts_for_only_products_that_have_credit_price_set(): void
    {
        PricingPolicy::factory()->create([
            'apply_credit_price' => true,
            'surcharge_percent' => 0,
            'active' => true,
        ]);

        // Product with credit_price: 2 × (1200 - 1000) = 400 difference
        // Product without credit_price: no difference
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson(self::ENDPOINT_CREATE_INVOICE, [
                'customer_id' => $this->customer->id,
                'paid' => false,
                'should_be_paid_at' => today()->addDays(30)->toDateString(),
                'items' => [
                    [
                        'product_id' => $this->productWithCreditPrice->id,
                        'quantity' => 2,
                        'price' => self::NORMAL_PRICE,
                    ],
                    [
                        'product_id' => $this->productWithoutCreditPrice->id,
                        'quantity' => 3,
                        'price' => 500,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::latest()->first();
        // Only the product with credit_price contributes to the difference
        $this->assertSame(400, $invoice->credit_price_difference);
        // total_amount: (1200 × 2) + (500 × 3) = 2400 + 1500 = 3900
        $this->assertSame(3900, $invoice->total_amount);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadStockIntoCarLoad(CarLoad $carLoad, Product $product, int $quantity): void
    {
        $item = new CarLoadItem;
        $item->car_load_id = $carLoad->id;
        $item->product_id = $product->id;
        $item->quantity_loaded = $quantity;
        $item->quantity_left = $quantity;
        $item->loaded_at = today()->subDay()->setHour(7)->toDateTimeString();
        $item->save();
    }
}
