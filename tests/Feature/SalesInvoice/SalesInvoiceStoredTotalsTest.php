<?php

namespace Tests\Feature\SalesInvoice;

use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for SalesInvoice stored totals and status lifecycle.
 *
 * Verifies that total_amount, total_payments, total_estimated_profit,
 * total_realized_profit, and status are automatically maintained whenever
 * invoice items or payments are created, updated, or deleted.
 *
 * These are financial columns — any regression means incorrect amounts on invoices.
 */
class SalesInvoiceStoredTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $defaultCustomer;

    private Commercial $defaultCommercial;

    private Product $defaultProduct;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function addItemToInvoice(SalesInvoice $invoice, int $price, int $quantity, int $profit): Vente
    {
        return Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->defaultProduct->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => $profit,
            'type' => Vente::TYPE_INVOICE,
        ]);
    }

    private function makePaymentForInvoice(SalesInvoice $invoice, int $amount): Payment
    {
        return Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'Cash',
            'user_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * Create a full payment covering the invoice total and call markAsFullyPaid()
     * to transition the invoice to FULLY_PAID status.
     * markAsFullyPaid() is the only authorised path to that status.
     */
    private function fullyPayInvoice(SalesInvoice $invoice): void
    {
        $this->makePaymentForInvoice($invoice, $invoice->fresh()->total_amount);
        $invoice->markAsFullyPaid();
    }

    // =========================================================================
    // Initial state
    // =========================================================================

    public function test_new_invoice_starts_with_all_totals_at_zero_and_draft_status(): void
    {
        $invoice = $this->makeEmptyInvoice()->fresh();

        $this->assertSame(0, $invoice->total_amount);
        $this->assertSame(0, $invoice->total_payments);
        $this->assertSame(0, $invoice->total_estimated_profit);
        $this->assertSame(0, $invoice->total_realized_profit);
        $this->assertSame(SalesInvoiceStatus::Draft, $invoice->status);
        $this->assertFalse($invoice->paid);
    }

    // =========================================================================
    // total_amount and total_estimated_profit — driven by Vente events
    // =========================================================================

    public function test_total_amount_is_updated_when_an_item_is_added(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 3, profit: 600);

        $this->assertSame(6000, $invoice->fresh()->total_amount);
    }

    public function test_total_estimated_profit_is_updated_when_an_item_is_added(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 3, profit: 600);

        $this->assertSame(600, $invoice->fresh()->total_estimated_profit);
    }

    public function test_total_amount_accumulates_across_multiple_items(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 1000, quantity: 2, profit: 200); // 2000
        $this->addItemToInvoice($invoice, price: 500, quantity: 4, profit: 100);  // 2000

        $this->assertSame(4000, $invoice->fresh()->total_amount);
    }

    public function test_total_estimated_profit_accumulates_across_multiple_items(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 1000, quantity: 2, profit: 200);
        $this->addItemToInvoice($invoice, price: 500, quantity: 4, profit: 300);

        $this->assertSame(500, $invoice->fresh()->total_estimated_profit);
    }

    public function test_total_amount_decreases_when_an_item_is_deleted(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $itemToDelete = $this->addItemToInvoice($invoice, price: 1000, quantity: 2, profit: 200);
        $this->addItemToInvoice($invoice, price: 500, quantity: 2, profit: 100);

        $itemToDelete->delete();

        $this->assertSame(1000, $invoice->fresh()->total_amount);
    }

    public function test_total_amount_is_zero_when_all_items_are_deleted(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $item = $this->addItemToInvoice($invoice, price: 1000, quantity: 2, profit: 200);

        $item->delete();

        $freshInvoice = $invoice->fresh();
        $this->assertSame(0, $freshInvoice->total_amount);
        $this->assertSame(0, $freshInvoice->total_estimated_profit);
    }

    public function test_total_amount_updates_when_an_existing_item_is_modified(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $item = $this->addItemToInvoice($invoice, price: 1000, quantity: 2, profit: 200);

        $item->quantity = 5;
        $item->save();

        $this->assertSame(5000, $invoice->fresh()->total_amount);
    }

    // =========================================================================
    // total_payments and total_realized_profit — driven by Payment events
    // =========================================================================

    public function test_total_payments_is_updated_when_a_payment_is_created(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 600);

        $this->makePaymentForInvoice($invoice, 1000);

        $this->assertSame(1000, $invoice->fresh()->total_payments);
    }

    public function test_total_payments_accumulates_across_multiple_payments(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 600);

        $this->makePaymentForInvoice($invoice, 800);
        $this->makePaymentForInvoice($invoice, 700);

        $this->assertSame(1500, $invoice->fresh()->total_payments);
    }

    public function test_total_payments_decreases_when_a_payment_is_deleted(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 600);

        $firstPayment = $this->makePaymentForInvoice($invoice, 800);
        $this->makePaymentForInvoice($invoice, 700);

        $firstPayment->delete();

        $this->assertSame(700, $invoice->fresh()->total_payments);
    }

    public function test_total_realized_profit_is_updated_when_a_payment_is_created(): void
    {
        $invoice = $this->makeEmptyInvoice();
        // total = 2000, profit = 600 (30% margin); half payment → 300 realized
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 600);

        $this->makePaymentForInvoice($invoice, 1000);

        $this->assertSame(300, $invoice->fresh()->total_realized_profit);
    }

    public function test_total_realized_profit_equals_total_estimated_profit_when_fully_paid(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 600);

        $this->fullyPayInvoice($invoice);

        $freshInvoice = $invoice->fresh();
        $this->assertSame($freshInvoice->total_estimated_profit, $freshInvoice->total_realized_profit);
    }

    // =========================================================================
    // Status transitions
    // =========================================================================

    public function test_status_is_draft_when_no_payments_exist(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 1000, quantity: 1, profit: 200);

        $this->assertSame(SalesInvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_status_becomes_partially_paid_after_a_partial_payment(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        $this->makePaymentForInvoice($invoice, 1000);

        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
    }

    public function test_status_becomes_fully_paid_only_after_mark_as_fully_paid_is_called(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        // Payment alone does not set FULLY_PAID
        $this->makePaymentForInvoice($invoice, 2000);
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        // Only markAsFullyPaid() transitions to FULLY_PAID
        $invoice->markAsFullyPaid();
        $freshInvoice = $invoice->fresh();
        $this->assertSame(SalesInvoiceStatus::FullyPaid, $freshInvoice->status);
        $this->assertTrue($freshInvoice->paid);
    }

    public function test_status_becomes_fully_paid_when_multiple_payments_cover_total_and_mark_is_called(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        $this->makePaymentForInvoice($invoice, 1200);
        $this->makePaymentForInvoice($invoice, 800);
        $invoice->markAsFullyPaid();

        $this->assertSame(SalesInvoiceStatus::FullyPaid, $invoice->fresh()->status);
    }

    public function test_mark_as_fully_paid_throws_when_payments_do_not_cover_total(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        $this->makePaymentForInvoice($invoice, 1000); // only partial

        $this->expectException(\App\Exceptions\InvoicePaymentMismatchException::class);
        $invoice->markAsFullyPaid();
    }

    public function test_status_reverts_to_partially_paid_after_a_payment_is_deleted_from_fully_paid_invoice(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        $partialPayment = $this->makePaymentForInvoice($invoice, 500);
        $this->makePaymentForInvoice($invoice, 1500);
        $invoice->markAsFullyPaid();
        $this->assertSame(SalesInvoiceStatus::FullyPaid, $invoice->fresh()->status);

        $partialPayment->delete();

        $freshInvoice = $invoice->fresh();
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $freshInvoice->status);
        $this->assertFalse($freshInvoice->paid);
    }

    public function test_status_reverts_to_draft_when_all_payments_are_deleted_from_fully_paid_invoice(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);

        $this->fullyPayInvoice($invoice);
        $this->assertSame(SalesInvoiceStatus::FullyPaid, $invoice->fresh()->status);

        $invoice->payments()->delete();

        $freshInvoice = $invoice->fresh();
        $this->assertSame(SalesInvoiceStatus::Draft, $freshInvoice->status);
        $this->assertFalse($freshInvoice->paid);
    }

    public function test_paid_boolean_is_kept_in_sync_with_status(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 1000, quantity: 1, profit: 200);

        $this->assertFalse($invoice->fresh()->paid);

        $this->fullyPayInvoice($invoice);
        $this->assertTrue($invoice->fresh()->paid);

        $invoice->payments()->delete();
        $invoice->recalculateStoredTotals();
        $this->assertFalse($invoice->fresh()->paid);
    }

    // =========================================================================
    // Backward-compat aliases
    // =========================================================================

    public function test_total_alias_returns_total_amount(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 1500, quantity: 2, profit: 300);

        $freshInvoice = $invoice->fresh();
        $this->assertSame($freshInvoice->total_amount, $freshInvoice->total);
    }

    public function test_total_paid_alias_returns_total_payments(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);
        $this->makePaymentForInvoice($invoice, 800);

        $freshInvoice = $invoice->fresh();
        $this->assertSame($freshInvoice->total_payments, $freshInvoice->total_paid);
    }

    public function test_total_remaining_alias_returns_correct_balance(): void
    {
        $invoice = $this->makeEmptyInvoice();
        $this->addItemToInvoice($invoice, price: 2000, quantity: 1, profit: 400);
        $this->makePaymentForInvoice($invoice, 600);

        $freshInvoice = $invoice->fresh();
        $this->assertSame(1400, $freshInvoice->total_remaining);
        $this->assertSame($freshInvoice->total_amount - $freshInvoice->total_payments, $freshInvoice->total_remaining);
    }
}
