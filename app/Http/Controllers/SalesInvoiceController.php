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
                    $query->select('id', 'sales_invoice_id', 'amount', 'created_at', 'comment','payment_method');
                }
            ]);

        // Get paginated results with optimized loading
        $invoices = $query->latest()
            ->orderByDesc("created_at")
            ->paginate(1000)
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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'should_be_paid_at' => 'required|date',
            'comment' => 'nullable|string',
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
            // check if customer is prospect
            $customer = Customer::findOrFail($request->customer_id);    
            if ($customer->is_prospect) {
                // Update customer's prospect status
                $customer->is_prospect = false;
                $customer->save();
            }

            DB::commit();

            return redirect()->back()->with('success', 'Facture créée avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la création de la facture. Veuillez réessayer.']);
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
        // Check if invoice has payments
        if ($salesInvoice->payments()->exists()) {
            return redirect()->back()->with('error', 'Cannot delete invoice with payments.');
        }

        try {
            DB::beginTransaction();
            
            // Delete related items first
            $salesInvoice->items()->delete();
            
            // Then delete the invoice
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

    public function removeItem(SalesInvoice $salesInvoice, Vente $item)
    {
        if ($salesInvoice->paid) {
            return redirect()->back()->withErrors(['error' => 'Impossible de modifier une facture déjà payée']);
        }

        if ($item->sales_invoice_id !== $salesInvoice->id || $item->type !== 'INVOICE_ITEM') {
            return redirect()->back()->withErrors(['error' => 'Cet article n\'appartient pas à cette facture']);
        }

        try {
            DB::beginTransaction();
            $item->delete();
            
            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);
            
            DB::commit();
            
            return redirect()->back()->with([
                'success' => 'Article supprimé avec succès',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la suppression de l\'article. Veuillez réessayer.']);
        }
    }

    public function addPayment(Request $request, SalesInvoice $salesInvoice)
    {
        try {
            $validated = $request->validate([
                'amount' => 'required|integer|min:1',
                'payment_method' => 'required|string|in:Cash,Wave,Om',
                'comment' => 'nullable|string',
            ]);

            if ($salesInvoice->paid) {
                return redirect()->back()->withErrors(['error' => 'La facture est déjà payée.']);
            }

            DB::beginTransaction();

            // Load items to ensure total is calculated correctly
            $salesInvoice->load('items');
            
            // Get current total paid amount
            $totalPaid = $salesInvoice->payments()->sum('amount');
            $remaining = $salesInvoice->total - $totalPaid;

            if ($request->amount > $remaining) {
                return redirect()->back()->withErrors(['amount' => 'Le montant du paiement dépasse le solde restant.']);
            }

            // Create the payment
            $payment = $salesInvoice->payments()->create([
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
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

            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);

            DB::commit();
            return redirect()->back()->with([
                'success' => 'Paiement ajouté avec succès',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de l\'ajout du paiement. Veuillez réessayer.']);
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

    public function updatePayment(Request $request, SalesInvoice $salesInvoice, Payment $payment)
    {
        if ($payment->sales_invoice_id !== $salesInvoice->id) {
            return redirect()->back()->withErrors(['error' => 'Ce paiement n\'appartient pas à cette facture']);
        }

        try {
            $validated = $request->validate([
                'amount' => 'required|integer|min:1',
                'payment_method' => 'required|string|in:Cash,Wave,Om',
                'comment' => 'nullable|string',
            ]);

            DB::beginTransaction();

            // Load items to ensure total is calculated correctly
            $salesInvoice->load('items');
            
            // Calculate new total paid amount excluding current payment
            $totalPaidExcludingCurrent = $salesInvoice->payments()
                ->where('id', '!=', $payment->id)
                ->sum('amount');
            
            // Check if new amount would exceed invoice total
            if ($request->amount + $totalPaidExcludingCurrent > $salesInvoice->total) {
                return redirect()->back()->withErrors(['amount' => 'Le nouveau montant dépasserait le total de la facture']);
            }

            // Update the payment
            $payment->update([
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'comment' => $request->comment,
            ]);

            // Recalculate total paid and update invoice status
            $newTotalPaid = $totalPaidExcludingCurrent + $request->amount;
            $salesInvoice->update([
                'paid' => $newTotalPaid >= $salesInvoice->total
            ]);

            // Update related ventes paid status
            $salesInvoice->items()->update([
                'paid' => $newTotalPaid >= $salesInvoice->total,
                'paid_at' => $newTotalPaid >= $salesInvoice->total ? now() : null,
            ]);

            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);

            DB::commit();
            return redirect()->back()->with([
                'success' => 'Paiement mis à jour avec succès',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour du paiement. Veuillez réessayer.']);
        }
    }

    public function updateItem(Request $request, SalesInvoice $salesInvoice, Vente $item)
    {
        if ($salesInvoice->paid) {
            return redirect()->back()->withErrors(['error' => 'Impossible de modifier une facture déjà payée']);
        }

        if ($item->sales_invoice_id !== $salesInvoice->id || $item->type !== 'INVOICE_ITEM') {
            return redirect()->back()->withErrors(['error' => 'Cet article n\'appartient pas à cette facture']);
        }

        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'price' => 'required|integer|min:0',
            ]);

            DB::beginTransaction();
            
            // Update the item
            $item->update([
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $request->price,
            ]);

            // Reload the invoice with its relationships
            $salesInvoice->load([
                'items.product',
                'customer',
                'payments'
            ]);
            
            DB::commit();
            
            return redirect()->back()->with([
                'success' => 'Article mis à jour avec succès',
                'invoice' => $salesInvoice
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour de l\'article. Veuillez réessayer.']);
        }
    }

    public function exportPdf(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load([
            'customer',
            'items.product',
        ]);

        // Calculate total if not already done
        $salesInvoice->total = $salesInvoice->items->sum('subtotal');

//        return view('pdf.invoice', [
//            'invoice' => $salesInvoice
//        ]);
        $pdf = \PDF::loadView('pdf.invoice', [
            'invoice' => $salesInvoice
        ]);

        return $pdf->download('facture de '.$salesInvoice->customer->name.'-' . $salesInvoice->id . '.pdf');

    }
} 