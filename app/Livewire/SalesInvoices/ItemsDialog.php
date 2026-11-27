<?php

namespace App\Livewire\SalesInvoices;

use App\Http\Requests\AddInvoiceItemRequest;
use App\Http\Requests\UpdateInvoiceItemProfitRequest;
use App\Livewire\SalesInvoices\Concerns\ManagesSelectedInvoice;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ItemsDialog extends Component
{
    use ManagesSelectedInvoice;

    public bool $isAddFormOpen = false;

    /** @var array{product_id: int|string, quantity: int|string, price: int|string} */
    public array $newItem = ['product_id' => '', 'quantity' => 1, 'price' => 0];

    #[Locked]
    public ?int $editedItemId = null;

    public int|string $editedProfit = 0;

    #[On('open-invoice-items')]
    public function open(int $salesInvoiceId): void
    {
        $this->selectInvoice($salesInvoiceId);
    }

    public function updatedNewItemProductId(int|string $productId): void
    {
        $this->newItem['price'] = Product::find($productId)?->price ?? 0;
    }

    public function addItem(SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($salesInvoiceService): string {
            $request = new AddInvoiceItemRequest;
            $validated = Validator::make($this->newItem, $request->rules(), $request->messages())->validate();

            $salesInvoice = $this->selectedInvoice();
            $salesInvoiceService->assertItemsCanBeModified($salesInvoice);
            $salesInvoiceService->addItemToInvoice($salesInvoice, $validated);

            $this->reset('newItem', 'isAddFormOpen');

            return 'Article ajouté avec succès';
        }, "Échec de l'ajout de l'article. Veuillez réessayer.");
    }

    public function removeItem(int $itemId, SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($itemId, $salesInvoiceService): string {
            $salesInvoice = $this->selectedInvoice();
            $item = Vente::findOrFail($itemId);

            $salesInvoiceService->assertItemsCanBeModified($salesInvoice);
            $salesInvoiceService->assertItemBelongsToInvoice($salesInvoice, $item);
            $salesInvoiceService->removeItemFromInvoice($salesInvoice, $item);

            return 'Article supprimé avec succès';
        }, "Échec de la suppression de l'article. Veuillez réessayer.");
    }

    public function startEditingProfit(int $itemId): void
    {
        $this->resetValidation();
        $this->editedItemId = $itemId;
        $this->editedProfit = $this->selectedInvoice()->items()->findOrFail($itemId)->profit;
    }

    public function cancelEditing(): void
    {
        $this->reset('editedItemId', 'editedProfit');
        $this->resetValidation();
    }

    public function saveProfit(SalesInvoiceService $salesInvoiceService): void
    {
        $this->runInvoiceMutation(function () use ($salesInvoiceService): string {
            $request = new UpdateInvoiceItemProfitRequest;
            $validated = Validator::make(['profit' => $this->editedProfit], $request->rules(), $request->messages())->validate();

            $salesInvoice = $this->selectedInvoice();
            $item = Vente::findOrFail($this->editedItemId);
            $salesInvoiceService->assertItemBelongsToInvoice($salesInvoice, $item);
            $salesInvoiceService->updateInvoiceItemProfit($item, (int) $validated['profit']);

            $this->reset('editedItemId', 'editedProfit');

            return 'Bénéfice mis à jour avec succès';
        }, "Échec de la mise à jour de l'article. Veuillez réessayer.");
    }

    public function render(): View
    {
        return view('livewire.sales-invoices.items-dialog', [
            'invoice' => $this->salesInvoiceId === null
                ? null
                : SalesInvoice::with(['customer:id,name', 'items.product:id,name'])->find($this->salesInvoiceId),
            'products' => $this->isAddFormOpen ? Product::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
