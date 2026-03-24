<?php

namespace Tests\Feature\Refactoring;

use App\Enums\SalesInvoiceStatus;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the bug where fully-paid invoices were left as
 * PARTIALLY_PAID after running bayal:correct-cost-prices-and-profits.
 *
 * Root cause: recalculateStoredTotals() never auto-promotes to FULLY_PAID.
 * It only preserves FULLY_PAID if the invoice was already in that state.
 * Migrated invoices start as Draft, so even fully-settled ones landed on
 * PARTIALLY_PAID. The fix adds a promotion pass at the end of step 6.
 */
class CorrectCostPricesPromotesFullyPaidInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $team = Team::create(['name' => 'Team Test', 'user_id' => $user->id]);
        $commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $user->id,
            'team_id' => $team->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'Customer Test',
            'address' => 'Test Address',
            'phone_number' => '221700000002',
            'owner_number' => '221700000002',
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $commercial->id,
        ]);

        $this->product = Product::create([
            'name' => 'Product Test',
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Creates a SalesInvoice with one vente item and a payment covering the full amount,
     * all via DB::table() to simulate the migration pipeline which bypasses model events.
     * The invoice is left in Draft status (as MigrateSingleVentesToInvoices does).
     */
    private function makeFullySettledDraftInvoiceViaRawInsert(int $price = 1000, int $quantity = 2): SalesInvoice
    {
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'TEST-'.uniqid(),
            'customer_id' => $this->customer->id,
            'status' => SalesInvoiceStatus::Draft->value,
            'paid' => false,
            'comment' => 'Test invoice',
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $totalAmount = $price * $quantity;
        $profit = ($price - $this->product->cost_price) * $quantity;

        DB::table('ventes')->insert([
            'sales_invoice_id' => $invoiceId,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => $profit,
            'type' => 'INVOICE_ITEM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'sales_invoice_id' => $invoiceId,
            'amount' => $totalAmount,
            'profit' => 0,
            'payment_method' => 'CASH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SalesInvoice::findOrFail($invoiceId);
    }

    /**
     * Creates a partially-paid invoice via raw inserts (payments < total_amount).
     */
    private function makePartiallyPaidDraftInvoiceViaRawInsert(int $price = 1000, int $quantity = 2): SalesInvoice
    {
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'TEST-'.uniqid(),
            'customer_id' => $this->customer->id,
            'status' => SalesInvoiceStatus::Draft->value,
            'paid' => false,
            'comment' => 'Test invoice',
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $totalAmount = $price * $quantity;
        $partialPayment = intdiv($totalAmount, 2);
        $profit = ($price - $this->product->cost_price) * $quantity;

        DB::table('ventes')->insert([
            'sales_invoice_id' => $invoiceId,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => $quantity,
            'profit' => $profit,
            'type' => 'INVOICE_ITEM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'sales_invoice_id' => $invoiceId,
            'amount' => $partialPayment,
            'profit' => 0,
            'payment_method' => 'CASH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SalesInvoice::findOrFail($invoiceId);
    }

    // =========================================================================
    // Tests
    // =========================================================================

    public function test_fully_settled_draft_invoice_is_promoted_to_fully_paid_after_command(): void
    {
        $invoice = $this->makeFullySettledDraftInvoiceViaRawInsert();

        // Before the command: status is Draft, paid is false (as inserted by migration pipeline)
        $this->assertSame(SalesInvoiceStatus::Draft, $invoice->status);
        $this->assertFalse($invoice->paid);

        Artisan::call('bayal:correct-cost-prices-and-profits');

        $invoice->refresh();

        $this->assertSame(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertTrue($invoice->paid);
    }

    public function test_partially_paid_draft_invoice_is_not_promoted_to_fully_paid(): void
    {
        $invoice = $this->makePartiallyPaidDraftInvoiceViaRawInsert();

        Artisan::call('bayal:correct-cost-prices-and-profits');

        $invoice->refresh();

        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertFalse($invoice->paid);
    }

    public function test_fully_settled_invoice_has_correct_totals_after_command(): void
    {
        $price = 1000;
        $quantity = 2;
        $totalAmount = $price * $quantity;

        $invoice = $this->makeFullySettledDraftInvoiceViaRawInsert(price: $price, quantity: $quantity);

        Artisan::call('bayal:correct-cost-prices-and-profits');

        $invoice->refresh();

        $this->assertSame($totalAmount, $invoice->total_amount);
        $this->assertSame($totalAmount, $invoice->total_payments);
    }

    public function test_invoice_already_fully_paid_is_not_demoted_by_command(): void
    {
        // Create a FULLY_PAID invoice directly (not via migration pipeline) — the command must not
        // accidentally demote it when recalculateStoredTotals() is called.
        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_number' => 'TEST-'.uniqid(),
            'customer_id' => $this->customer->id,
            'status' => SalesInvoiceStatus::FullyPaid->value,
            'paid' => true,
            'comment' => 'Test invoice',
            'total_amount' => 0,
            'total_payments' => 0,
            'total_estimated_profit' => 0,
            'total_realized_profit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ventes')->insert([
            'sales_invoice_id' => $invoiceId,
            'product_id' => $this->product->id,
            'price' => 1000,
            'quantity' => 2,
            'profit' => 800,
            'type' => 'INVOICE_ITEM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'sales_invoice_id' => $invoiceId,
            'amount' => 2000,
            'profit' => 0,
            'payment_method' => 'CASH',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('bayal:correct-cost-prices-and-profits');

        $invoice = SalesInvoice::findOrFail($invoiceId);

        $this->assertSame(SalesInvoiceStatus::FullyPaid, $invoice->status);
        $this->assertTrue($invoice->paid);
    }
}
