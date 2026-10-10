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
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The "Rapport" page must report total unpaid debt without any written-off invoice,
 * and the derived business_value must follow.
 */
class AdminRapportDebtsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Commercial $commercial;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

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

    private function makeInvoice(int $price): SalesInvoice
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
            'profit' => 100,
            'type' => Vente::TYPE_INVOICE,
        ]);

        return $invoice->fresh();
    }

    private function payOnInvoice(SalesInvoice $invoice, int $amount): void
    {
        Payment::create([
            'sales_invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_method' => 'CASH',
            'user_id' => $this->user->id,
        ]);
    }

    private function rapportStatistics(): array
    {
        $statistics = null;

        $this->actingAs($this->user)
            ->get(route('admin.rapport'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$statistics): void {
                $page->component('Admin/Rapport');
                $statistics = $page->toArray()['props']['statistics'];
            });

        return $statistics;
    }

    public function test_rapport_debt_is_zero_without_invoices(): void
    {
        $statistics = $this->rapportStatistics();

        $this->assertSame(0, $statistics['total_unpaid_invoices_amount']);
        $this->assertSame(0, $statistics['total_unpaid_invoices_count']);
    }

    public function test_rapport_debt_sums_multiple_unpaid_and_partially_paid_invoices(): void
    {
        $this->makeInvoice(5000);
        $this->makeInvoice(3000);
        $partiallyPaidInvoice = $this->makeInvoice(10000);
        $this->payOnInvoice($partiallyPaidInvoice, 4000);

        $statistics = $this->rapportStatistics();

        $this->assertSame(5000 + 3000 + 6000, $statistics['total_unpaid_invoices_amount']);
        $this->assertSame(3, $statistics['total_unpaid_invoices_count']);
    }

    public function test_rapport_debt_excludes_written_off_invoices(): void
    {
        $this->makeInvoice(5000);
        $this->makeInvoice(3000);
        $lostInvoiceA = $this->makeInvoice(40000);
        $lostInvoiceB = $this->makeInvoice(7000);

        $salesInvoiceService = app(SalesInvoiceService::class);
        $salesInvoiceService->writeOffInvoice($lostInvoiceA);
        $salesInvoiceService->writeOffInvoice($lostInvoiceB);

        $statistics = $this->rapportStatistics();

        $this->assertSame(8000, $statistics['total_unpaid_invoices_amount']);
        $this->assertSame(2, $statistics['total_unpaid_invoices_count']);
    }

    public function test_rapport_debt_is_zero_when_every_invoice_is_written_off(): void
    {
        $salesInvoiceService = app(SalesInvoiceService::class);
        $salesInvoiceService->writeOffInvoice($this->makeInvoice(5000));
        $salesInvoiceService->writeOffInvoice($this->makeInvoice(9000));

        $statistics = $this->rapportStatistics();

        $this->assertSame(0, $statistics['total_unpaid_invoices_amount']);
        $this->assertSame(0, $statistics['total_unpaid_invoices_count']);
    }

    public function test_rapport_business_value_does_not_include_written_off_debt(): void
    {
        $this->makeInvoice(5000);
        app(SalesInvoiceService::class)->writeOffInvoice($this->makeInvoice(90000));

        $statistics = $this->rapportStatistics();

        $expectedBusinessValue = $statistics['warehouse_stock_value']
            + $statistics['car_loads_stock_value']
            + $statistics['total_caisses_balance']
            + 5000;

        $this->assertSame(5000, $statistics['total_unpaid_invoices_amount']);
        $this->assertEquals($expectedBusinessValue, $statistics['business_value']);
        $this->assertEquals($expectedBusinessValue - $statistics['fond_de_roulement'], $statistics['net_plus_value']);
    }

    public function test_rapport_unpaid_total_matches_sales_invoices_index_total_remaining(): void
    {
        $this->makeInvoice(5000);
        $oldPartiallyPaidInvoice = $this->makeInvoice(10000);
        $this->payOnInvoice($oldPartiallyPaidInvoice, 4000);
        SalesInvoice::withoutGlobalScopes()
            ->whereKey($oldPartiallyPaidInvoice->id)
            ->update(['created_at' => '2026-02-01 10:00:00']);
        $fullyPaidInvoice = $this->makeInvoice(2000);
        $this->payOnInvoice($fullyPaidInvoice, 2000);
        app(SalesInvoiceService::class)->writeOffInvoice($this->makeInvoice(30000));

        $rapportStatistics = $this->rapportStatistics();

        $indexInvoices = [];
        $this->actingAs($this->user)
            ->get(route('sales-invoices.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$indexInvoices): void {
                $indexInvoices = $page->toArray()['props']['invoices']['data'];
            });

        $indexTotalRemaining = collect($indexInvoices)->sum('total_remaining');

        $this->assertSame(11000, $rapportStatistics['total_unpaid_invoices_amount']);
        $this->assertSame($rapportStatistics['total_unpaid_invoices_amount'], $indexTotalRemaining);
        $this->assertCount($rapportStatistics['total_unpaid_invoices_count'], $indexInvoices);
    }
}
