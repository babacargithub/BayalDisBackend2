<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Team;
use App\Services\CarLoadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Illuminate\Http\RedirectResponse;

class PurchaseInvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $purchaseInvoices = PurchaseInvoice::with(['supplier', 'items.product'])
            ->latest()
            ->get();
        
        return Inertia::render('PurchaseInvoices/Index', [
            'purchaseInvoices' => $purchaseInvoices,
            'suppliers' => Supplier::select('id', 'name')->get(),
            'products' => Product::select('id', 'name', 'price')->get()
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
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'invoice_number' => 'required|string|unique:purchase_invoices',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $invoice = PurchaseInvoice::create([
                'supplier_id' => $validated['supplier_id'],
                'invoice_number' => $validated['invoice_number'],
                'invoice_date' => $validated['invoice_date'],
                'due_date' => $validated['due_date'],
                'comment' => $validated['comment'] ?? null,
                'status' => 'pending'
            ]);

            foreach ($validated['items'] as $item) {
                $invoiceItem = $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                // Create stock entry for each item
               
            }

            DB::commit();
            return redirect()->route('purchase-invoices.index')->with('success', 'Facture créée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la création de la facture ' . $e->getMessage()]);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(PurchaseInvoice $purchaseInvoice)
    {
        $purchaseInvoice->load(['supplier', 'items.product']);
        
        return Inertia::render('PurchaseInvoices/Show', [
            'purchaseInvoice' => $purchaseInvoice
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
            'invoice_number' => 'required|string|unique:purchase_invoices,invoice_number,' . $purchaseInvoice->id,
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'comment' => 'nullable|string',
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
                'due_date' => $validated['due_date'],
                'notes' => $validated['notes']
            ]);

            // Delete old items and their stock entries
            foreach ($purchaseInvoice->items as $item) {
                $item->stockEntries()->delete();
            }
            $purchaseInvoice->items()->delete();

            // Create new items and stock entries
            foreach ($validated['items'] as $item) {
                $invoiceItem = $purchaseInvoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                // Create stock entry for each item
                StockEntry::create([
                    'product_id' => $item['product_id'],
                    'purchase_invoice_item_id' => $invoiceItem->id,
                    'quantity' => $item['quantity'],
                    'quantity_left' => $item['quantity'],
                    'unit_price' => $item['unit_price']
                ]);
            }

            DB::commit();
            return redirect()->route('purchase-invoices.index')->with('success', 'Facture mise à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la mise à jour de la facture']);
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

    public function putItemsToStock(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        if ($purchaseInvoice->is_stocked) {
            return redirect()->back()->withErrors(['error' => 'Cette facture est déjà en stock']);
        }

        try {
            DB::beginTransaction();
            $items = [];
            foreach ($purchaseInvoice->items as $item) {
                /** @var  PurchaseInvoiceItem $item */
                StockEntry::create([
                    'product_id' => $item->product_id,
                    'purchase_invoice_item_id' => $item->id,
                    'quantity' => $item->quantity,
                    'quantity_left' => $item->quantity,
                    'unit_price' => $item->unit_price
                ]);
                $items[]=[
                    'product_id' => $item->product_id,
                    'quantity_loaded' => $item->quantity,
                    'quantity_left' => $item->quantity,
                    'loaded_at' => now(),
                    'comment'=>"Chargée depuis facture ".$purchaseInvoice->invoice_number." du "
                        .$purchaseInvoice->invoice_date->format('d/m/Y H:s:i'),
                ];
            }

            $purchaseInvoice->update(['is_stocked' => true]);

            if (request()->input('put_in_current_car_load')== true) {
                $carLoadService = app(CarLoadService::class);
                //TODO make this dynamic later
                $team = Team::firstOrFail();
                $carLoad = $carLoadService->getCurrentCarLoadForTeam($team);
                if ($carLoad == null) {
                    throw new \Exception('CarLoad not found for team ' . $team->name);
                }// Do NOT decrement main stock here since we just created StockEntry from the purchase invoice
                $carLoadService->createItems($carLoad, $items, false);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Articles mis en stock avec succès');
        } catch (\Exception $e) {
            if (app()->environment('testing')) {
                dump($e->getMessage());
            }
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la mise en stock des articles ' . $e->getMessage()]);
        }
    }
}
