<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class SalesInvoiceController extends Controller
{
    public function index()
    {
        $invoices = SalesInvoice::with(['customer', 'items.product', 'payments'])
            ->latest()
            ->paginate(10);

        return Inertia::render('SalesInvoices/Index', [
            'invoices' => $invoices,
            'customers' => Customer::select('id', 'name')->get(),
            'products' => Product::select('id', 'name', 'price')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'comment' => 'nullable|string',
            'should_be_paid_at' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $invoice = SalesInvoice::create([
                'customer_id' => $request->customer_id,
                'comment' => $request->comment,
                'paid' => false,
                'should_be_paid_at' => $request->should_be_paid_at,
            ]);

            foreach ($request->items as $item) {
                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ]);
            }

            return redirect()->back()->with('success', 'Invoice created successfully.');
        });
    }

    public function show(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load(['customer', 'items.product', 'payments']);
        
        return Inertia::render('SalesInvoices/Show', [
            'invoice' => $salesInvoice
        ]);
    }

    public function update(Request $request, SalesInvoice $salesInvoice)
    {
        $request->validate([
            'paid' => 'boolean',
            'should_be_paid_at' => 'nullable|date',
            'comment' => 'nullable|string',
        ]);

        $salesInvoice->update($request->only(['paid', 'should_be_paid_at', 'comment']));

        return redirect()->back()->with('success', 'Invoice updated successfully.');
    }

    public function destroy(SalesInvoice $salesInvoice)
    {
        $salesInvoice->delete();
        
        return redirect()->route('sales-invoices.index')->with('success', 'Invoice deleted successfully.');
    }

    public function addItem(Request $request, SalesInvoice $salesInvoice)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|integer|min:0',
        ]);

        if ($salesInvoice->paid) {
            return response()->json(['message' => 'Cannot modify a paid invoice'], 422);
        }

        $salesInvoice->items()->create($request->only(['product_id', 'quantity', 'price']));

        return redirect()->back()->with('success', 'Item added successfully.');
    }

    public function removeItem(SalesInvoice $salesInvoice, SalesInvoiceItem $item)
    {
        if ($salesInvoice->paid) {
            return response()->json(['message' => 'Cannot modify a paid invoice'], 422);
        }

        if ($item->sales_invoice_id !== $salesInvoice->id) {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        $item->delete();

        return redirect()->back()->with('success', 'Item removed successfully.');
    }

    public function addPayment(Request $request, SalesInvoice $salesInvoice)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required|date',
            'comment' => 'nullable|string',
        ]);

        if ($salesInvoice->paid) {
            return response()->json(['message' => 'Invoice is already paid'], 422);
        }

        $totalPaid = $salesInvoice->payments()->sum('amount');
        $remaining = $salesInvoice->total - $totalPaid;

        if ($request->amount > $remaining) {
            return response()->json(['message' => 'Payment amount exceeds remaining balance'], 422);
        }

        $payment = $salesInvoice->payments()->create([
            'amount' => $request->amount,
            'payment_date' => $request->payment_date,
            'comment' => $request->comment,
        ]);

        // Check if invoice is fully paid
        $newTotalPaid = $totalPaid + $request->amount;
        if ($newTotalPaid >= $salesInvoice->total) {
            $salesInvoice->update(['paid' => true]);
        }

        return redirect()->back()->with('success', 'Payment added successfully.');
    }

    public function removePayment(SalesInvoice $salesInvoice, Payment $payment)
    {
        if ($payment->sales_invoice_id !== $salesInvoice->id) {
            return response()->json(['message' => 'Payment does not belong to this invoice'], 422);
        }

        $payment->delete();

        // Update invoice paid status
        $totalPaid = $salesInvoice->payments()->sum('amount');
        if ($totalPaid < $salesInvoice->total) {
            $salesInvoice->update(['paid' => false]);
        }

        return redirect()->back()->with('success', 'Payment removed successfully.');
    }
} 