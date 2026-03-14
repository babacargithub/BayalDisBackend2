<?php

namespace Tests\Feature\SalespersonApi;

use App\Enums\CarLoadStatus;
use App\Enums\SalesInvoiceStatus;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Critical endpoint tests for the salesperson mobile API.
 *
 * These three endpoints directly move stock and record money:
 *   POST /api/salesperson/sales-invoices      — create a sale
 *   POST /api/salesperson/invoices/{id}/pay   — record a payment
 *   GET  /api/salesperson/activity_report     — financial summary
 *
 * Strategy:
 *  1. Focused unit scenarios (auth, validation, individual flows).
 *  2. Full-day simulation: 30 invoices across 10 customers and 3 products,
 *     spread over typical working hours (10h–18h), with a mix of Cash / Wave /
 *     OM immediate payments and credit invoices followed by partial / full
 *     late payments.  The activity report is then verified to match the DB
 *     ground truth exactly — no hand-rolled expected values.
 */
class SalespersonCriticalEndpointsTest extends TestCase
{
    use RefreshDatabase;

    // ── Endpoint paths ────────────────────────────────────────────────────────
    private const ENDPOINT_CREATE_INVOICE = '/api/salesperson/sales-invoices';

    private const ENDPOINT_ACTIVITY_REPORT = '/api/salesperson/activity_report';

    // ── Product economics (XOF) ───────────────────────────────────────────────
    private const PRICE_A = 2500;   // Jus Délice case

    private const COST_A = 1600;

    private const PRICE_B = 1500;   // Eau Fraîche case

    private const COST_B = 900;

    private const PRICE_C = 3000;   // Soda Premium case

    private const COST_C = 2000;

    private const STOCK_PER_PRODUCT = 300;

    // ── Test fixtures ─────────────────────────────────────────────────────────
    private User $user;

    private Commercial $commercial;

    private Team $team;

    private Product $productA;

    private Product $productB;

    private Product $productC;

    private CarLoad $carLoad;

    /** @var Customer[] */
    private array $customers = [];

