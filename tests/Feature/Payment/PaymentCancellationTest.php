<?php

namespace Tests\Feature\Payment;

use App\Enums\AccountType;
use App\Enums\CaisseType;
use App\Exceptions\InsufficientAccountBalanceException;
use App\Exceptions\PaymentCancellationException;
use App\Models\Account;
use App\Models\Caisse;
use App\Models\Commercial;
use App\Models\CommercialProductCommissionRate;
use App\Models\CommercialWorkPeriod;
use App\Models\Customer;
use App\Models\DailyCommission;
use App\Models\Ligne;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Sector;
use App\Models\User;
use App\Models\Vente;
use App\Models\Zone;
use App\Services\AccountService;
use App\Services\DailySalesInvoicesService;
use App\Services\PaymentCancellationService;
use App\Services\VersementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentCancellationTest extends TestCase
{
    use RefreshDatabase;

    private PaymentCancellationService $paymentCancellationService;

    private AccountService $accountService;

    private User $salespersonUser;

    private User $backOfficeUser;

    private Commercial $commercial;

    private CommercialWorkPeriod $weeklyWorkPeriod;

    /** Fixed weekly period: Mon 2 Mar → Sat 7 Mar 2026 */
    private string $periodStart = '2026-03-02';

    private string $periodEnd = '2026-03-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentCancellationService = app(PaymentCancellationService::class);
        $this->accountService = app(AccountService::class);

        $this->salespersonUser = User::factory()->create();
        $this->backOfficeUser = User::factory()->create();

        // The Commercial::created hook provisions the caisse and the
        // COMMERCIAL_COLLECTED / COMMERCIAL_COMMISSION accounts automatically.
        $this->commercial = Commercial::create([
            'name' => 'Commercial Test',
            'phone_number' => '221700000001',
            'gender' => 'male',
            'user_id' => $this->salespersonUser->id,
        ]);

        $this->weeklyWorkPeriod = CommercialWorkPeriod::create([
            'commercial_id' => $this->commercial->id,
            'period_start_date' => $this->periodStart,
            'period_end_date' => $this->periodEnd,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeProductWithOnePercentCommission(int $price = 5_000): Product
    {
        $product = Product::create([
            'name' => 'Produit '.rand(1, 9999),
            'price' => $price,
            'cost_price' => 3_000,
        ]);

        CommercialProductCommissionRate::create([
            'commercial_id' => $this->commercial->id,
            'product_id' => $product->id,
            'rate' => 0.0100,
        ]);

        return $product;
    }

    /**
     * Creates an invoice with one product line and a payment, both backdated to $paymentDate.
     * Pass $invoiceDate to create the invoice on a different (earlier) day than the payment.
     * Freezing time ensures the sync commission job runs with the correct work day.
     */
    private function makePaidInvoiceOnDate(
        Product $product,
        int $quantity,
        int $pricePerUnit,
        Carbon $paymentDate,
        ?int $paymentAmount = null,
        ?Carbon $invoiceDate = null,
    ): Payment {
        Carbon::setTestNow($invoiceDate ?? $paymentDate);

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
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $pricePerUnit,
            'profit' => 0,
            'type' => Vente::TYPE_INVOICE,
        ]);

        Carbon::setTestNow($paymentDate);

        $payment = Payment::create([
            'sales_invoice_id' => $invoice->fresh()->id,
            'amount' => $paymentAmount ?? $quantity * $pricePerUnit,
            'payment_method' => 'CASH',
            'user_id' => $this->salespersonUser->id,
        ]);

        Carbon::setTestNow();

        return $payment;
    }

    private function findDailyCommissionForDay(string $workDay): ?DailyCommission
    {
        return DailyCommission::where('commercial_work_period_id', $this->weeklyWorkPeriod->id)
            ->whereDate('work_day', $workDay)
            ->first();
    }

    private function getCollectedAccount(): Account
    {
        return Account::where('account_type', AccountType::CommercialCollected->value)
            ->where('commercial_id', $this->commercial->id)
            ->firstOrFail();
    }

    private function getCommissionAccount(): Account
    {
        return Account::where('account_type', AccountType::CommercialCommission->value)
            ->where('commercial_id', $this->commercial->id)
            ->firstOrFail();
    }

    private function createMerchandiseSalesAccount(): Account
    {
        return Account::create([
            'name' => 'Vente marchandises',
            'account_type' => AccountType::MerchandiseSales,
            'balance' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Reproduces the account movements of "Clôturer Journée" for the given work day:
     * COMMERCIAL_COLLECTED → MERCHANDISE_SALES (full collected balance), then
     * MERCHANDISE_SALES → COMMERCIAL_COMMISSION (net commission), then finalized_at.
     */
    private function simulateCloseDayForWorkDay(string $workDay): DailyCommission
    {
        $dailyCommission = $this->findDailyCommissionForDay($workDay);
        $this->assertNotNull($dailyCommission, 'Expected a DailyCommission for the work day before closing it.');

        $collectedAccount = $this->getCollectedAccount();
        $collectedAccount->refresh();

        $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();

        if ($collectedAccount->balance > 0) {
            $this->accountService->transferBetweenAccounts(
                fromAccount: $collectedAccount,
                toAccount: $merchandiseSalesAccount,
                amount: $collectedAccount->balance,
                label: "Clôture journée {$workDay}",
                referenceType: 'CLOSE_DAY',
            );
        }

        if ($dailyCommission->net_commission > 0) {
            $this->accountService->transferBetweenAccounts(
                fromAccount: $merchandiseSalesAccount,
                toAccount: $this->getCommissionAccount(),
                amount: $dailyCommission->net_commission,
                label: "Commission journée {$workDay}",
                referenceType: 'CLOSE_DAY',
            );
        }

        $dailyCommission->update(['finalized_at' => now()]);

        return $dailyCommission->fresh();
    }

    // ── Scenario 1: same-day cancellation (day still open) ──────────────────

    public function test_same_day_cancellation_marks_payment_cancelled_and_excludes_it_from_all_queries(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Mauvais client sélectionné');

        $cancelledPayment = Payment::withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED)->findOrFail($payment->id);
        $this->assertNotNull($cancelledPayment->cancelled_at);
        $this->assertSame($this->backOfficeUser->id, $cancelledPayment->cancelled_by_user_id);
        $this->assertSame('Mauvais client sélectionné', $cancelledPayment->cancellation_reason);

        $this->assertNull(Payment::find($payment->id), 'Cancelled payments must be excluded by the global scope.');
        $this->assertSame(0, (int) Payment::sum('amount'));
        $this->assertSame(0, (int) $this->commercial->payments()->count());
    }

    public function test_same_day_cancellation_recalculates_invoice_stored_totals_and_demotes_status(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'), paymentAmount: 20_000);

        $invoice = $payment->salesInvoice->fresh();
        $this->assertSame(20_000, $invoice->total_payments);
        $this->assertSame('PARTIALLY_PAID', $invoice->status->value);

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $invoice->refresh();
        $this->assertSame(0, $invoice->total_payments);
        $this->assertSame(0, $invoice->total_realized_profit);
        $this->assertNotSame('PARTIALLY_PAID', $invoice->status->value);
        $this->assertFalse((bool) $invoice->paid);
    }

    public function test_same_day_cancellation_reverses_commercial_caisse_and_collected_account(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $commercialCaisse = $this->commercial->caisse->fresh();
        $this->assertSame(50_000, $commercialCaisse->balance);
        $this->assertSame(50_000, $this->getCollectedAccount()->balance);

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Montant erroné');

        $this->assertSame(0, $commercialCaisse->fresh()->balance);
        $this->assertSame(0, $this->getCollectedAccount()->fresh()->balance);

        $withdrawTransaction = $commercialCaisse->transactions()
            ->where('transaction_type', Caisse::TRANSACTION_TYPE_WITHDRAW)
            ->where('amount', 50_000)
            ->first();
        $this->assertNotNull($withdrawTransaction, 'A caisse WITHDRAW transaction must record the reversal.');

        $this->assertTrue($this->accountService->isGlobalInvariantSatisfied());
    }

    public function test_same_day_cancellation_recalculates_daily_commission_to_exclude_the_payment(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->assertSame(500, $this->findDailyCommissionForDay('2026-03-03')->net_commission);

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $this->assertSame(0, $this->findDailyCommissionForDay('2026-03-03')->net_commission);
    }

    // ── Scenario 2: cancellation after the day was closed / versed ──────────

    public function test_after_day_close_cancellation_claws_back_commission_and_debits_merchandise_sales(): void
    {
        $this->createMerchandiseSalesAccount();
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->simulateCloseDayForWorkDay('2026-03-03');

        $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();
        $this->assertSame(49_500, $merchandiseSalesAccount->fresh()->balance);
        $this->assertSame(500, $this->getCommissionAccount()->fresh()->balance);

        $cancellationResult = $this->paymentCancellationService->cancelPayment(
            $payment, $this->backOfficeUser->id, 'Paiement enregistré deux fois',
        );

        $this->assertTrue($cancellationResult->wasCancelledAfterDayClose);
        $this->assertSame(500, $cancellationResult->commissionClawbackAmount);
        $this->assertSame(50_000, $cancellationResult->cashReversalAmount);

        // Commission recomputed to 0 and the overpaid 500 F recovered.
        $this->assertSame(0, $this->findDailyCommissionForDay('2026-03-03')->net_commission);
        $this->assertSame(0, $this->getCommissionAccount()->fresh()->balance);

        // Merchandise sales: 49 500 + 500 (clawback) − 50 000 (cash reversal) = 0.
        $this->assertSame(0, $merchandiseSalesAccount->fresh()->balance);

        // The physical cash was still in the commercial's caisse (no versement yet).
        $this->assertSame(0, $this->commercial->caisse->fresh()->balance);

        $this->assertTrue($this->accountService->isGlobalInvariantSatisfied());
    }

    public function test_after_versement_cancellation_withdraws_the_cash_from_the_main_caisse(): void
    {
        $this->createMerchandiseSalesAccount();
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $mainCaisse = Caisse::create([
            'name' => 'Caisse principale',
            'caisse_type' => CaisseType::Main,
            'balance' => 0,
            'closed' => false,
        ]);

        app(VersementService::class)->performVersement($this->commercial, $mainCaisse);

        $this->assertSame(50_000, $mainCaisse->fresh()->balance);
        $this->assertSame(0, $this->commercial->caisse->fresh()->balance);
        $this->assertSame(500, $this->getCommissionAccount()->fresh()->balance);

        $cancellationResult = $this->paymentCancellationService->cancelPayment(
            $payment, $this->backOfficeUser->id, 'Mauvais client',
        );

        $this->assertTrue($cancellationResult->wasCancelledAfterDayClose);
        $this->assertSame('Caisse principale', $cancellationResult->cashReversalCaisseName);

        // The phantom cash leaves the main caisse, not the (empty) commercial caisse.
        $this->assertSame(0, $mainCaisse->fresh()->balance);
        $this->assertSame(0, $this->commercial->caisse->fresh()->balance);
        $this->assertSame(0, $this->getCommissionAccount()->fresh()->balance);
        $this->assertSame(0, $this->accountService->getMerchandiseSalesAccount()->fresh()->balance);

        $this->assertTrue($this->accountService->isGlobalInvariantSatisfied());
    }

    public function test_after_day_close_cancellation_rolls_back_everything_when_merchandise_balance_is_insufficient(): void
    {
        $this->createMerchandiseSalesAccount();
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->simulateCloseDayForWorkDay('2026-03-03');

        // Drain merchandise sales so the cash reversal cannot be funded.
        $merchandiseSalesAccount = $this->accountService->getMerchandiseSalesAccount();
        $this->accountService->debit($merchandiseSalesAccount, 49_000, 'Drain pour test');

        try {
            $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');
            $this->fail('Expected InsufficientAccountBalanceException.');
        } catch (InsufficientAccountBalanceException) {
            // Expected.
        }

        // The whole cancellation must have been rolled back.
        $freshPayment = Payment::withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED)->findOrFail($payment->id);
        $this->assertNull($freshPayment->cancelled_at);
        $this->assertSame(500, $this->getCommissionAccount()->fresh()->balance);
        $this->assertSame(500, $this->findDailyCommissionForDay('2026-03-03')->net_commission);
        $this->assertSame(50_000, $payment->salesInvoice->fresh()->total_payments);
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function test_cancelling_an_already_cancelled_payment_throws(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $this->expectException(PaymentCancellationException::class);
        $this->paymentCancellationService->cancelPayment(
            $payment->fresh(),
            $this->backOfficeUser->id,
            'Encore une fois',
        );
    }

    public function test_deleting_an_already_cancelled_payment_does_not_reverse_the_caisse_twice(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');
        $this->assertSame(0, $this->commercial->caisse->fresh()->balance);

        Payment::withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED)->findOrFail($payment->id)->delete();

        $this->assertSame(0, $this->commercial->caisse->fresh()->balance, 'Deleting a cancelled payment must not withdraw again.');
        $this->assertSame(0, $this->getCollectedAccount()->fresh()->balance);
    }

    // ── /ventes daily timeline ────────────────────────────────────────────────

    public function test_daily_timeline_keeps_cancelled_payment_rows_visible_and_flagged(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate(
            $product, 10, 5_000,
            paymentDate: Carbon::parse('2026-03-03 10:00'),
            invoiceDate: Carbon::parse('2026-03-02 09:00'),
        );

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $timelineItems = app(DailySalesInvoicesService::class)
            ->getDailyTimeline(Carbon::parse('2026-03-03'), null, null);

        $cancelledPaymentRow = $timelineItems->first(
            fn ($timelineItem) => ! $timelineItem->isInvoice() && $timelineItem->paymentId === $payment->id,
        );

        $this->assertNotNull($cancelledPaymentRow, 'Cancelled payments must stay visible in the timeline for audit.');
        $this->assertNotNull($cancelledPaymentRow->cancelledAt);
        $this->assertSame('Doublon', $cancelledPaymentRow->cancellationReason);
        $this->assertSame($this->backOfficeUser->name, $cancelledPaymentRow->cancelledByName);

        $rowAsArray = $cancelledPaymentRow->toArray();
        $this->assertNotNull($rowAsArray['cancelled_at']);
        $this->assertSame('Doublon', $rowAsArray['cancellation_reason']);
    }

    public function test_daily_timeline_marks_active_payment_rows_as_not_cancelled(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate(
            $product, 10, 5_000,
            paymentDate: Carbon::parse('2026-03-03 10:00'),
            invoiceDate: Carbon::parse('2026-03-02 09:00'),
        );

        $timelineItems = app(DailySalesInvoicesService::class)
            ->getDailyTimeline(Carbon::parse('2026-03-03'), null, null);

        $activePaymentRow = $timelineItems->first(
            fn ($timelineItem) => ! $timelineItem->isInvoice() && $timelineItem->paymentId === $payment->id,
        );

        $this->assertNotNull($activePaymentRow);
        $this->assertNull($activePaymentRow->cancelledAt);
        $this->assertNull($activePaymentRow->toArray()['cancelled_at']);
    }

    // ── Raw SQL paths (bypass the global scope, filtered explicitly) ────────

    public function test_sector_debt_increases_back_when_an_invoice_payment_is_cancelled(): void
    {
        $zone = Zone::create([
            'name' => 'Zone Test',
            'gps_coordinates' => '14.6928,17.4467',
            'ville' => 'Dakar',
            'quartiers' => 'Plateau',
        ]);
        $ligne = Ligne::create(['name' => 'Ligne Test', 'zone_id' => $zone->id]);
        $sector = Sector::create(['name' => 'Secteur Test', 'ligne_id' => $ligne->id]);
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'), paymentAmount: 20_000);

        $payment->salesInvoice->customer->forceFill(['sector_id' => $sector->id])->save();

        $this->assertSame(30_000, $sector->fresh()->total_debt, 'Debt before cancellation: 50 000 invoiced − 20 000 paid.');

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $this->assertSame(50_000, $sector->fresh()->total_debt, 'Cancelled payment must no longer reduce the sector debt.');
    }

    public function test_filtered_invoices_pdf_export_reports_zero_paid_when_the_only_payment_was_cancelled(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));
        $invoiceId = $payment->sales_invoice_id;

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $capturedInvoices = null;
        Pdf::shouldReceive('loadView')
            ->once()
            ->andReturnUsing(function (string $view, array $viewData) use (&$capturedInvoices) {
                $capturedInvoices = $viewData['invoices'];

                $pdfMock = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
                $pdfMock->shouldReceive('download')->andReturn(response('pdf'));

                return $pdfMock;
            });

        $response = $this->actingAs($this->backOfficeUser)->get(route('sales-invoices.export-pdf'));
        $response->assertOk();

        $exportedInvoice = collect($capturedInvoices)->firstWhere('id', $invoiceId);
        $this->assertNotNull($exportedInvoice);
        $this->assertSame(50_000, (int) $exportedInvoice->total);
        $this->assertSame(
            0,
            (int) $exportedInvoice->total_paid,
            'The raw total_paid subquery must not count cancelled payments.',
        );
    }

    // ── HTTP endpoint ─────────────────────────────────────────────────────────

    public function test_cancel_payment_endpoint_cancels_the_payment_with_a_reason(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $response = $this->actingAs($this->backOfficeUser)->post(
            route('sales-invoices.payments.cancel', [$payment->sales_invoice_id, $payment->id]),
            ['cancellation_reason' => 'Montant erroné saisi sur le terrain'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cancelledPayment = Payment::withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED)->findOrFail($payment->id);
        $this->assertNotNull($cancelledPayment->cancelled_at);
        $this->assertSame($this->backOfficeUser->id, $cancelledPayment->cancelled_by_user_id);
    }

    public function test_cancel_payment_endpoint_requires_a_reason(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $response = $this->actingAs($this->backOfficeUser)->post(
            route('sales-invoices.payments.cancel', [$payment->sales_invoice_id, $payment->id]),
            [],
        );

        $response->assertSessionHasErrors('cancellation_reason');

        $freshPayment = Payment::withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED)->findOrFail($payment->id);
        $this->assertNull($freshPayment->cancelled_at);
    }

    public function test_cancel_payment_endpoint_returns_404_for_an_already_cancelled_payment(): void
    {
        $product = $this->makeProductWithOnePercentCommission();
        $payment = $this->makePaidInvoiceOnDate($product, 10, 5_000, Carbon::parse('2026-03-03 10:00'));

        $this->paymentCancellationService->cancelPayment($payment, $this->backOfficeUser->id, 'Doublon');

        $response = $this->actingAs($this->backOfficeUser)->post(
            route('sales-invoices.payments.cancel', [$payment->sales_invoice_id, $payment->id]),
            ['cancellation_reason' => 'Encore'],
        );

        $response->assertNotFound();
    }
}
