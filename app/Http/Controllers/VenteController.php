<?php

namespace App\Http\Controllers;

use App\Models\CarLoad;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Vente;
use App\Services\DailySalesInvoicesService;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VenteController extends Controller
{
    public function __construct(
        private readonly DailySalesInvoicesService $dailySummaryService,
    ) {}

    public function index(Request $request): Response
    {
        $date = $request->filled('date') ? Carbon::parse($request->date) : today();
        $commercialId = $request->filled('commercial_id') ? (int) $request->commercial_id : null;
        $paidStatus = $request->filled('paid_status') ? $request->paid_status : null;

        $timelineItems = $this->dailySummaryService->getDailyTimeline($date, $commercialId, $paidStatus);
        $dailyTotals = $this->dailySummaryService->computeDailyTotals($timelineItems);

        /** @noinspection PhpUndefinedMethodInspection */
        return Inertia::render('Ventes/Index', [
            'timelineItems' => $timelineItems->map->toArray()->values(),
            'dailyTotals' => $dailyTotals->toArray(),
            'filters' => array_merge(
                $request->only(['date', 'paid_status', 'commercial_id']),
                ['date' => $date->toDateString()],
            ),
            'commerciaux' => Commercial::select(['id', 'name'])->orderBy('name')->get(),
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
                    $carload = CarLoad::where('returned', false)
                        ->where('team_id', $commercial->team_id)
                        ->where('return_date', '>', now()->toDateString())
                        ->first();
                    if ($carload) {
                        $carLoadItem = $carload->items()
                            ->where('product_id', $vente->product_id)
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
            return redirect()->back()->with('error', 'Erreur lors de la suppression de la vente : '.$e->getMessage());
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
            $data = ['date' => $item->date, 'total_sales' => (int) $item->total];
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
                'items' => $sales_history,
                'totals' => ['sales' => (int) $total_sales, 'profits' => (int) $total_profits],

                'averages' => ['sales_average' => (int) $average_sales, 'profits_average' => (int) $average_profits]],
        ]);

    }
}
