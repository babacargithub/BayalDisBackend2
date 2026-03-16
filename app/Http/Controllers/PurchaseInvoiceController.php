<?php

namespace App\Http\Controllers;

use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\CarLoadService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PurchaseInvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): \Inertia\Response
    {
        $purchaseInvoices = PurchaseInvoice::with(['supplier', 'items.product'])
            ->latest()
            ->get();

        $activeCarLoads = CarLoad::with('team')
            ->whereNotIn('status', [
                CarLoadStatus::FullInventory->value,
                CarLoadStatus::TerminatedAndTransferred->value,
            ])
            ->where(function ($query) {
                $query->whereNull('return_date')
                    ->orWhereDate('return_date', '>=', now()->toDateString());
            })
            ->orderBy('load_date', 'desc')
            ->get(['id', 'name', 'team_id', 'load_date', 'status']);

        return Inertia::render('PurchaseInvoices/Index', [
            'purchaseInvoices' => $purchaseInvoices,
            'suppliers' => Supplier::select('id', 'name')->get(),
            'products' => Product::select('id', 'name', 'price')->get(),
            'activeCarLoads' => $activeCarLoads,
            'warehouses' => Warehouse::select('id', 'name')->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, PurchaseInvoiceService $purchaseInvoiceService): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'invoice_number' => 'nullable|string|unique:purchase_invoices',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
            'is_paid' => 'boolean',
            'transportation_cost' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $transportationCost = (int) ($validated['transportation_cost'] ?? 0);
            $invoiceNumber = ! empty($validated['invoice_number'])
                ? $validated['invoice_number']
                : $this->generateUniqueInvoiceNumber();

            $invoice = PurchaseInvoice::create([
                'supplier_id' => $validated['supplier_id'],
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'is_paid' => $validated['is_paid'] ?? false,
                'transportation_cost' => $transportationCost,
                'status' => 'pending',
            ]);

            foreach ($validated['items'] as $item) {
                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            if ($transportationCost > 0) {
                $purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);
            }

            DB::commit();

            return redirect()->route('purchase-invoices.index')->with('success', 'Facture créée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Erreur lors de la création de la facture '.$e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load(['supplier', 'items.product']);

        return Inertia::render('PurchaseInvoices/Show', [
            'purchaseInvoice' => $purchaseInvoice,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_number' => 'required|string|unique:purchase_invoices,invoice_number,'.$purchaseInvoice->id,
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
            'is_paid' => 'boolean',
            'transportation_cost' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $purchaseInvoice->update([
                'invoice_number' => $validated['invoice_number'],
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'] ?? null,
                'comment' => $validated['comment'] ?? null,
                'is_paid' => $validated['is_paid'] ?? false,
                'transportation_cost' => (int) ($validated['transportation_cost'] ?? 0),
            ]);

            // Items are frozen once the invoice is stocked — do not modify them
            if (! $purchaseInvoice->is_stocked) {
                $purchaseInvoice->items()->delete();

                foreach ($validated['items'] as $item) {
                    $purchaseInvoice->items()->create([
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('purchase-invoices.index')->with('success', 'Facture mise à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Erreur lors de la mise à jour de la facture: '.$e->getMessage()]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        if ($purchaseInvoice->paid_amount > 0) {
            return back()->withErrors(['error' => 'Cannot delete an invoice that has payments']);
        }

        $purchaseInvoice->delete();

        return redirect()->route('purchase-invoices.index');
    }

    public function putItemsToStock(Request $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        if ($purchaseInvoice->is_stocked) {
            return redirect()->back()->withErrors(['error' => 'Cette facture est déjà en stock']);
        }

        $validated = $request->validate([
            'car_load_id' => 'nullable|integer|exists:car_loads,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $carLoadId = $validated['car_load_id'] ?? null;
        $warehouseId = $validated['warehouse_id'] ?? null;

        try {
            DB::beginTransaction();

            $carLoadItemsToCreate = [];

            foreach ($purchaseInvoice->items as $item) {
                /** @var PurchaseInvoiceItem $item */
                StockEntry::create([
                    'product_id' => $item->product_id,
                    'purchase_invoice_item_id' => $item->id,
                    'quantity' => $item->quantity,
                    'quantity_left' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'transportation_cost' => $item->transportation_cost_per_unit,
                    'warehouse_id' => $carLoadId === null ? $warehouseId : null,
                ]);

                $carLoadItemsToCreate[] = [
                    'product_id' => $item->product_id,
                    'quantity_loaded' => $item->quantity,
                    'quantity_left' => $item->quantity,
                    'loaded_at' => now(),
                    'comment' => 'Chargée depuis facture '.$purchaseInvoice->invoice_number.' du '
                        .$purchaseInvoice->invoice_date->format('d/m/Y H:i:s'),
                ];
            }

            $purchaseInvoice->update(['is_stocked' => true]);

            if ($carLoadId !== null) {
                $carLoad = CarLoad::find($carLoadId);
                if ($carLoad === null) {
                    throw new \Exception('Chargement introuvable avec l\'identifiant '.$carLoadId);
                }
                $carLoadService = app(CarLoadService::class);
                $carLoadService->createItemsToCarLoad($carLoad, $carLoadItemsToCreate);
            }

            DB::commit();

            return redirect()->back()->with('success', 'Articles mis en stock avec succès');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Erreur lors de la mise en stock des articles: '.$e->getMessage()]);
        }
    }

    private function generateUniqueInvoiceNumber(): string
    {
        $datePrefix = 'PINV-'.now()->format('Ymd');
        $existingCountForToday = PurchaseInvoice::where('invoice_number', 'like', $datePrefix.'%')->count();
        $sequenceNumber = $existingCountForToday + 1;

        $generatedInvoiceNumber = $datePrefix.str_pad((string) $sequenceNumber, 2, '0', STR_PAD_LEFT);

        while (PurchaseInvoice::where('invoice_number', $generatedInvoiceNumber)->exists()) {
            $sequenceNumber++;
            $generatedInvoiceNumber = $datePrefix.str_pad((string) $sequenceNumber, 2, '0', STR_PAD_LEFT);
        }

        return $generatedInvoiceNumber;
    }
}
