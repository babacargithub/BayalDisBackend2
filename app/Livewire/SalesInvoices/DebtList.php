<?php

namespace App\Livewire\SalesInvoices;

use App\Data\Beat\ArBucket;
use App\Data\SalesInvoice\BulkInvoiceActionPreviewDTO;
use App\Data\SalesInvoice\DebtListFilterDTO;
use App\Exceptions\InvoiceWriteOffException;
use App\Exceptions\SalesInvoiceOperationException;
use App\Models\SalesInvoice;
use App\Services\SalesInvoiceDebtListService;
use App\Services\SalesInvoiceService;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class DebtList extends Component
{
    public string $paymentStatus = DebtListFilterDTO::STATUS_ALL;

    public string $customerNameSearch = '';

    /** @var string[] */
    public array $selectedWeekStartDates = [];

    public bool $isWeekFilterOpen = false;

    private const ACTION_DELETE = 'delete';

    private const ACTION_WRITE_OFF = 'writeOff';

    /** @var array<int, int|string> Checked rows (checkbox values arrive as strings). */
    public array $selectedInvoiceIds = [];

    #[Locked]
    public ?string $pendingAction = null;

    /** @var int[] Exactly the invoices the open confirmation dialog is about. */
    #[Locked]
    public array $pendingInvoiceIds = [];

    #[Locked]
    public int $skippedInvoiceCount = 0;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->successMessage = session('success');
        $this->errorMessage = session('errors')?->first();
    }

    #[On('sales-invoice-changed')]
    public function refreshList(?string $message = null): void
    {
        $this->successMessage = $message;
        $this->errorMessage = null;
    }

    public function openWeekFilter(): void
    {
        $this->isWeekFilterOpen = true;
    }

    public function closeWeekFilter(): void
    {
        $this->isWeekFilterOpen = false;
    }

    public function updated(string $propertyName): void
    {
        // A selection never outlives the filters it was made under.
        if (str_starts_with($propertyName, 'selectedWeekStartDates') || in_array($propertyName, ['paymentStatus', 'customerNameSearch'], true)) {
            $this->clearSelection();
        }
    }

    public function selectAllWeeks(SalesInvoiceDebtListService $debtListService): void
    {
        $this->selectedWeekStartDates = array_keys(
            $debtListService->buildDebtList(new DebtListFilterDTO)->availableWeekLabels
        );
        $this->clearSelection();
    }

    public function clearWeekFilter(): void
    {
        $this->selectedWeekStartDates = [];
        $this->clearSelection();
    }

    public function selectAllDisplayedInvoices(SalesInvoiceDebtListService $debtListService): void
    {
        $this->selectedInvoiceIds = array_map('strval', $this->displayedInvoiceIds($debtListService));
    }

    public function clearSelection(): void
    {
        $this->selectedInvoiceIds = [];
    }

    public function askToDeleteInvoice(int $salesInvoiceId, SalesInvoiceService $salesInvoiceService): void
    {
        $this->askToActOnInvoice(self::ACTION_DELETE, $salesInvoiceId, $salesInvoiceService->assertInvoiceCanBeDeleted(...));
    }

    public function askToWriteOffInvoice(int $salesInvoiceId, SalesInvoiceService $salesInvoiceService): void
    {
        $this->askToActOnInvoice(self::ACTION_WRITE_OFF, $salesInvoiceId, $salesInvoiceService->assertInvoiceCanBeWrittenOff(...));
    }

    public function askToDeleteSelectedInvoices(SalesInvoiceService $salesInvoiceService, SalesInvoiceDebtListService $debtListService): void
    {
        $this->askToActOnSelection(
            self::ACTION_DELETE,
            $salesInvoiceService->previewBulkDeletion($this->selectedDisplayedInvoiceIds($debtListService)),
            'Aucune des factures sélectionnées ne peut être supprimée : elles ont des paiements.',
        );
    }

    public function askToWriteOffSelectedInvoices(SalesInvoiceService $salesInvoiceService, SalesInvoiceDebtListService $debtListService): void
    {
        $this->askToActOnSelection(
            self::ACTION_WRITE_OFF,
            $salesInvoiceService->previewBulkWriteOff($this->selectedDisplayedInvoiceIds($debtListService)),
            'Aucune des factures sélectionnées ne peut être radiée : elles sont entièrement payées.',
        );
    }

    public function cancelPendingAction(): void
    {
        $this->reset('pendingAction', 'pendingInvoiceIds', 'skippedInvoiceCount');
    }

    public function confirmPendingAction(SalesInvoiceService $salesInvoiceService): void
    {
        if ($this->pendingAction === null) {
            return;
        }

        $isDeletion = $this->pendingAction === self::ACTION_DELETE;
        $invoiceCount = count($this->pendingInvoiceIds);

        try {
            if ($isDeletion) {
                $salesInvoiceService->deleteSalesInvoices($this->pendingInvoiceIds);
            } else {
                $salesInvoiceService->writeOffInvoices($this->pendingInvoiceIds);
            }

            $pastParticiple = $isDeletion ? 'supprimée' : 'radiée';
            $this->successMessage = $invoiceCount === 1
                ? "Facture {$pastParticiple} avec succès."
                : "{$invoiceCount} factures {$pastParticiple}s avec succès.";
            $this->clearSelection();
        } catch (SalesInvoiceOperationException|InvoiceWriteOffException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $this->errorMessage = $isDeletion
                ? "Échec de la suppression. Aucune facture n'a été supprimée."
                : "Échec de la radiation. Aucune facture n'a été radiée.";
        }

        $this->cancelPendingAction();
    }

    /** @param  Closure(SalesInvoice): void  $assertEligible */
    private function askToActOnInvoice(string $action, int $salesInvoiceId, Closure $assertEligible): void
    {
        $this->resetMessages();

        try {
            $assertEligible(SalesInvoice::findOrFail($salesInvoiceId));
            $this->pendingAction = $action;
            $this->pendingInvoiceIds = [$salesInvoiceId];
            $this->skippedInvoiceCount = 0;
        } catch (SalesInvoiceOperationException|InvoiceWriteOffException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    private function askToActOnSelection(string $action, BulkInvoiceActionPreviewDTO $preview, string $nothingEligibleMessage): void
    {
        $this->resetMessages();

        if ($preview->eligibleInvoiceIds === []) {
            $this->errorMessage = $nothingEligibleMessage;

            return;
        }

        $this->pendingAction = $action;
        $this->pendingInvoiceIds = $preview->eligibleInvoiceIds;
        $this->skippedInvoiceCount = $preview->ineligibleInvoiceCount;
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    private function currentFilter(): DebtListFilterDTO
    {
        return new DebtListFilterDTO(
            paymentStatus: $this->paymentStatus,
            customerNameSearch: $this->customerNameSearch,
            selectedWeekStartDates: array_values($this->selectedWeekStartDates),
        );
    }

    /** @return int[] */
    private function displayedInvoiceIds(SalesInvoiceDebtListService $debtListService): array
    {
        return collect($debtListService->buildDebtList($this->currentFilter())->buckets)
            ->flatMap(fn (ArBucket $bucket) => array_column($bucket->invoices, 'id'))
            ->all();
    }

    /** @return int[] */
    private function selectedDisplayedInvoiceIds(SalesInvoiceDebtListService $debtListService): array
    {
        return array_values(array_intersect(
            $this->displayedInvoiceIds($debtListService),
            array_map('intval', $this->selectedInvoiceIds),
        ));
    }

    public function render(SalesInvoiceDebtListService $debtListService): View
    {
        $debtList = $debtListService->buildDebtList($this->currentFilter());

        return view('livewire.sales-invoices.debt-list', [
            'debtList' => $debtList,
            'pendingPreview' => $this->pendingAction === self::ACTION_WRITE_OFF
                ? app(SalesInvoiceService::class)->previewBulkWriteOff($this->pendingInvoiceIds)
                : null,
            'filteredPdfUrl' => route('sales-invoices.export-pdf', array_filter([
                'filter' => $this->paymentStatus === DebtListFilterDTO::STATUS_ALL ? null : $this->paymentStatus,
                'search' => $this->customerNameSearch,
                'weeks' => array_values($this->selectedWeekStartDates),
            ])),
        ]);
    }
}
