<?php

namespace App\Livewire\SalesInvoices\Concerns;

use App\Exceptions\InsufficientAccountBalanceException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PaymentCancellationException;
use App\Exceptions\SalesInvoiceOperationException;
use App\Models\SalesInvoice;
use Closure;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/** Shared state and error handling of the dialogs acting on one invoice. */
trait ManagesSelectedInvoice
{
    #[Locked]
    public ?int $salesInvoiceId = null;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    private function selectInvoice(?int $salesInvoiceId): void
    {
        $this->reset();
        $this->resetValidation();
        $this->salesInvoiceId = $salesInvoiceId;
    }

    public function close(): void
    {
        $this->selectInvoice(null);
    }

    private function selectedInvoice(): SalesInvoice
    {
        return SalesInvoice::findOrFail($this->salesInvoiceId);
    }

    /**
     * Run a mutation, translate failures into messages and tell the list to refresh.
     *
     * @param  Closure(): ?string  $mutation  Returns the success message.
     */
    private function runInvoiceMutation(Closure $mutation, string $failureMessage): bool
    {
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->resetValidation();

        try {
            $this->successMessage = $mutation();
        } catch (ValidationException $e) {
            throw $e;
        } catch (SalesInvoiceOperationException|PaymentCancellationException|InsufficientAccountBalanceException|InsufficientStockException $e) {
            $this->errorMessage = $e->getMessage();

            return false;
        } catch (Throwable $e) {
            report($e);
            $this->errorMessage = $failureMessage;

            return false;
        }

        $this->dispatch('sales-invoice-changed');

        return true;
    }
}
