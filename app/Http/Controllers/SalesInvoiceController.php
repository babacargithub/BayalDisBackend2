<?php

namespace App\Http\Controllers;

use App\Enums\SalesInvoiceStatus;
use App\Http\Requests\AddInvoiceItemRequest;
use App\Http\Requests\Api\PaySalesInvoiceRequest;
use App\Http\Requests\StoreSalesInvoiceRequest;
use App\Http\Requests\UpdateInvoiceItemProfitRequest;
use App\Http\Requests\UpdateSalesInvoiceRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Vente;
use App\Services\SalesInvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesInvoiceController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceService $salesInvoiceService
    ) {}

    public function index()
    {
        $invoices = SalesInvoice::query()
            ->with([
                'customer:id,name,phone_number,address',
                'commercial:id,name',
            ])
            ->where('status', '!=', SalesInvoiceStatus::FullyPaid->value)
            ->whereDate('created_at',">","2026-03-29")
            ->latest()
            ->paginate(5000);

        return Inertia::render('SalesInvoices/Index', [
            'invoices' => SalesInvoiceResource::collection($invoices),
            'customers' => [],
            'products' => [],
        ]);
    }

    public function store(StoreSalesInvoiceRequest $request)
    {
        try {
            $this->salesInvoiceService->createSalesInvoice([
                'paid' => false,
                ...$request->validated(),
            ]);

            return redirect()->back()->with('success', 'Facture créée avec succès');
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de la création de la facture : '.$e->getMessage()]);
        }
    }

    public function show(SalesInvoice $salesInvoice)
    {
        $salesInvoice->load([
            'customer:id,name,phone_number,address',
            'items:id,sales_invoice_id,product_id,quantity,price,profit',
            'items.product:id,name,price',
            'payments:id,sales_invoice_id,amount,commercial_commission,payment_method,comment,created_at',
        ]);

        if (request()->wantsJson() || request()->header('X-Requested-With') === 'XMLHttpRequest') {
            return response()->json(['invoice' => $this->formatInvoiceDetails($salesInvoice)]);
        }

        return Inertia::render('SalesInvoices/Index', ['invoice' => $salesInvoice]);
    }

    private function formatInvoiceDetails(SalesInvoice $salesInvoice): array
    {
        return [
            'id' => $salesInvoice->id,
            'customer' => [
                'id' => $salesInvoice->customer->id,
                'name' => $salesInvoice->customer->name,
                'phone_number' => $salesInvoice->customer->phone_number,
                'address' => $salesInvoice->customer->address,
            ],
            'paid' => $salesInvoice->paid,
            'total_amount' => $salesInvoice->total_amount,
            'total' => $salesInvoice->total_amount,
            'total_payments' => $salesInvoice->total_payments,
            'total_remaining' => $salesInvoice->total_amount - $salesInvoice->total_payments,
            'total_estimated_profit' => $salesInvoice->total_estimated_profit,
            'total_realized_profit' => $salesInvoice->total_realized_profit,
            'comment' => $salesInvoice->comment,
            'should_be_paid_at' => $salesInvoice->should_be_paid_at,
            'created_at' => $salesInvoice->created_at,
            'payments' => $salesInvoice->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'commercial_commission' => $payment->commercial_commission,
                'payment_method' => $payment->payment_method,
                'comment' => $payment->comment,
                'created_at' => $payment->created_at,
            ])->values()->all(),
            'items' => $salesInvoice->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'profit' => $item->profit,
                'subtotal' => $item->price * $item->quantity,
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'price' => $item->product->price,
                ],
            ])->values()->all(),
        ];
    }

    public function update(UpdateSalesInvoiceRequest $request, SalesInvoice $salesInvoice)
    {
        try {
            $this->salesInvoiceService->updateSalesInvoice($salesInvoice, $request->validated());

            return redirect()->back()->with('success', 'Facture mise à jour avec succès.');
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour de la facture. Veuillez réessayer.']);
        }
    }

    public function destroy(SalesInvoice $salesInvoice)
    {
        if ($salesInvoice->payments()->exists()) {
            return redirect()->back()->withErrors(['error' => 'Impossible de supprimer une facture avec des paiements.']);
        }

        try {
            $this->salesInvoiceService->deleteSalesInvoice($salesInvoice);

            return redirect()->route('sales-invoices.index')->with('success', 'Facture supprimée avec succès.');
        } catch (Exception $e) {
            report($e);

            return redirect()->route('sales-invoices.index')->withErrors(['error' => 'Échec de la suppression de la facture. Veuillez réessayer.']);
        }
    }

    public function addItem(AddInvoiceItemRequest $request, SalesInvoice $salesInvoice)
    {
        if ($salesInvoice->paid) {
            return redirect()->back()->withErrors(['error' => 'Impossible de modifier une facture déjà payée.']);
        }

        try {
            $this->salesInvoiceService->addItemToInvoice($salesInvoice, $request->validated());

            $salesInvoice->load(['items.product', 'customer', 'payments']);

            return redirect()->back()->with([
                'success' => 'Article ajouté avec succès',
                'invoice' => $salesInvoice,
            ]);
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de l\'ajout de l\'article. Veuillez réessayer.']);
        }
    }

    public function removeItem(SalesInvoice $salesInvoice, Vente $item)
    {
        if ($salesInvoice->paid) {
            return redirect()->back()->withErrors(['error' => 'Impossible de modifier une facture déjà payée.']);
        }

        if ($item->sales_invoice_id !== $salesInvoice->id || $item->type !== 'INVOICE_ITEM') {
            return redirect()->back()->withErrors(['error' => 'Cet article n\'appartient pas à cette facture.']);
        }

        try {
            $this->salesInvoiceService->removeItemFromInvoice($salesInvoice, $item);

            $salesInvoice->load(['items.product', 'customer', 'payments']);

            return redirect()->back()->with([
                'success' => 'Article supprimé avec succès',
                'invoice' => $salesInvoice,
            ]);
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de la suppression de l\'article. Veuillez réessayer.']);
        }
    }

    public function addPayment(PaySalesInvoiceRequest $request, SalesInvoice $salesInvoice)
    {
        if ($salesInvoice->paid) {
            return redirect()->back()->withErrors(['error' => 'La facture est déjà payée.']);
        }

        $remainingBalanceOnInvoice = $salesInvoice->total_amount - $salesInvoice->total_payments;
        if ($request->amount > $remainingBalanceOnInvoice) {
            return redirect()->back()->withErrors(['amount' => 'Le montant du paiement dépasse le solde restant.']);
        }

        try {
            $this->salesInvoiceService->paySalesInvoice($salesInvoice, $request->validated(), $request->user()->id);

            $salesInvoice->load(['items.product', 'customer', 'payments']);

            return redirect()->back()->with([
                'success' => 'Paiement ajouté avec succès',
                'invoice' => $salesInvoice,
            ]);
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de l\'ajout du paiement. Veuillez réessayer.']);
        }
    }

    public function removePayment(SalesInvoice $salesInvoice, Payment $payment)
    {
        if ($payment->sales_invoice_id !== $salesInvoice->id) {
            return redirect()->back()->withErrors(['error' => 'Ce paiement n\'appartient pas à cette facture.']);
        }

        try {
            $this->salesInvoiceService->removePaymentFromInvoice($payment);

            return redirect()->back()->with('success', 'Paiement supprimé avec succès.');
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de la suppression du paiement. Veuillez réessayer.']);
        }
    }

    public function updatePayment(PaySalesInvoiceRequest $request, SalesInvoice $salesInvoice, Payment $payment)
    {
        if ($payment->sales_invoice_id !== $salesInvoice->id) {
            return redirect()->back()->withErrors(['error' => 'Ce paiement n\'appartient pas à cette facture.']);
        }

        $totalPaidExcludingCurrentPayment = $salesInvoice->payments()
            ->where('id', '!=', $payment->id)
            ->sum('amount');

        if ($request->amount + $totalPaidExcludingCurrentPayment > $salesInvoice->total_amount) {
            return redirect()->back()->withErrors(['amount' => 'Le nouveau montant dépasserait le total de la facture.']);
        }

        try {
            $this->salesInvoiceService->updatePaymentOnInvoice($salesInvoice, $payment, $request->validated());

            $salesInvoice->load(['items.product', 'customer', 'payments']);

            return redirect()->back()->with([
                'success' => 'Paiement mis à jour avec succès',
                'invoice' => $salesInvoice,
            ]);
        } catch (Exception $e) {
            report($e);

            return redirect()->back()->withErrors(['error' => 'Échec de la mise à jour du paiement. Veuillez réessayer.']);
        }
    }

    public function updateProfit(UpdateInvoiceItemProfitRequest $request, SalesInvoice $salesInvoice, Vente $item)
    {
        if ($item->type !== 'INVOICE_ITEM') {
            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json(['error' => 'Cet article n\'appartient pas à cette facture.'], 422);
            }

            return redirect()->back()->withErrors(['error' => 'Cet article n\'appartient pas à cette facture.']);
        }

        try {
            $updatedItem = $this->salesInvoiceService->updateInvoiceItemProfit($item, $request->validated()['profit']);

            if ($request->wantsJson() || $request->header('X-Requested-With') === 'XMLHttpRequest') {
                return response()->json([
                    'success' => true,
                    'message' => 'Bénéfice mis à jour avec succès',
                    'item' => $updatedItem,
                ]);
            }

            return redirect()->back()->with([
                'success' => 'Article mis à jour avec succès',
                'invoice' => $item->salesInvoice,
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

        $pdf = Pdf::loadView('pdf.invoice', [
            'invoice' => $salesInvoice,
        ]);

        return $pdf->download('facture de '.$salesInvoice->customer->name.'-'.$salesInvoice->id.'.pdf');
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
            'date' => now()->format('d/m/Y H:i'),
        ]);

        return $pdf->download('factures_impayees_'.now()->format('d_m_Y').'.pdf');
    }

    public function exportFilteredPdf(Request $request)
    {
        $query = SalesInvoice::query()
            ->with(['customer', 'payments', 'items'])
            ->select('sales_invoices.*')
            ->selectRaw('(SELECT SUM(quantity * price) FROM ventes WHERE sales_invoice_id = sales_invoices.id AND type = "INVOICE_ITEM") as total')
            ->selectRaw('COALESCE((SELECT SUM(amount) FROM payments WHERE sales_invoice_id = sales_invoices.id), 0) as total_paid');

        if ($request->has('filter') && $request->filter !== 'all') {
            if ($request->filter === 'paid') {
                $query->havingRaw('total_paid >= total');
            } elseif ($request->filter === 'unpaid') {
                $query->havingRaw('total_paid < total OR total_paid IS NULL');
            }
        }

        if ($request->has('search') && ! empty($request->search)) {
            $searchTerm = '%'.strtolower($request->search).'%';
            $query->whereHas('customer', function ($customerQuery) use ($searchTerm) {
                $customerQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]);
            });
        }

        if ($request->has('weeks') && is_array($request->weeks) && count($request->weeks) > 0) {
            $query->where(function ($weekQuery) use ($request) {
                foreach ($request->weeks as $weekKey) {
                    $weekStart = \Carbon\Carbon::parse($weekKey);
                    $weekEnd = $weekStart->copy()->endOfWeek();

                    $weekQuery->orWhereBetween('created_at', [
                        $weekStart->startOfDay(),
                        $weekEnd->endOfDay(),
                    ]);
                }
            });
        }

        $filteredInvoices = $query->orderBy('created_at', 'desc')->get();

        $filterDescription = $this->buildFilterDescription($request);
        $weekRangeTitle = $this->buildWeekRangeTitle($request);

        $pdf = Pdf::loadView('pdf.filtered-invoices', [
            'invoices' => $filteredInvoices,
            'date' => now()->format('d/m/Y H:i'),
            'filterDescription' => $filterDescription,
            'weekRangeTitle' => $weekRangeTitle,
            'totalAmount' => $filteredInvoices->sum('total'),
            'totalPaid' => $filteredInvoices->sum('total_paid'),
            'totalRemaining' => $filteredInvoices->sum('total') - $filteredInvoices->sum('total_paid'),
        ]);

        return $pdf->download('factures_filtrees_'.now()->format('d_m_Y_H_i').'.pdf');
    }

    private function buildFilterDescription(Request $request): string
    {
        $descriptions = [];

        if ($request->has('filter') && $request->filter !== 'all') {
            if ($request->filter === 'paid') {
                $descriptions[] = 'Factures payées';
            } elseif ($request->filter === 'unpaid') {
                $descriptions[] = 'Factures non payées';
            }
        }

        if ($request->has('search') && ! empty($request->search)) {
            $descriptions[] = 'Client: "'.$request->search.'"';
        }

        if ($request->has('weeks') && is_array($request->weeks) && count($request->weeks) > 0) {
            $weekCount = count($request->weeks);
            $descriptions[] = $weekCount === 1 ? '1 semaine sélectionnée' : $weekCount.' semaines sélectionnées';
        }

        return empty($descriptions) ? 'Toutes les factures' : implode(', ', $descriptions);
    }

    private function buildWeekRangeTitle(Request $request): ?string
    {
        if (! $request->has('weeks') || ! is_array($request->weeks) || count($request->weeks) === 0) {
            return null;
        }

        $weeks = $request->weeks;

        if (count($weeks) === 1) {
            $weekStart = \Carbon\Carbon::parse($weeks[0]);
            $weekEnd = $weekStart->copy()->endOfWeek();

            return $this->formatWeekRangeTitle($weekStart, $weekEnd);
        }

        sort($weeks);
        $firstWeek = \Carbon\Carbon::parse($weeks[0]);
        $lastWeek = \Carbon\Carbon::parse($weeks[count($weeks) - 1]);

        $areWeeksConsecutive = true;
        for ($i = 1; $i < count($weeks); $i++) {
            $currentWeek = \Carbon\Carbon::parse($weeks[$i]);
            $previousWeek = \Carbon\Carbon::parse($weeks[$i - 1]);

            if ($currentWeek->diffInWeeks($previousWeek) !== 1) {
                $areWeeksConsecutive = false;
                break;
            }
        }

        if ($areWeeksConsecutive) {
            $firstWeekStart = $firstWeek->copy()->startOfWeek();
            $lastWeekEnd = $lastWeek->copy()->endOfWeek();

            return $this->formatWeekRangeTitle($firstWeekStart, $lastWeekEnd);
        }

        return 'Semaines sélectionnées ('.count($weeks).' semaines)';
    }

    private function formatWeekRangeTitle(\Carbon\Carbon $startDate, \Carbon\Carbon $endDate): string
    {
        $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        $startDay = $startDate->day;
        $startMonth = $months[$startDate->month];
        $startYear = $startDate->year;

        $endDay = $endDate->day;
        $endMonth = $months[$endDate->month];
        $endYear = $endDate->year;

        if ($startMonth === $endMonth && $startYear === $endYear) {
            return "Semaine du {$startDay} au {$endDay} {$startMonth} {$startYear}";
        } elseif ($startYear === $endYear) {
            return "Semaine du {$startDay} {$startMonth} au {$endDay} {$endMonth} {$startYear}";
        } else {
            return "Semaine du {$startDay} {$startMonth} {$startYear} au {$endDay} {$endMonth} {$endYear}";
        }
    }
}
