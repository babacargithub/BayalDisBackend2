<?php

namespace App\Livewire\SalesInvoices;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Services\SalesInvoiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

class CreateInvoiceDialog extends Component
{
    public bool $isOpen = false;

    public string $customerSearch = '';

    #[Locked]
    public ?int $customerId = null;

    #[Locked]
    public string $customerName = '';

    public string $comment = '';

    public string $dueDate = '';

    /** @var array<int, array{key: string, product_id: int|string, quantity: int|string, price: int|string}> */
    public array $items = [];

    public ?string $errorMessage = null;

    #[On('open-create-invoice')]
    public function open(): void
    {
        $this->close();
        $this->isOpen = true;
        $this->addItemLine();
    }

    public function close(): void
    {
        $this->reset();
        $this->resetValidation();
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::withoutEagerLoads()->findOrFail($customerId);

        $this->customerId = $customer->id;
        $this->customerName = $customer->name;
        $this->customerSearch = '';
    }

    public function addItemLine(): void
    {
        $this->items[] = ['key' => (string) Str::uuid(), 'product_id' => '', 'quantity' => 1, 'price' => 0];
    }

    public function removeItemLine(int $lineIndex): void
    {
        unset($this->items[$lineIndex]);
        $this->items = array_values($this->items);
    }

    public function updatedItems(mixed $value, string $path): void
    {
        [$lineIndex, $field] = array_pad(explode('.', $path), 2, null);

        if ($field === 'product_id') {
            $this->items[$lineIndex]['price'] = Product::find($value)?->price ?? 0;
        }
    }

    public function create(SalesInvoiceService $salesInvoiceService): void
    {
        $this->errorMessage = null;

        $request = new StoreSalesInvoiceRequest;
        $validated = Validator::make([
            'customer_id' => $this->customerId,
            'comment' => $this->comment === '' ? null : $this->comment,
            'should_be_paid_at' => $this->dueDate,
            'items' => $this->items,
        ], $request->rules(), $request->messages())->validate();

        if (auth()->user()->commercial === null) {
            $this->errorMessage = "Votre compte n'est lié à aucun commercial : impossible de créer une facture.";

            return;
        }

        try {
            $salesInvoiceService->createSalesInvoice(['paid' => false, ...$validated]);
        } catch (UnprocessableEntityHttpException|InsufficientStockException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->errorMessage = 'Échec de la création de la facture.';

            return;
        }

        $this->close();
        $this->dispatch('sales-invoice-changed', message: 'Facture créée avec succès');
    }

    public function render(): View
    {
        $isSearchingCustomer = $this->isOpen && mb_strlen(trim($this->customerSearch)) >= 2;

        return view('livewire.sales-invoices.create-invoice-dialog', [
            'matchingCustomers' => $isSearchingCustomer
                ? Customer::withoutEagerLoads()
                    ->where('name', 'like', '%'.trim($this->customerSearch).'%')
                    ->orderBy('name')
                    ->limit(20)
                    ->get(['id', 'name', 'address'])
                : collect(),
            'products' => $this->isOpen ? Product::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }
}
