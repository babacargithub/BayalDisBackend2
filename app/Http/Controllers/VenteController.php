<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\Payment;
use App\Models\Vente;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Commercial;
use App\Models\SalesInvoice;
use Exception;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Services\PaymentService;

class VenteController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function index(Request $request)
    {
        // Base query for invoices with optimized eager loading
        $query = SalesInvoice::query()
            ->select(
                'sales_invoices.id',
                'sales_invoices.customer_id',
                'sales_invoices.commercial_id',
                'sales_invoices.paid',
                'sales_invoices.created_at',
                'sales_invoices.should_be_paid_at',
                'sales_invoices.comment'
            )
            ->whereHas('items', function ($q) {
                $q->where('type', 'INVOICE_ITEM');
            })
            ->with([
                'customer:id,name',
                'commercial:id,name',
                'items' => function ($query) {
                    $query->select('id', 'sales_invoice_id', 'product_id', 'quantity', 'price', 'profit')
                        ->where('type', 'INVOICE_ITEM');
                },
                'payments' => function ($query) {
                    $query->select('id', 'sales_invoice_id', 'amount', 'created_at');
                }
            ]);

        // Filter by payment status
        if ($request->filled('paid_status')) {
            switch ($request->paid_status) {
                case 'paid':
                    // Invoices where total payments >= total invoice amount
                    $query->whereRaw('
                        (SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.sales_invoice_id = sales_invoices.id) 
                        >= 
                        (SELECT COALESCE(SUM(ventes.quantity * ventes.price), 0) FROM ventes WHERE ventes.sales_invoice_id = sales_invoices.id AND ventes.type = "INVOICE_ITEM")
                    ');
                    break;
                case 'partial':
                    // Invoices where total payments > 0 but < total invoice amount
                    $query->whereRaw('
                        (SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.sales_invoice_id = sales_invoices.id) > 0
                        AND
                        (SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.sales_invoice_id = sales_invoices.id) 
                        < 
                        (SELECT COALESCE(SUM(ventes.quantity * ventes.price), 0) FROM ventes WHERE ventes.sales_invoice_id = sales_invoices.id AND ventes.type = "INVOICE_ITEM")
                    ');
                    break;
                case 'unpaid':
                    // Invoices where total payments = 0
                    $query->whereRaw('
                        (SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.sales_invoice_id = sales_invoices.id) = 0
                    ');
                    break;
            }
        }

        // Filter by date range
        if ($request->filled('date_debut')) {
            $query->whereDate('sales_invoices.created_at', '>=', $request->date_debut);
        } else {
            $query->whereDate("created_at", "=", today());
        }
        if ($request->filled('date_fin')) {
            $query->whereDate('sales_invoices.created_at', '<=', $request->date_fin);
        }

        // Filter by commercial
        if ($request->filled('commercial_id')) {
            $query->where('sales_invoices.commercial_id', $request->commercial_id);
        }

        // Calculate statistics using separate queries to avoid conflicts
        $baseStatsQuery = SalesInvoice::query()
            ->whereHas('items', function ($q) {
                $q->where('type', 'INVOICE_ITEM');
            });

        // Apply same filters to statistics
        if ($request->filled('date_debut')) {
            $baseStatsQuery->whereDate('created_at', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $baseStatsQuery->whereDate('created_at', '<=', $request->date_fin);
        }
        if ($request->filled('commercial_id')) {
            $baseStatsQuery->where('commercial_id', $request->commercial_id);
        }

        $totalInvoices = $baseStatsQuery->count();

        // Calculate amounts using database aggregation
        $amountStats = DB::table('sales_invoices')
            ->join('ventes', 'sales_invoices.id', '=', 'ventes.sales_invoice_id')
            ->where('ventes.type', 'INVOICE_ITEM')
            ->selectRaw('
                SUM(ventes.quantity * ventes.price) as total_amount,
                COUNT(DISTINCT sales_invoices.id) as invoice_count
            ')->first();

        // Calculate paid amounts
        $paidStats = DB::table('sales_invoices')
            ->join('payments', 'sales_invoices.id', '=', 'payments.sales_invoice_id')
            ->selectRaw('
                SUM(payments.amount) as total_paid,
                COUNT(DISTINCT sales_invoices.id) as paid_invoices_count
            ')->first();

        // Calculate unpaid amounts
        $unpaidAmount = ($amountStats->total_amount ?? 0) - ($paidStats->total_paid ?? 0);
        $unpaidCount = $totalInvoices - ($paidStats->paid_invoices_count ?? 0);

        $statistics = [
            'total_invoices' => $totalInvoices,
            'total_amount' => $amountStats->total_amount ?? 0,
            'paid_count' => $paidStats->paid_invoices_count ?? 0,
            'paid_amount' => $paidStats->total_paid ?? 0,
            'unpaid_count' => $unpaidCount,
            'unpaid_amount' => $unpaidAmount,
        ];

        // Get paginated results
        $invoices = $query->latest('sales_invoices.created_at')->paginate(25);

        // Add computed properties to each invoice
        foreach ($invoices as $invoice) {
            $invoice->total_amount = $invoice->items->sum('subtotal');
            $invoice->total_paid = $invoice->payments->sum('amount');
        }

        // Get today's payments
        $payments = $this->paymentService->getTodayPayments();
        $paymentStats = $this->paymentService->getPaymentStatistics();

        return Inertia::render('Ventes/Index', [
            'invoices' => $invoices,
            'clients' => Customer::select(['id', 'name'])->orderBy('name')->get(),
            'commerciaux' => Commercial::select(['id', 'name'])->orderBy('name')->get(),
            'filters' => $request->only(['date_debut', 'date_fin', 'paid_status', 'commercial_id']),
            'statistics' => $statistics,
            'payments' => [
                'data' => $payments,
                'statistics' => $paymentStats,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:customers,id',
            'commercial_id' => 'required|exists:commercials,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'paid' => 'required|boolean',
            'should_be_paid_at' => 'required_if:paid,false|nullable|date',
        ]);

        // If paid is true, set should_be_paid_at to null
        if ($validated['paid']) {
            $validated['should_be_paid_at'] = null;
        }

        // Add type for single vente
        $validated['type'] = 'SINGLE';

        DB::transaction(function () use ($validated) {
            // Create the vente
            Vente::create($validated);
            // check if the customer is a prospect
            /** @var Customer $customer */
            $customer = Customer::findOrFail($validated['customer_id']);
            if ($customer->is_prospect) {
                $customer->is_prospect = false;
                $customer->save();
            }

        });

        return redirect()->back()->with('success', 'Vente enregistrée avec succès');
    }

    public function update(Request $request, Vente $vente)
    {
        $validated = $request->validate([
            'paid' => 'boolean',
            'should_be_paid_at' => 'date',
        ]);

        $vente->update($validated);

        return redirect()->back()->with('success', 'Vente mise à jour avec succès');
    }

    public function destroy(Vente $vente)
    {
        try {
            return DB::transaction(function () use ($vente) {
                if ($vente->type !== 'SINGLE') {
                    return redirect()->back()->with('error', 'Cannot delete invoice items directly');
                }
                $commercial = $vente->commercial;
                // put stock back
                if ($commercial) {
                    $carload = CarLoad::where("returned", false)
                        ->where("team_id", $commercial->team_id)
                        ->where("return_date", ">", now()->toDateString())
                        ->first();
                    if ($carload) {
                        $carLoadItem = $carload->items()
                            ->where("product_id", $vente->product_id)
                            ->latest()
                            ->first();
                        if ($carLoadItem) {
                            $carLoadItem->quantity_left += $vente->quantity;
                            $carLoadItem->save();
                        }
                    }

                }

                $vente->delete();
                return redirect()->back()->with('success', 'Vente supprimée avec succès !');
            });
        } catch (Exception $e) {
            return redirect()->back()->with('error', 'Erreur lors de la suppression de la vente : ' . $e->getMessage());
        }
    }

    public function salesHistory(Request $request)
    {
        function filterAndGroup(EloquentBuilder $query, $dateStart, $dateEnd, bool $group = true): EloquentBuilder
        {
            $t = $query
                ->whereBetween(DB::raw('DATE(created_at)'), [$dateStart, $dateEnd]);
            if ($group) {
               return $t->groupBy(DB::raw('DATE(created_at)'));

            }
            return $t;
        }

        $dateStart = $request->get('dateStart');
        $dateEnd = $request->get('dateEnd');
        /** @var EloquentBuilder $profitsQuery */
        $profitsQuery = Payment::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(profit) as total') // adjust field names as needed
        );
        $profits_history = filterAndGroup($profitsQuery, $dateStart, $dateEnd)
            ->get()->map(function ($item) {
                return ['date' => $item->date, 'total_profit' => $item->total];
            })->toArray();
        /** @var EloquentBuilder $salesQuery */
       $salesQuery = Vente::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(price * quantity) as total') // adjust field names as needed
        );
       $salesQuery = filterAndGroup($salesQuery, $dateStart, $dateEnd);
             $sales_history = $salesQuery->get()->map(function ($item) use ($profits_history) {
                $data = ['date' => $item->date, 'total_sales' => (int)$item->total];
                if (array_key_exists($item->date, $profits_history)) {
                    $data['total_profits'] = $profits_history[$item->date];
                }
                $key = array_search($item->date, array_column($profits_history, 'date'));

                if ($key !== false) {
                    $item_profit = $profits_history[$key];
                    $data['total_profits'] = $item_profit['total_profit'];
                }
                return $data;

            });

        // averages

        $average_profits = Payment::select(DB::raw('SUM(profit) as total'))
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateStart, $dateEnd])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()->avg('total');

        $average_sales = Vente::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('SUM(price * quantity) as total'))
            ->whereBetween(DB::raw('DATE(created_at)'), [$dateStart, $dateEnd])
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get()
            ->avg('total');
        /** @var EloquentBuilder $totalProfitsQuery */
        $totalProfitsQuery = Payment::select(DB::raw('SUM(profit) as total'));
        $total_profits = filterAndGroup($totalProfitsQuery, $dateStart, $dateEnd, group: false)->first()->total;
        /** @var EloquentBuilder $salesTotalQuery */
        $salesTotalQuery = Vente::select(DB::raw('SUM(price * quantity) as total'));
        $total_sales = filterAndGroup($salesTotalQuery, $dateStart, $dateEnd, group: false)->first()->total;
        return Inertia::render('Ventes/SalesHistory', [
            'history' => [
                "items" => $sales_history,
                "totals"=> ["sales"=>(int)$total_sales, "profits"=>(int)$total_profits],

                "averages" => ["sales_average" => (int)$average_sales, "profits_average" => (int)$average_profits]],
        ]);


    }
} 
