<?php

namespace Tests\Feature\SalesInvoice;

use App\Data\SalesInvoice\DebtListDTO;
use App\Data\SalesInvoice\DebtListFilterDTO;
use App\Enums\DayOfWeek;
use App\Livewire\SalesInvoices\DebtList;
use App\Livewire\SalesInvoices\ItemsDialog;
use App\Livewire\SalesInvoices\PaymentsDialog;
use App\Models\Beat;
use App\Models\BeatRound;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vente;
use App\Services\ArAgingService;
use App\Services\BeatService;
use App\Services\SalesInvoiceDebtListService;
use App\Services\SalesInvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesInvoiceDebtPageTest extends TestCase
{
    use RefreshDatabase;

    private User $backOfficeUser;

    private Commercial $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday
        Carbon::setTestNow('2026-03-20 10:00:00');
        $this->backOfficeUser = User::factory()->create();
        $this->actingAs($this->backOfficeUser);
        $this->commercial = Commercial::factory()->create();
    }

    private function createInvoice(
        string $customerName,
        int $amount,
        string $createdAt,
        ?string $dueDate = null,
    ): SalesInvoice {
        $customer = Customer::create([
            'name' => $customerName,
            'address' => 'Dakar',
            'phone_number' => (string) random_int(700000000, 799999999),
            'owner_number' => (string) random_int(700000000, 799999999),
            'gps_coordinates' => '14.7,-17.4',
            'commercial_id' => $this->commercial->id,
        ]);

        $salesInvoice = SalesInvoice::create([
            'customer_id' => $customer->id,
            'should_be_paid_at' => $dueDate,
        ]);
        $salesInvoice->created_at = Carbon::parse($createdAt);
        $salesInvoice->save();

        $product = $this->createProduct();
        Vente::create([
            'sales_invoice_id' => $salesInvoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $amount,
            'profit' => 100,
            'type' => 'INVOICE_ITEM',
        ]);

        return $salesInvoice->refresh();
    }

    private function createProduct(int $price = 500): Product
    {
        return Product::create(['name' => 'Produit '.uniqid(), 'price' => $price, 'cost_price' => 400]);
    }

    private function buildDebtList(DebtListFilterDTO $filter = new DebtListFilterDTO): DebtListDTO
    {
        return app(SalesInvoiceDebtListService::class)->buildDebtList($filter);
    }

    /** @return array<string, int> */
    private function remainingByBucket(DebtListDTO $debtList): array
    {
        return collect($debtList->buckets)->mapWithKeys(fn ($bucket) => [$bucket->labelFr => $bucket->totalAmount])->all();
    }

    public function test_invoices_are_grouped_by_calendar_day_age_and_every_total_adds_up(): void
    {
        $this->createInvoice('Today', 1_000, '2026-03-20 08:00:00');
        // Later in the day than "now" would suggest less than 24h: still 1 calendar day old.
        $this->createInvoice('Yesterday', 2_000, '2026-03-19 23:00:00');
        $this->createInvoice('Seven days', 3_000, '2026-03-13 18:00:00');
        $this->createInvoice('Eight days', 4_000, '2026-03-12 18:00:00');
        $this->createInvoice('Old', 5_000, '2026-02-01 12:00:00');
        $partiallyPaid = $this->createInvoice('Old partial', 6_000, '2026-02-01 12:00:00');
        Payment::create(['sales_invoice_id' => $partiallyPaid->id, 'amount' => 2_500, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);

        $debtList = $this->buildDebtList();

        $this->assertSame([
            "Aujourd'hui" => 1_000,
            '1 à 7 jours' => 5_000,
            '8 à 14 jours' => 4_000,
            '15 à 21 jours' => 0,
            '22 à 30 jours' => 0,
            'Plus de 30 jours' => 8_500,
        ], $this->remainingByBucket($debtList));
        $this->assertSame(6, $debtList->invoiceCount);
        $this->assertSame(21_000, $debtList->totalAmount);
        $this->assertSame(2_500, $debtList->totalPaid);
        $this->assertSame(18_500, $debtList->totalRemaining);
        $this->assertSame(11_000, $debtList->buckets[5]->totalInvoicedAmount);
        $this->assertSame(2_500, $debtList->buckets[5]->totalPaidAmount);
    }

    public function test_aging_counts_calendar_days_regardless_of_the_time_of_day(): void
    {
        $salesInvoice = $this->createInvoice('Late evening', 1_000, '2026-03-12 23:59:00');

        $invoiceRow = app(ArAgingService::class)->buildInvoiceRow($salesInvoice, Carbon::parse('2026-03-13'));

        $this->assertSame(1, $invoiceRow['days_overdue']);
    }

    public function test_fully_paid_invoices_are_excluded_and_empty_list_totals_zero(): void
    {
        $salesInvoice = $this->createInvoice('Settled', 1_000, '2026-03-18');
        Payment::create(['sales_invoice_id' => $salesInvoice->id, 'amount' => 1_000, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);
        $salesInvoice->refresh()->markAsFullyPaid();

        $debtList = $this->buildDebtList();

        $this->assertSame(0, $debtList->invoiceCount);
        $this->assertSame(0, $debtList->totalRemaining);
        $this->assertCount(5, $debtList->buckets);
    }

    public function test_written_off_invoices_are_never_listed_counted_or_offered_as_a_week(): void
    {
        $this->createInvoice('Boutique active', 1_000, '2026-03-18');
        $writtenOffInvoice = $this->createInvoice('Boutique perdue', 30_000, '2026-02-04', '2026-02-10');
        app(SalesInvoiceService::class)->writeOffInvoice($writtenOffInvoice);

        $debtList = $this->buildDebtList();

        $this->assertSame(1, $debtList->invoiceCount);
        $this->assertSame(1_000, $debtList->totalAmount);
        $this->assertSame(1_000, $debtList->totalRemaining);
        $this->assertSame(0, $debtList->overdueInvoiceCount);
        $this->assertSame(['2026-03-16'], array_keys($debtList->availableWeekLabels));

        foreach (['all', 'unpaid', 'past_due'] as $paymentStatus) {
            $filteredList = $this->buildDebtList(new DebtListFilterDTO($paymentStatus, 'perdue'));
            $this->assertSame(0, $filteredList->invoiceCount, $paymentStatus);
        }

        $this->get(route('sales-invoices.index'))
            ->assertOk()
            ->assertSee('Boutique active')
            ->assertDontSee('Boutique perdue');
    }

    public function test_filters_by_customer_name_due_date_and_week(): void
    {
        $this->createInvoice('Boutique Awa', 1_000, '2026-03-18', '2026-03-20');
        $this->createInvoice('Boutique Moussa', 2_000, '2026-03-10', '2026-03-25');
        $this->createInvoice('Kiosque', 4_000, '2026-03-09', '2026-03-12');

        $this->assertSame(3_000, $this->buildDebtList(new DebtListFilterDTO(customerNameSearch: 'BOUTIQUE'))->totalRemaining);
        $this->assertSame(5_000, $this->buildDebtList(new DebtListFilterDTO(paymentStatus: 'past_due'))->totalRemaining);
        $this->assertSame(0, $this->buildDebtList(new DebtListFilterDTO(paymentStatus: 'paid'))->totalRemaining);
        $this->assertSame(7_000, $this->buildDebtList(new DebtListFilterDTO(paymentStatus: 'unpaid'))->totalRemaining);

        $filteredByWeek = $this->buildDebtList(new DebtListFilterDTO(selectedWeekStartDates: ['2026-03-09']));
        $this->assertSame(6_000, $filteredByWeek->totalRemaining);
        $this->assertSame(['2026-03-16', '2026-03-09'], array_keys($filteredByWeek->availableWeekLabels));
    }

    public function test_beat_round_performance_page_renders_with_the_shared_aging_report(): void
    {
        $salesInvoice = $this->createInvoice('Boutique Awa', 12_500, '2026-03-18 23:00:00');
        $beat = Beat::create(['name' => 'Beat Plateau', 'day_of_week' => DayOfWeek::Friday->value, 'commercial_id' => $this->commercial->id]);
        $beatRound = BeatRound::create(['beat_id' => $beat->id, 'name' => 'Tournée', 'commercial_id' => $this->commercial->id, 'planned_at' => '2026-03-20']);
        BeatStop::create(['beat_round_id' => $beatRound->id, 'customer_id' => $salesInvoice->customer_id]);

        $this->get(route('beats.rounds.performance', [$beat, $beatRound]))
            ->assertOk()
            ->assertSee('Analyse des créances par ancienneté')
            ->assertSee('1 à 7 jours')
            ->assertSee('Boutique Awa')
            ->assertSee('12 500')
            ->assertDontSee('Voir les paiements');
    }

    public function test_beat_debt_is_not_reduced_by_payments_on_written_off_invoices(): void
    {
        $activeInvoice = $this->createInvoice('Boutique', 5_000, '2026-03-10');
        $lostInvoice = $this->createInvoice('Ancienne boutique', 9_000, '2026-03-10');
        $lostInvoice->update(['customer_id' => $activeInvoice->customer_id]);
        $payment = Payment::create(['sales_invoice_id' => $lostInvoice->id, 'amount' => 4_000, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);
        $payment->created_at = Carbon::parse('2026-03-11');
        $payment->saveQuietly();
        app(SalesInvoiceService::class)->writeOffInvoice($lostInvoice->refresh());

        $beat = Beat::create(['name' => 'Beat', 'day_of_week' => DayOfWeek::Friday->value, 'commercial_id' => $this->commercial->id]);
        $beatRound = BeatRound::create(['beat_id' => $beat->id, 'name' => 'Tournée', 'commercial_id' => $this->commercial->id, 'planned_at' => '2026-03-20']);
        BeatStop::create(['beat_round_id' => $beatRound->id, 'customer_id' => $activeInvoice->customer_id]);

        $this->assertSame(5_000, app(BeatService::class)->totalDebtToCollectForBeatRound($beatRound));
    }

    public function test_page_renders_the_livewire_list(): void
    {
        $this->createInvoice('Boutique Awa', 12_500, '2026-03-18');

        $this->get(route('sales-invoices.index'))
            ->assertOk()
            ->assertSeeLivewire(DebtList::class)
            ->assertSee('Boutique Awa')
            ->assertSee('12 500');
    }

    public function test_inertia_visit_forces_a_full_page_load(): void
    {
        $this->get(route('sales-invoices.index'), ['X-Inertia' => 'true', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('sales-invoices.index'));
    }

    public function test_invoice_without_payment_can_be_deleted(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 1_000, '2026-03-18');

        Livewire::test(DebtList::class)
            ->call('askToDeleteInvoice', $salesInvoice->id)
            ->assertSet('pendingInvoiceIds', [$salesInvoice->id])
            ->call('confirmPendingAction')
            ->assertSet('successMessage', 'Facture supprimée avec succès.');

        $this->assertDatabaseMissing('sales_invoices', ['id' => $salesInvoice->id]);
    }

    public function test_invoice_with_payment_cannot_be_deleted(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 1_000, '2026-03-18');
        Payment::create(['sales_invoice_id' => $salesInvoice->id, 'amount' => 100, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);

        Livewire::test(DebtList::class)
            ->call('askToDeleteInvoice', $salesInvoice->id)
            ->assertSet('pendingAction', null)
            ->assertSet('errorMessage', 'Impossible de supprimer une facture avec des paiements.');

        $this->assertDatabaseHas('sales_invoices', ['id' => $salesInvoice->id]);
    }

    public function test_invoice_is_written_off_only_after_confirmation(): void
    {
        $keptInvoice = $this->createInvoice('Boutique active', 1_000, '2026-03-18');
        $lostInvoice = $this->createInvoice('Boutique perdue', 30_000, '2026-03-18');

        $list = Livewire::test(DebtList::class)
            ->call('askToWriteOffInvoice', $lostInvoice->id)
            ->assertSet('pendingInvoiceIds', [$lostInvoice->id]);
        $this->assertNull($lostInvoice->refresh()->written_off_at);

        $list->call('confirmPendingAction')
            ->assertSet('successMessage', 'Facture radiée avec succès.')
            ->assertSet('pendingAction', null)
            ->assertDontSee('Boutique perdue')
            ->assertSee('Boutique active');

        $this->assertNotNull(SalesInvoice::withWrittenOff()->find($lostInvoice->id)->written_off_at);
        $this->assertNull($keptInvoice->refresh()->written_off_at);
        $this->assertSame(1_000, $this->buildDebtList()->totalRemaining);
    }

    public function test_cancelling_the_write_off_confirmation_changes_nothing(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 1_000, '2026-03-18');

        Livewire::test(DebtList::class)
            ->call('askToWriteOffInvoice', $salesInvoice->id)
            ->call('cancelPendingAction')
            ->call('confirmPendingAction');

        $this->assertNull($salesInvoice->refresh()->written_off_at);
    }

    public function test_partially_paid_invoice_shows_the_lost_amount_before_being_written_off(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 10_000, '2026-03-18');
        Payment::create(['sales_invoice_id' => $salesInvoice->id, 'amount' => 2_500, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);

        Livewire::test(DebtList::class)
            ->call('askToWriteOffInvoice', $salesInvoice->id)
            ->assertSee('Montant perdu')
            ->assertSee('7 500')
            ->call('confirmPendingAction')
            ->assertSet('successMessage', 'Facture radiée avec succès.');

        $this->assertSame(0, $this->buildDebtList()->totalRemaining);
        $this->assertDatabaseHas('payments', ['sales_invoice_id' => $salesInvoice->id, 'amount' => 2_500, 'cancelled_at' => null]);
    }

    private function payOnInvoice(SalesInvoice $salesInvoice, int $amount): void
    {
        Payment::create(['sales_invoice_id' => $salesInvoice->id, 'amount' => $amount, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);
    }

    public function test_bulk_write_off_acts_on_every_selected_invoice_and_no_other(): void
    {
        $unpaidInvoice = $this->createInvoice('Une', 1_000, '2026-03-18');
        $partiallyPaidInvoice = $this->createInvoice('Deux', 10_000, '2026-03-18');
        $this->payOnInvoice($partiallyPaidInvoice, 4_000);
        $keptInvoice = $this->createInvoice('Gardée', 500, '2026-03-18');

        Livewire::test(DebtList::class)
            ->set('selectedInvoiceIds', [(string) $unpaidInvoice->id, (string) $partiallyPaidInvoice->id])
            ->call('askToWriteOffSelectedInvoices')
            ->assertSee('4 000 XOF')
            ->assertSee('7 000 XOF')
            ->call('confirmPendingAction')
            ->assertSet('successMessage', '2 factures radiées avec succès.')
            ->assertSet('selectedInvoiceIds', []);

        $this->assertSame(2, SalesInvoice::onlyWrittenOff()->count());
        $this->assertNotNull(SalesInvoice::find($keptInvoice->id));
        $this->assertSame(500, $this->buildDebtList()->totalRemaining);
    }

    public function test_bulk_delete_skips_invoices_with_payments(): void
    {
        $firstInvoice = $this->createInvoice('Une', 1_000, '2026-03-18');
        $secondInvoice = $this->createInvoice('Deux', 2_000, '2026-03-18');
        $paidOnInvoice = $this->createInvoice('Trois', 3_000, '2026-03-18');
        $this->payOnInvoice($paidOnInvoice, 100);

        Livewire::test(DebtList::class)
            ->call('selectAllDisplayedInvoices')
            ->call('askToDeleteSelectedInvoices')
            ->assertSet('skippedInvoiceCount', 1)
            ->call('confirmPendingAction')
            ->assertSet('successMessage', '2 factures supprimées avec succès.');

        $this->assertDatabaseMissing('sales_invoices', ['id' => $firstInvoice->id]);
        $this->assertDatabaseMissing('sales_invoices', ['id' => $secondInvoice->id]);
        $this->assertDatabaseHas('sales_invoices', ['id' => $paidOnInvoice->id]);
    }

    public function test_bulk_action_changes_nothing_when_one_invoice_became_ineligible(): void
    {
        $firstInvoice = $this->createInvoice('Une', 1_000, '2026-03-18');
        $secondInvoice = $this->createInvoice('Deux', 2_000, '2026-03-18');

        $list = Livewire::test(DebtList::class)
            ->call('selectAllDisplayedInvoices')
            ->call('askToDeleteSelectedInvoices');
        // A payment arrives while the confirmation dialog is open.
        $this->payOnInvoice($firstInvoice, 100);
        $list->call('confirmPendingAction')
            ->assertSet('errorMessage', 'Impossible de supprimer une facture avec des paiements.');

        $this->assertDatabaseHas('sales_invoices', ['id' => $firstInvoice->id]);
        $this->assertDatabaseHas('sales_invoices', ['id' => $secondInvoice->id]);
    }

    public function test_selection_is_cleared_by_filters_and_hidden_invoices_are_never_acted_on(): void
    {
        $visibleInvoice = $this->createInvoice('Awa', 1_000, '2026-03-18');
        $hiddenInvoice = $this->createInvoice('Moussa', 2_000, '2026-03-18');

        Livewire::test(DebtList::class)
            ->call('selectAllDisplayedInvoices')
            ->set('customerNameSearch', 'awa')
            ->assertSet('selectedInvoiceIds', [])
            ->set('selectedInvoiceIds', [(string) $visibleInvoice->id, (string) $hiddenInvoice->id])
            ->call('askToDeleteSelectedInvoices')
            ->assertSet('pendingInvoiceIds', [$visibleInvoice->id]);
    }

    public function test_payment_can_be_added_updated_and_deleted_with_correct_totals(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 10_000, '2026-03-18');

        $dialog = Livewire::test(PaymentsDialog::class)
            ->call('open', $salesInvoice->id)
            ->set('newPayment.amount', 4_000)
            ->set('newPayment.payment_method', 'WAVE')
            ->call('addPayment')
            ->assertHasNoErrors()
            ->assertDispatched('sales-invoice-changed');

        $this->assertSame(4_000, $salesInvoice->refresh()->total_payments);
        $payment = $salesInvoice->payments()->sole();

        $dialog->call('startEditing', $payment->id)
            ->set('editedPayment.amount', 3_000)
            ->call('updatePayment')
            ->assertHasNoErrors();
        $this->assertSame(3_000, $salesInvoice->refresh()->total_payments);

        $dialog->call('deletePayment', $payment->id);
        $this->assertSame(0, $salesInvoice->refresh()->total_payments);
    }

    public function test_payment_above_remaining_balance_is_rejected(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 10_000, '2026-03-18');

        Livewire::test(PaymentsDialog::class)
            ->call('open', $salesInvoice->id)
            ->set('newPayment.amount', 10_001)
            ->set('newPayment.payment_method', 'CASH')
            ->call('addPayment')
            ->assertSet('errorMessage', 'Le montant du paiement dépasse le solde restant.')
            ->assertNotDispatched('sales-invoice-changed');

        $this->assertSame(0, $salesInvoice->refresh()->total_payments);
    }

    public function test_payment_of_another_invoice_cannot_be_deleted(): void
    {
        $openedInvoice = $this->createInvoice('Boutique', 10_000, '2026-03-18');
        $otherInvoice = $this->createInvoice('Kiosque', 10_000, '2026-03-18');
        $otherPayment = Payment::create(['sales_invoice_id' => $otherInvoice->id, 'amount' => 100, 'payment_method' => 'CASH', 'user_id' => $this->backOfficeUser->id]);

        Livewire::test(PaymentsDialog::class)
            ->call('open', $openedInvoice->id)
            ->call('deletePayment', $otherPayment->id);

        $this->assertDatabaseHas('payments', ['id' => $otherPayment->id]);
    }

    public function test_item_can_be_added_its_profit_edited_and_removed(): void
    {
        $salesInvoice = $this->createInvoice('Boutique', 10_000, '2026-03-18');
        $product = $this->createProduct(700);

        $dialog = Livewire::test(ItemsDialog::class)
            ->call('open', $salesInvoice->id)
            ->set('newItem.product_id', $product->id)
            ->assertSet('newItem.price', 700)
            ->set('newItem.quantity', 3)
            ->call('addItem')
            ->assertHasNoErrors();

        $this->assertSame(12_100, $salesInvoice->refresh()->total_amount);
        $addedItem = $salesInvoice->items()->where('product_id', $product->id)->sole();

        $dialog->call('startEditingProfit', $addedItem->id)
            ->set('editedProfit', 250)
            ->call('saveProfit')
            ->assertHasNoErrors();
        $this->assertSame(350, $salesInvoice->refresh()->total_estimated_profit);

        $dialog->call('removeItem', $addedItem->id);
        $this->assertSame(10_000, $salesInvoice->refresh()->total_amount);
    }
}
