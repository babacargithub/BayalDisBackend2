<?php

namespace App\Livewire\SalesInvoices;

use App\Http\Requests\Api\PaySalesInvoiceRequest;
use App\Http\Requests\CancelPaymentRequest;
use App\Livewire\SalesInvoices\Concerns\ManagesSelectedInvoice;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Services\PaymentCancellationService;
use App\Services\SalesInvoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class PaymentsDialog extends Component
{
    use ManagesSelectedInvoice;

    private const EMPTY_PAYMENT = ['amount' => '', 'payment_method' => '', 'comment' => ''];

    public bool $isAddFormOpen = false;

    /** @var array{amount: int|string, payment_method: string, comment: string} */
    public array $newPayment = self::EMPTY_PAYMENT;

    #[Locked]
    public ?int $editedPaymentId = null;

    /** @var array{amount: int|string, payment_method: string, comment: string} */
    public array $editedPayment = self::EMPTY_PAYMENT;

    #[Locked]
    public ?int $cancelledPaymentId = null;

    public string $cancellationReason = '';

    #[On('open-invoice-payments')]
    public function open(int $salesInvoiceId): void
    {
        $this->selectInvoice($salesInvoiceId);
    }

    public function addPayment(SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($salesInvoiceService): string {
            $validated = $this->validatePayment($this->newPayment);

            $salesInvoice = $this->selectedInvoice();
            $salesInvoiceService->assertNewPaymentIsAllowed($salesInvoice, (int) $validated['amount']);
            $salesInvoiceService->paySalesInvoice($salesInvoice, $validated, auth()->id());

            $this->reset('newPayment', 'isAddFormOpen');

            return 'Paiement ajouté avec succès';
        }, "Échec de l'ajout du paiement. Veuillez réessayer.");
    }

    public function startEditing(int $paymentId): void
    {
        $this->resetValidation();
        $payment = $this->selectedInvoice()->payments()->findOrFail($paymentId);

        $this->editedPaymentId = $payment->id;
        $this->editedPayment = [
            'amount' => $payment->amount,
            'payment_method' => strtoupper((string) $payment->payment_method),
            'comment' => (string) $payment->comment,
        ];
    }

    public function cancelEditing(): void
    {
        $this->reset('editedPaymentId', 'editedPayment');
        $this->resetValidation();
    }

    public function updatePayment(SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($salesInvoiceService): string {
            $validated = $this->validatePayment($this->editedPayment);

            $salesInvoice = $this->selectedInvoice();
            $payment = Payment::findOrFail($this->editedPaymentId);
            $salesInvoiceService->assertPaymentBelongsToInvoice($salesInvoice, $payment);
            $salesInvoiceService->assertPaymentAmountCanBeChanged($salesInvoice, $payment, (int) $validated['amount']);
            $salesInvoiceService->updatePaymentOnInvoice($salesInvoice, $payment, $validated);

            $this->reset('editedPaymentId', 'editedPayment');

            return 'Paiement mis à jour avec succès';
        }, 'Échec de la mise à jour du paiement. Veuillez réessayer.');
    }

    public function deletePayment(int $paymentId, SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($paymentId, $salesInvoiceService): string {
            $payment = Payment::findOrFail($paymentId);
            $salesInvoiceService->assertPaymentBelongsToInvoice($this->selectedInvoice(), $payment);
            $salesInvoiceService->removePaymentFromInvoice($payment);

            return 'Paiement supprimé avec succès.';
        }, 'Échec de la suppression du paiement. Veuillez réessayer.');
    }

    public function startCancellation(int $paymentId): void
    {
        $this->resetValidation();
        $this->cancellationReason = '';
        $this->cancelledPaymentId = $this->selectedInvoice()->payments()->findOrFail($paymentId)->id;
    }

    public function abortCancellation(): void
    {
        $this->reset('cancelledPaymentId', 'cancellationReason');
        $this->resetValidation();
    }

    public function confirmCancellation(
        SalesInvoiceService $salesInvoiceService,
        PaymentCancellationService $paymentCancellationService,
    ): void {
        $this->runInvoiceMutation(function () use ($salesInvoiceService, $paymentCancellationService): string {
            $request = new CancelPaymentRequest;
            $validated = Validator::make(
                ['cancellation_reason' => $this->cancellationReason],
                $request->rules(),
                $request->messages(),
            )->validate();

            $payment = Payment::findOrFail($this->cancelledPaymentId);
            $salesInvoiceService->assertPaymentBelongsToInvoice($this->selectedInvoice(), $payment);

            $cancellationResult = $paymentCancellationService->cancelPayment(
                payment: $payment,
                cancelledByUserId: auth()->id(),
                cancellationReason: $validated['cancellation_reason'],
            );

            $this->reset('cancelledPaymentId', 'cancellationReason');

            return $cancellationResult->buildSuccessMessage();
        }, "Échec de l'annulation du paiement. Veuillez réessayer.");
    }

    /**
     * @param  array<string, mixed>  $paymentData
     * @return array<string, mixed>
     */
    private function validatePayment(array $paymentData): array
    {
        return Validator::make(
            $paymentData,
            PaySalesInvoiceRequest::rulesForInvoice($this->salesInvoiceId),
            (new PaySalesInvoiceRequest)->messages(),
        )->validate();
    }

    public function render(): View
    {
        return view('livewire.sales-invoices.payments-dialog', [
            'invoice' => $this->salesInvoiceId === null ? null : SalesInvoice::with([
                'customer:id,name',
                // Cancelled payments stay visible here for audit.
                'payments' => fn ($paymentsQuery) => $paymentsQuery->withoutGlobalScope(Payment::SCOPE_NOT_CANCELLED),
                'payments.cancelledBy:id,name',
            ])->find($this->salesInvoiceId),
            'paymentMethods' => [
                Vente::PAYMENT_METHOD_CASH => 'Cash',
                Vente::PAYMENT_METHOD_WAVE => 'Wave',
                Vente::PAYMENT_METHOD_OM => 'Om',
            ],
        ]);
    }
}
