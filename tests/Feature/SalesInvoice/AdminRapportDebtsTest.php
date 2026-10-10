<?php

namespace Tests\Feature\SalesInvoice;

use App\Enums\AccountDebtStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountDebt;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\SalesInvoice;
use App\Models\Supplier;
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

    private function makePurchaseInvoice(array $itemLines, bool $isPaid, int $transportationCost = 0): PurchaseInvoice
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Supplier test']);

        $purchaseInvoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'PI-'.uniqid(),
            'invoice_date' => '2026-10-01',
            'is_paid' => $isPaid,
            'transportation_cost' => $transportationCost,
        ]);

        foreach ($itemLines as [$quantity, $unitPrice]) {
            PurchaseInvoiceItem::create([
                'purchase_invoice_id' => $purchaseInvoice->id,
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'transportation_cost' => 0,
            ]);
        }

        return $purchaseInvoice;
    }

    private function makeBorrowingsAccount(int $balance = 0): Account
    {
        return Account::create([
            'name' => 'Emprunts',
            'account_type' => AccountType::Management->value,
            'balance' => $balance,
            'is_active' => true,
        ]);
    }

    private function makeDebt(Account $debtorAccount, Account $creditorAccount, int $originalAmount, int $remainingAmount): AccountDebt
    {
        return AccountDebt::create([
            'debtor_account_id' => $debtorAccount->id,
            'creditor_account_id' => $creditorAccount->id,
            'original_amount' => $originalAmount,
            'remaining_amount' => $remainingAmount,
            'status' => match (true) {
                $remainingAmount === 0 => AccountDebtStatus::FullyRepaid,
                $remainingAmount < $originalAmount => AccountDebtStatus::PartiallyRepaid,
                default => AccountDebtStatus::Pending,
            },
            'reason' => 'Test',
        ]);
    }

    private function makeSalesAccount(): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales->value,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    public function test_rapport_supplier_debts_and_borrowings_are_zero_without_data(): void
    {
        $statistics = $this->rapportStatistics();

        $this->assertSame(0, $statistics['total_supplier_debts_amount']);
        $this->assertSame(0, $statistics['total_unpaid_purchase_invoices_count']);
        $this->assertSame(0, $statistics['total_borrowings_amount']);
    }

    public function test_rapport_supplier_debts_sum_unpaid_purchase_invoice_lines_and_ignore_paid_ones(): void
    {
        $this->makePurchaseInvoice([[10, 1500], [3, 2000]], isPaid: false);
        $this->makePurchaseInvoice([[1, 7000]], isPaid: false, transportationCost: 900);
        $this->makePurchaseInvoice([[100, 5000]], isPaid: true);

        $statistics = $this->rapportStatistics();

        $this->assertSame(15000 + 6000 + 7000, $statistics['total_supplier_debts_amount']);
        $this->assertSame(2, $statistics['total_unpaid_purchase_invoices_count']);
    }

    public function test_rapport_supplier_debt_is_zero_when_all_purchase_invoices_are_paid(): void
    {
        $this->makePurchaseInvoice([[4, 2500]], isPaid: true);

        $statistics = $this->rapportStatistics();

        $this->assertSame(0, $statistics['total_supplier_debts_amount']);
        $this->assertSame(0, $statistics['total_unpaid_purchase_invoices_count']);
    }

    public function test_rapport_borrowings_sum_outstanding_debts_owed_to_emprunts_account(): void
    {
        $borrowingsAccount = $this->makeBorrowingsAccount();
        $salesAccount = $this->makeSalesAccount();
        $otherCreditorAccount = Account::create([
            'name' => 'Autre', 'account_type' => AccountType::Management->value, 'balance' => 0, 'is_active' => true,
        ]);

        $this->makeDebt($salesAccount, $borrowingsAccount, 950000, 950000);
        $this->makeDebt($salesAccount, $borrowingsAccount, 500000, 300000);
        $this->makeDebt($salesAccount, $borrowingsAccount, 157000, 0);
        $this->makeDebt($salesAccount, $otherCreditorAccount, 80000, 80000);

        $statistics = $this->rapportStatistics();

        $this->assertSame(950000 + 300000, $statistics['total_borrowings_amount']);
    }

    public function test_rapport_borrowings_include_current_emprunts_account_balance(): void
    {
        $borrowingsAccount = $this->makeBorrowingsAccount(balance: 25000);
        $this->makeDebt($this->makeSalesAccount(), $borrowingsAccount, 100000, 100000);

        $statistics = $this->rapportStatistics();

        $this->assertSame(125000, $statistics['total_borrowings_amount']);
    }

    public function test_rapport_borrowings_are_zero_when_emprunts_account_does_not_exist(): void
    {
        $statistics = $this->rapportStatistics();

        $this->assertSame(0, $statistics['total_borrowings_amount']);
    }

    public function test_rapport_net_plus_value_deducts_supplier_debts_and_borrowings(): void
    {
        $this->makeInvoice(5000);
        $this->makePurchaseInvoice([[2, 10000]], isPaid: false);
        $this->makeDebt($this->makeSalesAccount(), $this->makeBorrowingsAccount(), 40000, 40000);

        $statistics = $this->rapportStatistics();

        $this->assertSame(20000, $statistics['total_supplier_debts_amount']);
        $this->assertSame(40000, $statistics['total_borrowings_amount']);
        $this->assertEquals(
            $statistics['business_value'] - $statistics['fond_de_roulement'] - 20000 - 40000,
            $statistics['net_plus_value']
        );
    }
}
