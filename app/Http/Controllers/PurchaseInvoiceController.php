<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Product;
use Illuminate\Http\Request;
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

        $totalAmount = collect($validated['items'])->sum(function ($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $purchaseInvoice = PurchaseInvoice::create([
            'supplier_id' => $validated['supplier_id'],
            'invoice_number' => $validated['invoice_number'],
            'invoice_date' => $validated['invoice_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'status' => 'pending',
            'comment' => $validated['comment'] ?? null
        ]);

        foreach ($validated['items'] as $item) {
            $purchaseInvoice->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price']
            ]);
        }

        return redirect()->route('purchase-invoices.index');
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

        $totalAmount = collect($validated['items'])->sum(function ($item) {
            return $item['quantity'] * $item['unit_price'];
        });

        $purchaseInvoice->update([
            'invoice_number' => $validated['invoice_number'],
            'invoice_date' => $validated['invoice_date'],
            'due_date' => $validated['due_date'],
            'comment' => $validated['comment'] ?? null
        ]);

        // Delete existing items
        $purchaseInvoice->items()->delete();

        // Create new items
        foreach ($validated['items'] as $item) {
            $purchaseInvoice->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ]);
        }

        return redirect()->route('purchase-invoices.index');
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
}
