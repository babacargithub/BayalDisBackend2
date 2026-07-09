<?php

namespace Tests\Feature\SalesInvoice;

use App\Exceptions\InvoiceWriteOffException;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for marking invoices as definitively lost (written off).
 *
 * Written-off invoices must be excluded from every default query (sums, relations,
 * stats) via the SalesInvoice global scope, and only reachable through the explicit
 * withWrittenOff()/onlyWrittenOff() audit scopes.
 */
class SalesInvoiceWriteOffTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Commercial $commercial;

    private Product $product;

    private SalesInvoiceService $salesInvoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->salesInvoiceService = app(SalesInvoiceService::class);

        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => User::factory()->create()->id,
        ]);

        $this->commercial = Commercial::create([
            'name' => 'Commercial '.uniqid(),
            'phone_number' => '221'.rand(700000000, 799999999),
            'gender' => 'male',
            'user_id' => User::factory()->create()->id,
            'team_id' => $team->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'Customer '.uniqid(),
            'address' => 'Test Address',
            'phone_number' => '221'.rand(700000000, 799999999),
            'owner_number' => '221'.rand(700000000, 799999999),
            'gps_coordinates' => '14.6928,17.4467',
            'commercial_id' => $this->commercial->id,
        ]);

        $this->product = Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 1000,
            'cost_price' => 600,
            'base_quantity' => 1,
        ]);
    }

    private function makeUnpaidInvoice(int $price = 5000): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'customer_id' => $this->customer->id,
            'commercial_id' => $this->commercial->id,
        ]);

        Vente::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'price' => $price,
            'quantity' => 1,
            'profit' => 400,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh();
    }

    public function test_write_off_marks_invoice_as_written_off(): void
    {
        $invoice = $this->makeUnpaidInvoice();

        $this->salesInvoiceService->writeOffInvoice($invoice);

        $this->assertTrue($invoice->fresh()->isWrittenOff());
        $this->assertNotNull($invoice->fresh()->written_off_at);
    }

    public function test_written_off_invoice_is_excluded_from_default_queries(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $this->salesInvoiceService->writeOffInvoice($invoice);

        $this->assertNull(SalesInvoice::find($invoice->id));
        $this->assertSame(0, SalesInvoice::where('customer_id', $this->customer->id)->count());
    }

    public function test_written_off_invoice_is_reachable_via_with_written_off_scope(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $this->salesInvoiceService->writeOffInvoice($invoice);

        $found = SalesInvoice::query()->withWrittenOff()->find($invoice->id);

        $this->assertNotNull($found);
        $this->assertSame($invoice->id, $found->id);
    }

    public function test_only_written_off_scope_excludes_active_invoices(): void
    {
        $activeInvoice = $this->makeUnpaidInvoice();
        $writtenOffInvoice = $this->makeUnpaidInvoice();
        $this->salesInvoiceService->writeOffInvoice($writtenOffInvoice);

        $results = SalesInvoice::query()->onlyWrittenOff()->pluck('id');

        $this->assertTrue($results->contains($writtenOffInvoice->id));
        $this->assertFalse($results->contains($activeInvoice->id));
    }

    public function test_write_off_throws_when_invoice_already_written_off(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $this->salesInvoiceService->writeOffInvoice($invoice);

        $this->expectException(InvoiceWriteOffException::class);

        $this->salesInvoiceService->writeOffInvoice($invoice->fresh());
    }

    public function test_write_off_throws_when_invoice_has_payments(): void
    {
        $invoice = $this->makeUnpaidInvoice(5000);

        Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => 1000,
            'payment_method' => 'CASH',
            'user_id' => User::factory()->create()->id,
        ]);

        $this->expectException(InvoiceWriteOffException::class);

        $this->salesInvoiceService->writeOffInvoice($invoice->fresh());
    }

    public function test_get_written_off_invoices_returns_only_written_off_ones(): void
    {
        $activeInvoice = $this->makeUnpaidInvoice();
        $writtenOffInvoiceA = $this->makeUnpaidInvoice();
        $writtenOffInvoiceB = $this->makeUnpaidInvoice();
        $this->salesInvoiceService->writeOffInvoice($writtenOffInvoiceA);
        $this->salesInvoiceService->writeOffInvoice($writtenOffInvoiceB);

        $paginated = $this->salesInvoiceService->getWrittenOffInvoices();

        $this->assertSame(2, $paginated->total());
        $this->assertFalse(collect($paginated->items())->pluck('id')->contains($activeInvoice->id));
    }
}
