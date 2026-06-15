<?php

namespace Tests\Feature\Payment;

use App\Data\Payment\BulkCustomerPaymentResultData;
use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the bulk multi-invoice payment feature.
 *
 * A commercial can submit one payment (customer_id + amount + payment_method) and
 * the system distributes it across the customer's unpaid invoices from oldest to newest.
 * Guards: total amount must not exceed total owed; same amount is rejected within 1 minute.
 */
class BulkCustomerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $salespersonUser;

    private Commercial $commercial;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salespersonUser = User::factory()->create();

        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->salespersonUser->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221700000002',
            'owner_number' => '221700000003',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->product = Product::create([
            'name' => 'Produit Test',
            'price' => 5_000,
            'cost_price' => 3_000,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createUnpaidInvoice(int $totalAmount, ?string $createdAt = null): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
            'invoice_number' => 'F-TEST-'.rand(1000, 9999),
            'status' => SalesInvoiceStatus::Issued,
        ]);

        if ($createdAt !== null) {
            $invoice->created_at = Carbon::parse($createdAt);
            $invoice->save();
        }

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $totalAmount,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh();
    }

    private function postBulkPayment(int $amount, string $paymentMethod = 'CASH'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->salespersonUser, 'sanctum')
            ->postJson("/api/salesperson/customers/{$this->customer->id}/pay-invoices", [
                'amount' => $amount,
                'payment_method' => $paymentMethod,
            ]);
    }

    // ── Happy path: full settlement across multiple invoices ──────────────────

    public function test_bulk_payment_distributes_amount_across_invoices_oldest_first(): void
    {
        $oldestInvoice = $this->createUnpaidInvoice(18_000, '2026-01-01');
        $middleInvoice = $this->createUnpaidInvoice(20_000, '2026-01-02');
        $newestInvoice = $this->createUnpaidInvoice(10_000, '2026-01-03');

        $response = $this->postBulkPayment(48_000);

        $response->assertOk();
        $this->assertEquals(18_000, Payment::where('sales_invoice_id', $oldestInvoice->id)->sum('amount'));
        $this->assertEquals(20_000, Payment::where('sales_invoice_id', $middleInvoice->id)->sum('amount'));
        $this->assertEquals(10_000, Payment::where('sales_invoice_id', $newestInvoice->id)->sum('amount'));

        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $oldestInvoice->fresh()->status);
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $middleInvoice->fresh()->status);
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $newestInvoice->fresh()->status);
    }

    public function test_bulk_payment_partially_settles_last_invoice_when_amount_is_insufficient(): void
    {
        $firstInvoice = $this->createUnpaidInvoice(18_000, '2026-01-01');
        $secondInvoice = $this->createUnpaidInvoice(20_000, '2026-01-02');
        $thirdInvoice = $this->createUnpaidInvoice(10_000, '2026-01-03');

        // Pay 30 000 — covers first (18k) and partially settles second (12k of 20k)
        $response = $this->postBulkPayment(30_000);

        $response->assertOk();
        $this->assertEquals(18_000, Payment::where('sales_invoice_id', $firstInvoice->id)->sum('amount'));
        $this->assertEquals(12_000, Payment::where('sales_invoice_id', $secondInvoice->id)->sum('amount'));
        $this->assertEquals(0, Payment::where('sales_invoice_id', $thirdInvoice->id)->sum('amount'));

        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $firstInvoice->fresh()->status);
        $this->assertEquals(SalesInvoiceStatus::PartiallyPaid, $secondInvoice->fresh()->status);
        $this->assertEquals(SalesInvoiceStatus::Issued, $thirdInvoice->fresh()->status);
    }

    public function test_bulk_payment_against_single_invoice_works_correctly(): void
    {
        $invoice = $this->createUnpaidInvoice(15_000);

        $this->postBulkPayment(15_000)->assertOk();

        $this->assertEquals(15_000, Payment::where('sales_invoice_id', $invoice->id)->sum('amount'));
        $this->assertEquals(SalesInvoiceStatus::FullyPaid, $invoice->fresh()->status);
    }

    public function test_bulk_payment_skips_already_fully_paid_invoices(): void
    {
        $unpaidInvoice = $this->createUnpaidInvoice(20_000, '2026-01-01');
        $paidInvoice = $this->createUnpaidInvoice(10_000, '2026-01-02');

        // Fully pay the second invoice directly
        $paidInvoice->payments()->create([
            'amount' => 10_000,
            'payment_method' => 'CASH',
            'user_id' => $this->salespersonUser->id,
        ]);
        $paidInvoice->markAsFullyPaid();

        // Bulk pay — only the first (unpaid) invoice should receive the payment
        $this->postBulkPayment(20_000)->assertOk();

        $this->assertEquals(20_000, Payment::where('sales_invoice_id', $unpaidInvoice->id)->sum('amount'));
        // The paid invoice should not have received a new payment
        $this->assertEquals(10_000, Payment::where('sales_invoice_id', $paidInvoice->id)->sum('amount'));
    }

    public function test_response_contains_per_invoice_breakdown(): void
    {
        $firstInvoice = $this->createUnpaidInvoice(18_000, '2026-01-01');
        $secondInvoice = $this->createUnpaidInvoice(20_000, '2026-01-02');

        $response = $this->postBulkPayment(38_000);

        $response->assertOk();
        $invoicePayments = $response->json('data.invoice_payments');
        $this->assertCount(2, $invoicePayments);

        $this->assertEquals($firstInvoice->id, $invoicePayments[0]['invoice_id']);
        $this->assertEquals(18_000, $invoicePayments[0]['amount_paid']);
        $this->assertTrue($invoicePayments[0]['was_fully_paid']);

        $this->assertEquals($secondInvoice->id, $invoicePayments[1]['invoice_id']);
        $this->assertEquals(20_000, $invoicePayments[1]['amount_paid']);
        $this->assertTrue($invoicePayments[1]['was_fully_paid']);
    }

    // ── Guard: amount must not exceed total owed ──────────────────────────────

    public function test_bulk_payment_exceeding_total_owed_is_rejected_with_422(): void
    {
        $this->createUnpaidInvoice(18_000);
        $this->createUnpaidInvoice(20_000);

        $response = $this->postBulkPayment(50_000); // total owed is 38k, submitting 50k

        $response->assertUnprocessable();
        $this->assertStringContainsString('50000', $response->json('message'));
        $this->assertStringContainsString('38000', $response->json('message'));
        $this->assertEquals(0, Payment::count());
    }

    public function test_bulk_payment_equal_to_total_owed_is_accepted(): void
    {
        $this->createUnpaidInvoice(18_000);
        $this->createUnpaidInvoice(20_000);

        $this->postBulkPayment(38_000)->assertOk();

        $this->assertEquals(38_000, Payment::sum('amount'));
    }

    // ── Guard: rate limit (same amount for same customer within 1 minute) ──────

    public function test_immediate_duplicate_bulk_payment_is_rejected(): void
    {
        $this->createUnpaidInvoice(18_000, '2026-01-01');
        $this->createUnpaidInvoice(20_000, '2026-01-02');

        $this->postBulkPayment(38_000)->assertOk();

        $duplicateResponse = $this->postBulkPayment(38_000);

        $duplicateResponse->assertUnprocessable();
        $this->assertStringContainsString('1 minute', $duplicateResponse->json('errors.amount.0'));
    }

    public function test_duplicate_bulk_payment_at_59_seconds_is_rejected(): void
    {
        $this->createUnpaidInvoice(18_000, '2026-01-01');
        $this->createUnpaidInvoice(20_000, '2026-01-02');

        Carbon::setTestNow(now()->subSeconds(59));
        $this->postBulkPayment(38_000)->assertOk();
        Carbon::setTestNow();

        $duplicateResponse = $this->postBulkPayment(38_000);

        $duplicateResponse->assertUnprocessable();
        $this->assertEquals(38_000, Payment::sum('amount')); // only the first payment went through
    }

    public function test_same_amount_bulk_payment_is_allowed_after_one_minute(): void
    {
        // Customer owes 38k on two invoices; commercial pays 10k twice one minute apart.
        $this->createUnpaidInvoice(18_000, '2026-01-01');
        $this->createUnpaidInvoice(20_000, '2026-01-02');

        Carbon::setTestNow(now()->subSeconds(61));
        $this->postBulkPayment(10_000)->assertOk();
        Carbon::setTestNow();

        // A second payment of the same amount 61 seconds later is allowed.
        $this->postBulkPayment(10_000)->assertOk();

        $this->assertEquals(20_000, Payment::sum('amount'));
    }

    // ── Validation errors ─────────────────────────────────────────────────────

    public function test_bulk_payment_with_missing_amount_returns_validation_error(): void
    {
        $this->actingAs($this->salespersonUser, 'sanctum')
            ->postJson("/api/salesperson/customers/{$this->customer->id}/pay-invoices", [
                'payment_method' => 'CASH',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_bulk_payment_with_zero_amount_returns_validation_error(): void
    {
        $this->actingAs($this->salespersonUser, 'sanctum')
            ->postJson("/api/salesperson/customers/{$this->customer->id}/pay-invoices", [
                'amount' => 0,
                'payment_method' => 'CASH',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_bulk_payment_with_invalid_payment_method_returns_validation_error(): void
    {
        $this->createUnpaidInvoice(10_000);

        $this->actingAs($this->salespersonUser, 'sanctum')
            ->postJson("/api/salesperson/customers/{$this->customer->id}/pay-invoices", [
                'amount' => 5_000,
                'payment_method' => 'BITCOIN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    public function test_bulk_payment_requires_authentication(): void
    {
        $this->postJson("/api/salesperson/customers/{$this->customer->id}/pay-invoices", [
            'amount' => 10_000,
            'payment_method' => 'CASH',
        ])->assertUnauthorized();
    }

    // ── Service unit: BulkCustomerPaymentResultData shape ────────────────────

    public function test_service_returns_correct_result_data_shape(): void
    {
        $invoice = $this->createUnpaidInvoice(10_000);

        /** @var SalesInvoiceService $service */
        $service = app(SalesInvoiceService::class);

        $result = $service->payCustomerUnpaidInvoicesInBulk(
            customer: $this->customer,
            totalAmountToDistribute: 10_000,
            paymentMethod: 'CASH',
            userId: $this->salespersonUser->id,
        );

        $this->assertInstanceOf(BulkCustomerPaymentResultData::class, $result);
        $this->assertEquals(10_000, $result->totalAmountDistributed);
        $this->assertCount(1, $result->invoicePayments);
        $this->assertEquals($invoice->id, $result->invoicePayments[0]['invoice_id']);
        $this->assertEquals(10_000, $result->invoicePayments[0]['amount_paid']);
        $this->assertTrue($result->invoicePayments[0]['was_fully_paid']);
    }
}