    // =========================================================================
    // Setup
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Équipe Conakry',
            'user_id' => $this->user->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Mamadou Diallo',
            'phone_number' => '221770000001',
            'gender' => 'male',
            'user_id' => $this->user->id,
        ]);
        // team_id is not in $fillable — must use associate()
        $this->commercial->team()->associate($this->team);
        $this->commercial->save();

        $this->productA = Product::create([
            'name' => 'Jus Délice (carton)',
            'price' => self::PRICE_A,
            'cost_price' => self::COST_A,
            'base_quantity' => 1,
        ]);
        $this->productB = Product::create([
            'name' => 'Eau Fraîche (carton)',
            'price' => self::PRICE_B,
            'cost_price' => self::COST_B,
            'base_quantity' => 1,
        ]);
        $this->productC = Product::create([
            'name' => 'Soda Premium (carton)',
            'price' => self::PRICE_C,
            'cost_price' => self::COST_C,
            'base_quantity' => 1,
        ]);

        // CarLoad active today — no status filter in getCurrentCarLoadForTeam,
        // only return_date >= today is required.
        $this->carLoad = CarLoad::create([
            'name' => 'Chargement du Jour',
            'team_id' => $this->team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => today()->subDay(),
            'return_date' => today()->addDays(30),
            'returned' => false,
        ]);

        $this->loadStock($this->carLoad, $this->productA, self::STOCK_PER_PRODUCT);
        $this->loadStock($this->carLoad, $this->productB, self::STOCK_PER_PRODUCT);
        $this->loadStock($this->carLoad, $this->productC, self::STOCK_PER_PRODUCT);

        // 10 realistic customers in the commercial's portfolio.
        $names = [
            'Fatoumata Diallo', 'Ousmane Ndiaye', 'Aïssatou Bah', 'Ibrahima Sow',
            'Mariama Camara', 'Abdoulaye Diakité', 'Kadiatou Konaté', 'Moussa Barry',
            'Hawa Traoré', 'Mamadou Baldé',
        ];
        foreach ($names as $i => $name) {
            $this->customers[$i] = Customer::create([
                'name' => $name,
                'address' => 'Quartier '.($i + 1).', Conakry',
                'phone_number' => '22177'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'owner_number' => '22166'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'gps_coordinates' => '9.5'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).',13.7'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'commercial_id' => $this->commercial->id,
            ]);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a CarLoadItem for a product.
     * quantity_left is guarded from mass assignment so we assign directly.
     */
    private function loadStock(CarLoad $carLoad, Product $product, int $quantity): CarLoadItem
    {
        $item = new CarLoadItem;
        $item->car_load_id = $carLoad->id;
        $item->product_id = $product->id;
        $item->quantity_loaded = $quantity;
        $item->quantity_left = $quantity;
        $item->loaded_at = today()->subDay()->setHour(7)->toDateTimeString();
        $item->save();

        return $item;
    }

    private function asCommercial(): static
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    private function createInvoice(array $payload): TestResponse
    {
        return $this->asCommercial()->postJson(self::ENDPOINT_CREATE_INVOICE, $payload);
    }

    private function payInvoice(SalesInvoice $invoice, int $amount, string $method, ?string $comment = null): TestResponse
    {
        $payload = ['amount' => $amount, 'payment_method' => $method];
        if ($comment !== null) {
            $payload['comment'] = $comment;
        }

        return $this->asCommercial()->postJson("/api/salesperson/invoices/{$invoice->id}/pay", $payload);
    }

    private function activityReport(string $date, string $type = 'daily'): TestResponse
    {
        return $this->asCommercial()->getJson(self::ENDPOINT_ACTIVITY_REPORT."?date={$date}&type={$type}");
    }

    /** Resolve a letter key to a Product instance. */
    private function product(string $key): Product
    {
        return match ($key) {
            'A' => $this->productA,
            'B' => $this->productB,
            'C' => $this->productC,
        };
    }

    /** Build the items array for createInvoice from [['A', qty], ...] pairs. */
    private function items(array $pairs): array
    {
        return array_map(fn ($pair) => [
            'product_id' => $this->product($pair[0])->id,
            'quantity' => $pair[1],
            'price' => $this->product($pair[0])->price,
        ], $pairs);
    }

    /** Return the quantity_left remaining in the car load for a given product. */
    private function remainingStock(Product $product): int
    {
        return (int) CarLoadItem::where('car_load_id', $this->carLoad->id)
            ->where('product_id', $product->id)
            ->sum('quantity_left');
    }

    /**
     * The 30 invoice scenarios that simulate a full working day (10h–18h).
     *
     * Format: [customer_index, [[product_key, qty], ...], paid, method|null, should_be_paid_at|null]
     *
     * Split:  12 Cash · 8 Wave · 4 OM · 6 Credit (3 of which get late payments in the simulation test)
     */
    private function thirtyInvoiceScenarios(): array
    {
        $nextWeek = Carbon::today()->addDays(7)->toDateString();

        return [
            // ── 12 Cash invoices (10:00–12:30) ────────────────────────────
            [0, [['A', 2]],           true,  'Cash', null],       // 01  5 000
            [1, [['B', 3]],           true,  'Cash', null],       // 02  4 500
            [2, [['C', 1]],           true,  'Cash', null],       // 03  3 000
            [3, [['A', 1], ['B', 2]], true,  'Cash', null],       // 04  5 500
            [4, [['B', 4]],           true,  'Cash', null],       // 05  6 000
            [5, [['C', 2]],           true,  'Cash', null],       // 06  6 000
            [6, [['A', 3]],           true,  'Cash', null],       // 07  7 500
            [7, [['B', 1], ['C', 1]], true,  'Cash', null],       // 08  4 500
            [8, [['A', 2]],           true,  'Cash', null],       // 09  5 000
            [9, [['C', 1]],           true,  'Cash', null],       // 10  3 000
            [0, [['A', 1], ['B', 1]], true,  'Cash', null],       // 11  4 000
            [1, [['B', 2]],           true,  'Cash', null],       // 12  3 000
            // ── 8 Wave invoices (13:00–15:30) ─────────────────────────────
            [2, [['A', 3]],           true,  'Wave', null],       // 13  7 500
            [3, [['C', 2]],           true,  'Wave', null],       // 14  6 000
            [4, [['B', 5]],           true,  'Wave', null],       // 15  7 500
            [5, [['A', 2], ['C', 1]], true,  'Wave', null],       // 16  8 000
            [6, [['B', 3]],           true,  'Wave', null],       // 17  4 500
            [7, [['A', 1]],           true,  'Wave', null],       // 18  2 500
            [8, [['C', 3]],           true,  'Wave', null],       // 19  9 000
            [9, [['B', 2], ['A', 1]], true,  'Wave', null],       // 20  5 500
            // ── 4 OM invoices (15:30–16:30) ───────────────────────────────
            [0, [['C', 1]],           true,  'Om',   null],       // 21  3 000
            [1, [['A', 2]],           true,  'Om',   null],       // 22  5 000
            [2, [['B', 3]],           true,  'Om',   null],       // 23  4 500
            [3, [['C', 2]],           true,  'Om',   null],       // 24  6 000
            // ── 6 Credit invoices (16:30–18:00) ───────────────────────────
            [4, [['A', 3]],           false, null,   $nextWeek],  // 25  7 500  credit
            [5, [['B', 4]],           false, null,   $nextWeek],  // 26  6 000  credit
            [6, [['C', 1]],           false, null,   $nextWeek],  // 27  3 000  credit
            [7, [['A', 2]],           false, null,   $nextWeek],  // 28  5 000  credit
            [8, [['B', 3]],           false, null,   $nextWeek],  // 29  4 500  credit
            [9, [['C', 2]],           false, null,   $nextWeek],  // 30  6 000  credit
        ];
    }

    // =========================================================================
    // 1. Authentication & route guards
    // =========================================================================

    public function test_unauthenticated_request_to_create_invoice_returns_401(): void
    {
        $this->postJson(self::ENDPOINT_CREATE_INVOICE, [])->assertStatus(401);
    }

    public function test_unauthenticated_request_to_activity_report_returns_401(): void
    {
        $this->getJson(self::ENDPOINT_ACTIVITY_REPORT.'?date='.today()->toDateString().'&type=daily')
            ->assertStatus(401);
    }

    // =========================================================================
    // 2. create invoice — validation
    // =========================================================================

    public function test_create_invoice_with_no_items_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => [],
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_create_invoice_with_zero_quantity_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => 0, 'price' => 2500]],
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_create_invoice_paid_true_without_payment_method_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 1]]),
            'paid' => true,
            'payment_method' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_method']);
    }

    public function test_create_credit_invoice_without_should_be_paid_at_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 1]]),
            'paid' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['should_be_paid_at']);
    }

    public function test_create_invoice_with_nonexistent_customer_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => 999999,
            'items' => $this->items([['A', 1]]),
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    // =========================================================================
    // 3. create invoice — car load requirement
    // =========================================================================

    public function test_create_invoice_without_active_car_load_returns_422(): void
    {
        // Delete the car load items but keep the carload — or simply put a past return_date
        $this->carLoad->return_date = today()->subDay();
        $this->carLoad->save();

        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 1]]),
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'chargement'));
    }

    // =========================================================================
    // 4. create invoice — insufficient stock
    // =========================================================================

    public function test_create_invoice_with_quantity_exceeding_stock_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => self::STOCK_PER_PRODUCT + 1, 'price' => 2500]],
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'Stock insuffisant'));
    }

    public function test_insufficient_stock_does_not_create_invoice_or_deduct_stock(): void
    {
        $stockBefore = $this->remainingStock($this->productA);
        $invoiceCountBefore = SalesInvoice::count();

        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => [['product_id' => $this->productA->id, 'quantity' => self::STOCK_PER_PRODUCT + 50, 'price' => 2500]],
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(422);

        $this->assertEquals($stockBefore, $this->remainingStock($this->productA));
        $this->assertEquals($invoiceCountBefore, SalesInvoice::count());
    }

    // =========================================================================
    // 5. create invoice — cash sale (immediate payment)
    // =========================================================================

    public function test_cash_invoice_is_created_with_correct_stored_totals(): void
    {
        $response = $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 2], ['B', 3]]),  // 5000 + 4500 = 9500
            'paid' => true,
            'payment_method' => 'Cash',
        ]);

        $response->assertStatus(201);

        $invoice = SalesInvoice::with('payments')->latest()->first();
        $this->assertEquals(9500, $invoice->total_amount);
        $this->assertEquals(9500, $invoice->total_payments);
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertTrue($invoice->paid);
    }

    public function test_cash_invoice_creates_exactly_one_payment_with_correct_amount_and_method(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[1]->id,
            'items' => $this->items([['C', 2]]),  // 6000
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $payments = $invoice->payments;

        $this->assertCount(1, $payments);
        $this->assertEquals(6000, $payments->first()->amount);
        $this->assertEquals('Cash', $payments->first()->payment_method);
    }

    public function test_cash_invoice_deducts_correct_quantities_from_car_load_stock(): void
    {
        $stockABefore = $this->remainingStock($this->productA);
        $stockBBefore = $this->remainingStock($this->productB);

        $this->createInvoice([
            'customer_id' => $this->customers[2]->id,
            'items' => $this->items([['A', 3], ['B', 2]]),
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(201);

        $this->assertEquals($stockABefore - 3, $this->remainingStock($this->productA));
        $this->assertEquals($stockBBefore - 2, $this->remainingStock($this->productB));
    }

    // =========================================================================
    // 6. create invoice — Wave payment
    // =========================================================================

    public function test_wave_invoice_is_fully_paid_and_payment_method_is_recorded_correctly(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[3]->id,
            'items' => $this->items([['A', 1]]),  // 2500
            'paid' => true,
            'payment_method' => 'Wave',
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertEquals('Wave', $invoice->payments->first()->payment_method);
    }

    // =========================================================================
    // 7. create invoice — OM payment
    // =========================================================================

    public function test_om_invoice_is_fully_paid_and_payment_method_is_recorded_correctly(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[4]->id,
            'items' => $this->items([['C', 1]]),  // 3000
            'paid' => true,
            'payment_method' => 'Om',
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertEquals('Om', $invoice->payments->first()->payment_method);
    }

    // =========================================================================
    // 8. create invoice — credit sale (no immediate payment)
    // =========================================================================
    public function test_credit_invoice_has_issued_status_and_zero_payments(): void
    {
        $dueDate = today()->addDays(7)->toDateString();

        $this->createInvoice([
            'customer_id' => $this->customers[5]->id,
            'items' => $this->items([['B', 4]]),  // 6000
            'paid' => false,
            'should_be_paid_at' => $dueDate,
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->assertEquals(6000, $invoice->total_amount);
        $this->assertEquals(0, $invoice->total_payments);
        // ISSUED = invoice finalised and sent to customer, awaiting payment.
        // DRAFT is reserved for back-office invoices not yet sent out.
        $this->assertEquals(SalesInvoiceStatus::Issued, $invoice->status);
        $this->assertFalse($invoice->paid);
        $this->assertCount(0, $invoice->payments);
    }

    public function test_credit_invoice_still_deducts_stock_from_car_load(): void
    {
        $stockBefore = $this->remainingStock($this->productC);

        $this->createInvoice([
            'customer_id' => $this->customers[6]->id,
            'items' => $this->items([['C', 2]]),
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $this->assertEquals($stockBefore - 2, $this->remainingStock($this->productC));
    }

    // =========================================================================
    // 9. create invoice — profit is stored correctly
    // =========================================================================

    public function test_invoice_total_estimated_profit_equals_sum_of_vente_profits(): void
    {
        // Profit per vente depends on StockEntry cost history (getCostPriceFromStockEntry).
        // The invariant we can always assert: stored total_estimated_profit == SUM(ventes.profit).
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 2], ['B', 3]]),
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $venteProfitSum = (int) $invoice->items()->sum('profit');

        $this->assertEquals($venteProfitSum, $invoice->total_estimated_profit);
        $this->assertGreaterThanOrEqual(0, $invoice->total_estimated_profit);
    }

    public function test_payment_profit_equals_proportional_share_of_invoice_estimated_profit(): void
    {
        // Invariant: payment.profit = round(invoice.total_estimated_profit / invoice.total_amount × payment.amount)
        // This holds regardless of cost-price history or StockEntry data.
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 2], ['B', 3]]),  // 9500 total
            'paid' => true,
            'payment_method' => 'Cash',
        ])->assertStatus(201);

        $invoice = SalesInvoice::with('payments')->orderByDesc('id')->first();
        $payment = $invoice->payments->first();

        $expectedPaymentProfit = (int) round(
            $invoice->total_estimated_profit / $invoice->total_amount * $payment->amount
        );

        $this->assertEquals($expectedPaymentProfit, $payment->profit);
        // Full cash payment — realized profit must equal the full estimated profit
        $this->assertEquals($invoice->total_estimated_profit, $invoice->total_realized_profit);
    }

    // =========================================================================
    // 10. pay invoice — partial payment
    // =========================================================================

    public function test_partial_payment_sets_partially_paid_status_and_updates_stored_totals(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[7]->id,
            'items' => $this->items([['C', 2]]),  // 6000
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();

        $this->payInvoice($invoice, 3000, 'Wave')->assertOk();

        $invoice->refresh();
        $this->assertEquals(6000, $invoice->total_amount);
        $this->assertEquals(3000, $invoice->total_payments);
        $this->assertEquals(SalesInvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertFalse($invoice->paid);
    }

    public function test_second_payment_completes_invoice_and_sets_fully_paid(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[8]->id,
            'items' => $this->items([['A', 2]]),  // 5000
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();

        $this->payInvoice($invoice, 2000, 'Cash')->assertOk();  // partial
        $invoice->refresh();
        $this->assertEquals(SalesInvoiceStatus::PartiallyPaid, $invoice->status);

        $this->payInvoice($invoice, 3000, 'Wave')->assertOk();  // remainder

        $invoice->refresh();
        $this->assertEquals(5000, $invoice->total_payments);
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertTrue($invoice->paid);
        $this->assertCount(2, $invoice->payments);
    }

    public function test_partial_payment_profit_equals_proportional_share_of_estimated_profit(): void
    {
        // Invariant: payment.profit = round(invoice.total_estimated_profit / invoice.total_amount × payment.amount)
        // This holds regardless of StockEntry cost history.
        $this->createInvoice([
            'customer_id' => $this->customers[9]->id,
            'items' => $this->items([['C', 2]]),  // 6000
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->payInvoice($invoice, 3000, 'Om')->assertOk();  // half payment

        $invoice->refresh();
        $payment = $invoice->payments()->orderByDesc('id')->first();

        $expectedProfit = (int) round(
            $invoice->total_estimated_profit / $invoice->total_amount * $payment->amount
        );

        $this->assertEquals($expectedProfit, $payment->profit);
    }

    // =========================================================================
    // 11. pay invoice — validation
    // =========================================================================

    public function test_pay_invoice_with_zero_amount_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 1]]),
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ]);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->payInvoice($invoice, 0, 'Cash')->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_pay_invoice_with_invalid_payment_method_returns_422(): void
    {
        $this->createInvoice([
            'customer_id' => $this->customers[0]->id,
            'items' => $this->items([['A', 1]]),
            'paid' => false,
            'should_be_paid_at' => today()->addDays(7)->toDateString(),
        ]);

        $invoice = SalesInvoice::orderByDesc('id')->first();
        $this->payInvoice($invoice, 1000, 'Invalid PM')->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    // =========================================================================
    // 12. Activity report — structure and basic values
    // =========================================================================

    public function test_activity_report_returns_correct_json_structure(): void
    {
        $this->activityReport(today()->toDateString())
            ->assertOk()
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

    public function test_activity_report_returns_all_zeros_when_no_sales_exist(): void
    {
        $this->activityReport(today()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.total_sales', 0)
            ->assertJsonPath('data.total_payments', 0)
            ->assertJsonPath('data.total_unpaid_amount', 0)
            ->assertJsonPath('data.total_payments_cash', 0)
            ->assertJsonPath('data.total_payments_wave', 0)
            ->assertJsonPath('data.total_payments_om', 0);
    }

    public function test_activity_report_missing_date_returns_422(): void
    {
        $this->asCommercial()->getJson(self::ENDPOINT_ACTIVITY_REPORT.'?type=daily')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_activity_report_invalid_type_returns_422(): void
    {
        $this->activityReport(today()->toDateString(), 'invalid period')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // =========================================================================
    // 13. Full-day simulation — 30 invoices, 10 customers, 3 products
    // =========================================================================

    /**
     * Creates all 30 invoice scenarios via the API, adds late payments to 3 of the
     * 6 credit invoices, then asserts that the activity report figures match the
     * ground truth derived directly from the database.
     *
     * Verified invariants:
     *   total_sales          = SUM(sales_invoices.total_amount)
     *   total_payments       = SUM(payments.amount)
     *   total_unpaid_amount  = SUM(total_amount - total_payments) on invoices
     *   payment method split = SUM(payments.amount) grouped by payment_method
     *   payment_methods_sum  = total_payments   (no money disappears)
     *   stock_deducted       = SUM(quantities sold per product)
     */
    public function test_full_day_simulation_activity_report_matches_database_ground_truth(): void
    {
        $scenarios = $this->thirtyInvoiceScenarios();

        // ── Step 1: Create all 30 invoices via the API ───────────────────────
        $createdInvoices = [];
        foreach ($scenarios as $index => [$customerIndex, $itemPairs, $paid, $method, $dueDate]) {
            $payload = [
                'customer_id' => $this->customers[$customerIndex]->id,
                'items' => $this->items($itemPairs),
                'paid' => $paid,
            ];
            if ($paid) {
                $payload['payment_method'] = $method;
            } else {
                $payload['should_be_paid_at'] = $dueDate;
            }

            $this->createInvoice($payload)->assertStatus(201, 'Invoice #'.($index + 1).' failed to create');

            $createdInvoices[$index] = SalesInvoice::orderByDesc('id')->first();
        }

        $this->assertCount(30, $createdInvoices, '30 invoices must exist after simulation');

        // ── Step 2: Add late payments to 3 of the 6 credit invoices ─────────
        // Invoice 25 (index 24): 7 500 — partial Wave payment of 3 000
        $this->payInvoice($createdInvoices[24], 3000, 'Wave')->assertOk();

        // Invoice 27 (index 26): 3 000 — full OM payment (settles the debt)
        $this->payInvoice($createdInvoices[26], 3000, 'Om')->assertOk();

        // Invoice 29 (index 28): 4 500 — partial Cash payment of 2 000
        $this->payInvoice($createdInvoices[28], 2000, 'Cash')->assertOk();

        // ── Step 3: Compute ground truth directly from the database ──────────
        $expectedTotalSales = (int) SalesInvoice::where('commercial_id', $this->commercial->id)
            ->whereDate('created_at', today())
            ->sum('total_amount');

        $expectedTotalPayments = (int) Payment::whereHas(
            'salesInvoice',
            fn ($q) => $q->where('commercial_id', $this->commercial->id)
        )->whereDate('created_at', today())->sum('amount');

        $expectedTotalUnpaid = (int) SalesInvoice::where('commercial_id', $this->commercial->id)
            ->whereDate('created_at', today())
            ->selectRaw('SUM(total_amount - total_payments) as unpaid')
            ->value('unpaid');

        $expectedCash = (int) Payment::whereHas(
            'salesInvoice',
            fn ($q) => $q->where('commercial_id', $this->commercial->id)
        )->whereDate('created_at', today())->where('payment_method', 'Cash')->sum('amount');

        $expectedWave = (int) Payment::whereHas(
            'salesInvoice',
            fn ($q) => $q->where('commercial_id', $this->commercial->id)
        )->whereDate('created_at', today())->where('payment_method', 'Wave')->sum('amount');

        $expectedOm = (int) Payment::whereHas(
            'salesInvoice',
            fn ($q) => $q->where('commercial_id', $this->commercial->id)
        )->whereDate('created_at', today())->where('payment_method', 'Om')->sum('amount');

        // Sanity-check our own DB queries before comparing to the report.
        $this->assertEquals(
            $expectedTotalPayments,
            $expectedCash + $expectedWave + $expectedOm,
            'DB ground truth: payment methods must sum to total payments'
        );
        $this->assertEquals(
            $expectedTotalSales,
            $expectedTotalPayments + $expectedTotalUnpaid,
            'DB ground truth: sales = payments + unpaid'
        );

        // We know these should be non-trivial — guard against empty data bugs.
        $this->assertGreaterThan(0, $expectedTotalSales, 'Total sales must be > 0');
        $this->assertGreaterThan(0, $expectedTotalPayments, 'Total payments must be > 0');
        $this->assertGreaterThan(0, $expectedTotalUnpaid, 'Some credit must remain unpaid');

        // ── Step 4: Call the activity report and assert it matches DB ────────
        $response = $this->activityReport(today()->toDateString());
        $response->assertOk();

        $data = $response->json('data');

        $this->assertEquals($expectedTotalSales, $data['total_sales'], ' activity report total_sales mismatch');
        $this->assertEquals($expectedTotalPayments, $data['total_payments'], 'activity report total_payments mismatch');
        $this->assertEquals($expectedTotalUnpaid, $data['total_unpaid_amount'], 'activity report total_unpaid_amount mismatch');
        $this->assertEquals($expectedCash, $data['total_payments_cash'], 'activity report total_payments_cash mismatch');
        $this->assertEquals($expectedWave, $data['total_payments_wave'], 'activity report total_payments_wave mismatch');
        $this->assertEquals($expectedOm, $data['total_payments_om'], 'activity report  total_payments_om mismatch');

        // Payment method split must equal total payments in the report too.
        $this->assertEquals(
            $data['total_payments'],
            $data['total_payments_cash'] + $data['total_payments_wave'] + $data['total_payments_om'],
            'Report: payment methods must sum to total_payments'
        );
    }

    public function test_full_day_simulation_stock_is_deducted_exactly_for_each_product(): void
    {
        $scenarios = $this->thirtyInvoiceScenarios();

        $totalQuantityA = 0;
        $totalQuantityB = 0;
        $totalQuantityC = 0;

        foreach ($scenarios as [$customerIndex, $itemPairs, $paid, $method, $dueDate]) {
            $payload = [
                'customer_id' => $this->customers[$customerIndex]->id,
                'items' => $this->items($itemPairs),
                'paid' => $paid,
            ];
            $paid
                ? $payload['payment_method'] = $method
                : $payload['should_be_paid_at'] = $dueDate;

            $this->createInvoice($payload)->assertStatus(201);

            foreach ($itemPairs as [$key, $qty]) {
                match ($key) {
                    'A' => $totalQuantityA += $qty,
                    'B' => $totalQuantityB += $qty,
                    'C' => $totalQuantityC += $qty,
                };
            }
        }

        $this->assertEquals(
            self::STOCK_PER_PRODUCT - $totalQuantityA,
            $this->remainingStock($this->productA),
            'Product A stock mismatch after 30 invoices'
        );
        $this->assertEquals(
            self::STOCK_PER_PRODUCT - $totalQuantityB,
            $this->remainingStock($this->productB),
            'Product B stock mismatch after 30 invoices'
        );
        $this->assertEquals(
            self::STOCK_PER_PRODUCT - $totalQuantityC,
            $this->remainingStock($this->productC),
            'Product C stock mismatch after 30 invoices'
        );
    }

    public function test_full_day_simulation_all_30_invoices_have_correct_stored_totals(): void
    {
        $scenarios = $this->thirtyInvoiceScenarios();

        foreach ($scenarios as $index => [$customerIndex, $itemPairs, $paid, $method, $dueDate]) {
            $expectedAmount = (int) array_sum(array_map(
                fn ($pair) => $this->product($pair[0])->price * $pair[1],
                $itemPairs
            ));

            $payload = [
                'customer_id' => $this->customers[$customerIndex]->id,
                'items' => $this->items($itemPairs),
                'paid' => $paid,
            ];
            $paid
                ? $payload['payment_method'] = $method
                : $payload['should_be_paid_at'] = $dueDate;

            $this->createInvoice($payload)->assertStatus(201);

            $invoice = SalesInvoice::orderByDesc('id')->first();

            $this->assertEquals(
                $expectedAmount,
                $invoice->total_amount,
                'Invoice #'.($index + 1)." total_amount should be {$expectedAmount}"
            );

            if ($paid) {
                $this->assertEquals(
                    $expectedAmount,
                    $invoice->total_payments,
                    'Invoice #'.($index + 1).' should be fully paid'
                );
                $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->status);
            } else {
                $this->assertEquals(0, $invoice->total_payments, 'Invoice #'.($index + 1).' should have 0 payments');
                $this->assertEquals(SalesInvoiceStatus::Issued, $invoice->status, 'Invoice #'.($index + 1).' should be Issued');
            }
        }
    }
}
