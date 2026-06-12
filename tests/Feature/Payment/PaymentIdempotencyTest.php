<?php

namespace Tests\Feature\Payment;

use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the one-minute idempotency guard on sales invoice payments.
 *
 * A commercial in the field may hit "save payment" twice due to poor
 * connectivity before the first response arrives. The rule rejects any
 * payment whose (invoice_id, amount) pair already exists in the payments
 * table with a created_at within the last 60 seconds.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $backOfficeUser;

    private User $salespersonUser;

    private Commercial $commercial;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backOfficeUser = User::factory()->create();
        $this->salespersonUser = User::factory()->create();

        $this->commercial = Commercial::create([
            'name' => 'Test Commercial',
            'phone_number' => '221700000099',
            'gender' => 'male',
            'user_id' => $this->salespersonUser->id,
        ]);

        $this->product = Product::create([
            'name' => 'Produit Test',
            'price' => 5_000,
            'cost_price' => 3_000,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeInvoiceWithTotal(int $totalAmount): SalesInvoice
    {
        $customer = Customer::create([
            'name' => 'Client Test',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $invoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'commercial_id' => $this->commercial->id,
            'status' => 'DRAFT',
        ]);

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

    private function postWebPayment(SalesInvoice $invoice, int $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->backOfficeUser)
            ->post(route('sales-invoices.payments.store', $invoice->id), [
                'amount' => $amount,
                'payment_method' => 'CASH',
            ]);
    }

    private function postApiPayment(SalesInvoice $invoice, int $amount): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->salespersonUser, 'sanctum')
            ->postJson("/api/salesperson/invoices/{$invoice->id}/pay", [
                'amount' => $amount,
                'payment_method' => 'CASH',
            ]);
    }

    // ─── Tests — web route ────────────────────────────────────────────────────

    public function test_first_payment_on_web_route_is_accepted(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        $this->postWebPayment($invoice, 5_000)->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'sales_invoice_id' => $invoice->id,
            'amount' => 5_000,
        ]);
    }

    public function test_immediate_duplicate_payment_on_web_route_is_rejected(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        $this->postWebPayment($invoice, 5_000); // first: succeeds

        $response = $this->postWebPayment($invoice, 5_000); // immediate duplicate: rejected

        $response->assertSessionHasErrors('amount');
        $this->assertSame(1, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_duplicate_payment_at_59_seconds_on_web_route_is_rejected(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        Carbon::setTestNow(now()->subSeconds(59));
        $this->postWebPayment($invoice, 5_000);
        Carbon::setTestNow();

        $response = $this->postWebPayment($invoice, 5_000);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(1, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_same_amount_is_allowed_on_web_route_after_one_minute(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        Carbon::setTestNow(now()->subSeconds(61));
        $this->postWebPayment($invoice, 4_000);
        Carbon::setTestNow();

        // 61 seconds later — no longer a duplicate
        $this->postWebPayment($invoice, 4_000)->assertRedirect();

        $this->assertSame(2, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_different_amount_is_allowed_immediately_on_web_route(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        $this->postWebPayment($invoice, 3_000);
        $this->postWebPayment($invoice, 2_000)->assertRedirect(); // different amount: allowed

        $this->assertSame(2, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_duplicate_amount_on_different_invoice_is_allowed_immediately(): void
    {
        $invoiceA = $this->makeInvoiceWithTotal(10_000);
        $invoiceB = $this->makeInvoiceWithTotal(10_000);

        $this->postWebPayment($invoiceA, 5_000);
        $this->postWebPayment($invoiceB, 5_000)->assertRedirect(); // different invoice: allowed

        $this->assertSame(1, Payment::where('sales_invoice_id', $invoiceA->id)->count());
        $this->assertSame(1, Payment::where('sales_invoice_id', $invoiceB->id)->count());
    }

    // ─── Tests — API route ────────────────────────────────────────────────────

    public function test_immediate_duplicate_payment_via_api_route_is_rejected(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        $this->postApiPayment($invoice, 5_000)->assertSuccessful();
        $this->postApiPayment($invoice, 5_000)->assertUnprocessable();

        $this->assertSame(1, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_api_error_response_contains_french_message(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        $this->postApiPayment($invoice, 5_000);
        $response = $this->postApiPayment($invoice, 5_000);

        $response->assertUnprocessable();
        $this->assertStringContainsString('1 minute', $response->json('errors.amount.0'));
    }

    public function test_same_amount_is_allowed_via_api_route_after_one_minute(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        Carbon::setTestNow(now()->subSeconds(61));
        $this->postApiPayment($invoice, 4_000)->assertSuccessful();
        Carbon::setTestNow();

        $this->postApiPayment($invoice, 4_000)->assertSuccessful();

        $this->assertSame(2, Payment::where('sales_invoice_id', $invoice->id)->count());
    }

    // ─── Test — cancelled payment does not block a new payment ───────────────

    public function test_cancelled_payment_does_not_trigger_idempotency_guard(): void
    {
        $invoice = $this->makeInvoiceWithTotal(10_000);

        // Insert a cancelled payment directly so cancelled_at is persisted without
        // going through $fillable (cancelled_at is intentionally not mass-assignable).
        DB::table('payments')->insert([
            'sales_invoice_id' => $invoice->id,
            'amount' => 5_000,
            'payment_method' => 'CASH',
            'user_id' => $this->backOfficeUser->id,
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $this->backOfficeUser->id,
            'cancellation_reason' => 'Test cancellation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The same amount should be accepted because the previous payment is cancelled.
        // NoDuplicateSalesInvoicePaymentWithinOneMinuteRule uses Payment::query() which
        // applies the notCancelled global scope, so cancelled payments are invisible to it.
        $response = $this->postWebPayment($invoice, 5_000);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Payment::where('sales_invoice_id', $invoice->id)->count());
    }
}
