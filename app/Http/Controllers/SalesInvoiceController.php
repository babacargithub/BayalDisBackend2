<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

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
            ->where('paid', false)
            ->paginate(4000)
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
            // update stock of products
            foreach ($ventes as $vente) {
                $product = Product::findOrFail($vente['product_id']);
                $product->decrementStock($vente['quantity']);
            }
            // check if customer is prospect
            $customer = Customer::findOrFail($request->customer_id);    
            if ($customer->is_prospect) {
                // Update customer's prospect status
                $customer->is_prospect = false;
                $customer->save();
            }

            DB::commit();

            return redirect()->back()->with('success', 'Facture créée avec succès');
        } catch (Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la création de la facture. Veuillez réessayer.']);
        }
    }

    public function show(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load([
            'customer:id,name',
            'items:id,sales_invoice_id,product_id,quantity,price,profit',
            'items.product:id,name',
            'payments:id,sales_invoice_id,amount,created_at,comment'
        ]);

        // Check if this is an AJAX request
        if (request()->wantsJson() || request()->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json([
                'props' => [
                    'invoice' => $salesInvoice
                ]
            ]);
        }

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
            // update stock of products by calcaulating the difference between the old quantity and the new quantity

            DB::commit();
            return redirect()->back()->with('success', 'Invoice updated successfully.');
        } catch (Exception $e) {
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
        // put stock back            
            foreach ($salesInvoice->items as $item) {
                $product = Product::findOrFail($item->product_id);
                $carLoadItem = CarLoad::findCarLoadItemForProductAndCommercial($product, $salesInvoice->commercial);
                if ($carLoadItem !=null){
                    $carLoadItem->quantity_left += $item->quantity;
                    $carLoadItem->save();

                }
            }

            // Delete related items first
            $salesInvoice->items()->delete();

            // Then delete the invoice
            $salesInvoice->delete();

            DB::commit();
            return redirect()->route('sales-invoices.index')->with('success', 'Invoice deleted successfully.');
        } catch (Exception $e) {
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
            $vente = Vente::create([
                'sales_invoice_id' => $salesInvoice->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'price' => $request->price,
                'type' => 'INVOICE_ITEM',
                'paid' => $salesInvoice->paid,
                'should_be_paid_at' => $salesInvoice->should_be_paid_at,
            ]);
            $vente->refresh();
            $carLoadItem = CarLoad::findCarLoadItemForProductAndCommercial($vente->product, $salesInvoice->commercial);
            if ($carLoadItem !== null){
                $carLoadItem->quantity_left -= $vente->quantity;
                $carLoadItem->save();
            }

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
        } catch (Exception $e) {
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

            $commercial = $item->commercial ?? $salesInvoice->commercial;
            // put stock back
            if ($commercial != null){
                $carLoadItem = CarLoad::findCarLoadItemForProductAndCommercial($item->product, $commercial);

                    if ($carLoadItem) {
                        $carLoadItem->quantity_left += $item->quantity;
                        $carLoadItem->save();
                    }
            }
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            DB::rollBack();
            report($e);
            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour du paiement. Veuillez réessayer.']);
        }
    }

    public function updateProfit(Request $request, $salesInvoice,Vente $item)
    {

        if ($item->type !== 'INVOICE_ITEM') {
            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json(['error' => 'Cet article n\'appartient pas à cette facture'], 422);
            }
            return redirect()->back()->withErrors(['error' => 'Cet article n\'appartient pas à cette facture']);
        }


        try {
            // Check if this is a profit-only update

                $validated = $request->validate([
                    'profit' => 'required|integer',
                ]);

                 //Update only the profit field
                $item->profit = $validated['profit'];
                $item->save();


            return response()->json(['error' => '!Cet article a été traité !!'.$validated['profit']], 200);


            // Return JSON response for AJAX requests
            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Bénéfice mis à jour avec succès',
                    'item' => $item,
                ]);
            }

            return redirect()->back()->with([
                'success' => 'Article mis à jour avec succès',
                'invoice' => $item->salesInvoice
            ]);

        } catch (Exception $e) {
            report($e);

            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json(['error' => 'Échec de la mise à jour de l\'article. Veuillez réessayer.'], 500);
            }

            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour de l\'article. Veuillez réessayer.']);
        }
    }

    public function exportPdf(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load([
            'customer',
            'items.product',
        ]);

        // Total is calculated in the model or via relationships

//        return view('pdf.invoice', [
//            'invoice' => $salesInvoice
//        ]);
        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $salesInvoice
        ]);

        return $pdf->download('facture de '.$salesInvoice->customer->name.'-' . $salesInvoice->id . '.pdf');

    }

    public function exportUnpaidPdf()
    {
        $unpaidInvoices = SalesInvoice::with(['customer', 'payments', 'items'])
            ->select('sales_invoices.*')
            ->selectRaw('(SELECT SUM(quantity * price) FROM ventes WHERE sales_invoice_id = sales_invoices.id AND type = "INVOICE_ITEM") as total')
            ->selectRaw('COALESCE((SELECT SUM(amount) FROM payments WHERE sales_invoice_id = sales_invoices.id), 0) as total_paid')
            ->havingRaw('total_paid < total OR total_paid IS NULL')
            ->orderBy('created_at', 'desc')
            ->get();

        $pdf = Pdf::loadView('pdf.unpaid-invoices', [
            'invoices' => $unpaidInvoices,
            'date' => now()->format('d/m/Y H:i')
        ]);

        return $pdf->download('factures_impayees_'.now()->format('d_m_Y').'.pdf');
    }

    public function exportFilteredPdf(Request $request)
    {
        // Base query with optimized eager loading
        $query = SalesInvoice::query()
            ->with(['customer', 'payments', 'items'])
            ->select('sales_invoices.*')
            ->selectRaw('(SELECT SUM(quantity * price) FROM ventes WHERE sales_invoice_id = sales_invoices.id AND type = "INVOICE_ITEM") as total')
            ->selectRaw('COALESCE((SELECT SUM(amount) FROM payments WHERE sales_invoice_id = sales_invoices.id), 0) as total_paid');

        // Apply payment status filter
        if ($request->has('filter') && $request->filter !== 'all') {
            if ($request->filter === 'paid') {
                $query->havingRaw('total_paid >= total');
            } elseif ($request->filter === 'unpaid') {
                $query->havingRaw('total_paid < total OR total_paid IS NULL');
            }
        }

        // Apply search filter
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = '%' . strtolower($request->search) . '%';
            $query->whereHas('customer', function ($customerQuery) use ($searchTerm) {
                $customerQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]);
            });
        }

        // Apply week filter
        if ($request->has('weeks') && is_array($request->weeks) && count($request->weeks) > 0) {
            $query->where(function ($weekQuery) use ($request) {
                foreach ($request->weeks as $weekKey) {
                    $weekStart = \Carbon\Carbon::parse($weekKey);
                    $weekEnd = $weekStart->copy()->endOfWeek();
                    
                    $weekQuery->orWhereBetween('created_at', [
                        $weekStart->startOfDay(),
                        $weekEnd->endOfDay()
                    ]);
                }
            });
        }

        // Get filtered invoices
        $filteredInvoices = $query->orderBy('created_at', 'desc')->get();

        // Prepare filter description for PDF
        $filterDescription = $this->buildFilterDescription($request);
        $weekRangeTitle = $this->buildWeekRangeTitle($request);

        $pdf = Pdf::loadView('pdf.filtered-invoices', [
            'invoices' => $filteredInvoices,
            'date' => now()->format('d/m/Y H:i'),
            'filterDescription' => $filterDescription,
            'weekRangeTitle' => $weekRangeTitle,
            'totalAmount' => $filteredInvoices->sum('total'),
            'totalPaid' => $filteredInvoices->sum('total_paid'),
            'totalRemaining' => $filteredInvoices->sum('total') - $filteredInvoices->sum('total_paid')
        ]);

        $filename = 'factures_filtrees_' . now()->format('d_m_Y_H_i') . '.pdf';
        return $pdf->download($filename);
    }

    private function buildFilterDescription(Request $request)
    {
        $descriptions = [];

        // Payment status filter
        if ($request->has('filter') && $request->filter !== 'all') {
            if ($request->filter === 'paid') {
                $descriptions[] = 'Factures payées';
            } elseif ($request->filter === 'unpaid') {
                $descriptions[] = 'Factures non payées';
            }
        }

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $descriptions[] = 'Client: "' . $request->search . '"';
        }

        // Week filter
        if ($request->has('weeks') && is_array($request->weeks) && count($request->weeks) > 0) {
            $weekCount = count($request->weeks);
            $descriptions[] = $weekCount === 1 ? '1 semaine sélectionnée' : $weekCount . ' semaines sélectionnées';
        }

        return empty($descriptions) ? 'Toutes les factures' : implode(', ', $descriptions);
    }

    private function buildWeekRangeTitle(Request $request)
    {
        if (!$request->has('weeks') || !is_array($request->weeks) || count($request->weeks) === 0) {
            return null;
        }

        $weeks = $request->weeks;
        
        // If only one week is selected
        if (count($weeks) === 1) {
            $weekStart = \Carbon\Carbon::parse($weeks[0]);
            $weekEnd = $weekStart->copy()->endOfWeek();
            
            return $this->formatWeekRangeTitle($weekStart, $weekEnd);
        }

        // If multiple weeks are selected, check if they are consecutive
        sort($weeks);
        $firstWeek = \Carbon\Carbon::parse($weeks[0]);
        $lastWeek = \Carbon\Carbon::parse($weeks[count($weeks) - 1]);
        
        // Check if weeks are consecutive
        $areConsecutive = true;
        for ($i = 1; $i < count($weeks); $i++) {
            $currentWeek = \Carbon\Carbon::parse($weeks[$i]);
            $previousWeek = \Carbon\Carbon::parse($weeks[$i - 1]);
            
            if ($currentWeek->diffInWeeks($previousWeek) !== 1) {
                $areConsecutive = false;
                break;
            }
        }

        if ($areConsecutive) {
            // Show range from first to last week
            $firstWeekStart = $firstWeek->copy()->startOfWeek();
            $lastWeekEnd = $lastWeek->copy()->endOfWeek();
            
            return $this->formatWeekRangeTitle($firstWeekStart, $lastWeekEnd);
        } else {
            // Non-consecutive weeks
            return 'Semaines sélectionnées (' . count($weeks) . ' semaines)';
        }
    }

    private function formatWeekRangeTitle($startDate, $endDate)
    {
        $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'
        ];
        
        $startDay = $startDate->day;
        $startMonth = $months[$startDate->month];
        $startYear = $startDate->year;
        
        $endDay = $endDate->day;
        $endMonth = $months[$endDate->month];
        $endYear = $endDate->year;
        
        if ($startMonth === $endMonth && $startYear === $endYear) {
            return "Semaine du {$startDay} au {$endDay} {$startMonth} {$startYear}";
        } else if ($startYear === $endYear) {
            return "Semaine du {$startDay} {$startMonth} au {$endDay} {$endMonth} {$startYear}";
        } else {
            return "Semaine du {$startDay} {$startMonth} {$startYear} au {$endDay} {$endMonth} {$endYear}";
        }
    }
} 
