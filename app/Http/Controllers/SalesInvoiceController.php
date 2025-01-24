<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use App\Models\Vente;
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
        // Base query with optimized eager loading
        $query = SalesInvoice::query()
            ->select('id', 'customer_id', 'paid', 'should_be_paid_at', 'comment', 'created_at')
            ->with([
                'customer:id,name',
                'items' => function ($query) {
                    $query->select('id', 'sales_invoice_id', 'product_id', 'quantity', 'price')
                        ->where('type', 'INVOICE_ITEM');
                },
                'items.product:id,name',
                'payments' => function ($query) {
                    $query->select('id', 'sales_invoice_id', 'amount');
                }
            ]);

        // Get paginated results with optimized loading
        $invoices = $query->latest()
            ->paginate(10)
            ->through(function ($invoice) {
                // Add computed total to avoid N+1 queries
                $invoice->total = $invoice->items->sum('subtotal');
                return $invoice;
            });

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

        try {
            DB::beginTransaction();

            $invoice = SalesInvoice::create([
                'customer_id' => $request->customer_id,
                'comment' => $request->comment,
                'paid' => false,
                'should_be_paid_at' => $request->should_be_paid_at,
            ]);

            // Prepare all ventes data at once
            $ventes = array_map(function ($item) use ($invoice, $request) {
                return [
                    'sales_invoice_id' => $invoice->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'type' => 'INVOICE_ITEM',
                    'paid' => false,
                    'should_be_paid_at' => $request->should_be_paid_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $request->items);

            // Insert all ventes in a single query
            Vente::insert($ventes);

            DB::commit();
            return redirect()->back()->with('success', 'Invoice created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to create invoice. Please try again.');
        }
    }

    public function show(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load([
            'customer:id,name',
            'items:id,sales_invoice_id,product_id,quantity,price',
            'items.product:id,name',
            'payments:id,sales_invoice_id,amount,payment_date,comment'
        ]);
        
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

        try {
            DB::beginTransaction();
            $salesInvoice->update($request->only(['paid', 'should_be_paid_at', 'comment']));
            DB::commit();
            return redirect()->back()->with('success', 'Invoice updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to update invoice. Please try again.');
        }
    }

    public function destroy(SalesInvoice $salesInvoice)
    {
        try {
            DB::beginTransaction();
            $salesInvoice->items()->delete();
            $salesInvoice->delete();
            DB::commit();
            return redirect()->route('sales-invoices.index')->with('success', 'Invoice deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->route('sales-invoices.index')->with('error', 'Failed to delete invoice. Please try again.');
        }
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

        try {
            DB::beginTransaction();
            
            // Create the new item
            Vente::create([
                'sales_invoice_id' => $salesInvoice->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $request->price,
                'type' => 'INVOICE_ITEM',
                'paid' => $salesInvoice->paid,
                'should_be_paid_at' => $salesInvoice->should_be_paid_at,
            ]);

            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);
            
            DB::commit();
            
            return redirect()->back()->with([
                'success' => 'Item added successfully',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to add item');
        }
    }

    public function removeItem(SalesInvoice $salesInvoice, Vente $vente)
    {
        if ($salesInvoice->paid) {
            return response()->json(['message' => 'Cannot modify a paid invoice'], 422);
        }

        if ($vente->sales_invoice_id !== $salesInvoice->id || $vente->type !== 'INVOICE_ITEM') {
            return response()->json(['message' => 'Item does not belong to this invoice'], 422);
        }

        try {
            DB::beginTransaction();
            $vente->delete();
            
            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);
            
            DB::commit();
            
            return redirect()->back()->with([
                'success' => 'Item removed successfully',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to remove item');
        }
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

        try {
            DB::beginTransaction();

            // Get current total paid amount
            $totalPaid = $salesInvoice->payments()->sum('amount');
            $remaining = $salesInvoice->total - $totalPaid;

            if ($request->amount > $remaining) {
                return response()->json(['message' => 'Payment amount exceeds remaining balance'], 422);
            }

            // Create the payment
            $payment = $salesInvoice->payments()->create([
                'amount' => $request->amount,
                'payment_date' => $request->payment_date,
                'comment' => $request->comment,
            ]);

            // Check if invoice is fully paid
            $newTotalPaid = $totalPaid + $request->amount;
            if ($newTotalPaid >= $salesInvoice->total) {
                // Update invoice and related ventes
                $salesInvoice->update(['paid' => true]);
                $salesInvoice->items()->update([
                    'paid' => true,
                    'paid_at' => now(),
                ]);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Payment added successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to add payment. Please try again.');
        }
    }

    public function removePayment(SalesInvoice $salesInvoice, Payment $payment)
    {
        if ($payment->sales_invoice_id !== $salesInvoice->id) {
            return response()->json(['message' => 'Payment does not belong to this invoice'], 422);
        }

        try {
            DB::beginTransaction();
            
            $payment->delete();

            // Update invoice paid status
            $totalPaid = $salesInvoice->payments()->sum('amount');
            if ($totalPaid < $salesInvoice->total) {
                $salesInvoice->update(['paid' => false]);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Payment removed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->with('error', 'Failed to remove payment. Please try again.');
        }
    }
} 