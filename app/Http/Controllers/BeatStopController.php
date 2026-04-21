<?php

namespace App\Http\Controllers;

use App\Data\Vente\VenteStatsFilter;
use App\Enums\DayOfWeek;
use App\Models\Beat;
use App\Models\BeatStop;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Services\BeatService;
use App\Services\SalesInvoiceStatsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BeatStopController extends Controller
{
    public function __construct(
        private readonly SalesInvoiceStatsService $salesInvoiceStatsService,
        private readonly BeatService $beatService,
    ) {}

    public function index(): Response
    {
        $beats = Beat::with([
            'commercial:id,name',
            'sector:id,name',
        ])
            ->withCount(['stops as template_stops_count' => function ($query) {
                $query->whereNull('visit_date');
            }])
            ->latest()
            ->get()
            ->map(function (Beat $beat) {
                $forecast = $this->beatService->computeForecastedSalesForBeat($beat);

                return [
                    'id' => $beat->id,
                    'name' => $beat->sector?->name.' '.$beat->name,
                    'day_of_week' => $beat->day_of_week?->value,
                    'day_of_week_label' => $beat->day_of_week?->label(),
                    'commercial' => [
                        'id' => $beat->commercial->id,
                        'name' => $beat->commercial->name,
                    ],
                    'template_stops_count' => $beat->template_stops_count,
                    'created_at' => $beat->created_at,
                    'forecasted_total_sales' => $forecast->forecastedTotalSales,
                    'forecasted_total_profit' => $forecast->forecastedTotalProfit,
                    'forecast_data_points_count' => $forecast->dataPointsCount,
                ];
            });

        $customers = Customer::select('id', 'name', 'phone_number', 'address', 'created_at')
            ->with(['beatStops' => function ($query) {
                $query->select('id', 'customer_id', 'visited_at', 'status')
                    ->whereIn('status', [BeatStop::STATUS_COMPLETED, BeatStop::STATUS_CANCELLED])
                    ->latest('visited_at');
            }])
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'address' => $customer->address,
                    'last_visit' => $customer->last_visit,
                ];
            });

        return Inertia::render('Beats/Index', [
            'beats' => $beats,
            'customers' => $customers,
            'days_of_week' => collect(DayOfWeek::cases())->map(fn ($day) => [
                'value' => $day->value,
                'label' => $day->label(),
            ])->values(),
        ]);
    }

    public function create(): Response
    {
        $customers = Customer::select('id', 'name', 'phone_number', 'address')->get();
        $commercials = Commercial::select('id', 'name')->get();

        return Inertia::render('Beats/Create', [
            'customers' => $customers,
            'commercials' => $commercials,
            'days_of_week' => collect(DayOfWeek::cases())->map(fn ($day) => [
                'value' => $day->value,
                'label' => $day->label(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'string', 'in:'.implode(',', array_column(DayOfWeek::cases(), 'value'))],
            'commercial_id' => ['nullable', 'exists:commercials,id'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.customer_id' => ['required', 'exists:customers,id'],
            'stops.*.notes' => ['nullable', 'string'],
        ], [
            'name.required' => 'Le nom est obligatoire',
            'name.max' => 'Le nom ne doit pas dépasser 255 caractères',
            'day_of_week.required' => 'Le jour de la semaine est obligatoire',
            'day_of_week.in' => 'Le jour sélectionné n\'est pas valide',
            'commercial_id.exists' => 'Le commercial sélectionné n\'existe pas',
            'stops.required' => 'Au moins un arrêt est requis',
            'stops.*.customer_id.required' => 'Le client est obligatoire',
            'stops.*.customer_id.exists' => 'Le client sélectionné n\'existe pas',
        ]);

        $beat = Beat::create([
            'name' => $validated['name'],
            'day_of_week' => $validated['day_of_week'],
            'commercial_id' => $validated['commercial_id'] ?? null,
        ]);

        // Create template stops (visit_date = null — define the recurring customer list)
        foreach ($validated['stops'] as $stopData) {
            $beat->templateStops()->create([
                'customer_id' => $stopData['customer_id'],
                'notes' => $stopData['notes'] ?? null,
                'status' => BeatStop::STATUS_PLANNED,
            ]);
        }

        return redirect()->route('beats.show', $beat)
            ->with('success', 'Beat créé avec succès');
    }

    public function show(Beat $beat): Response
    {
        $beat->load(['templateStops.customer', 'commercial:id,name', 'sector:id,name']);

        return Inertia::render('Beats/Show', [
            'batch' => [
                'id' => $beat->id,
                'name' => $beat->name,
                'day_of_week' => $beat->day_of_week?->value,
                'day_of_week_label' => $beat->day_of_week?->label(),
                'commercial' => $beat->commercial,
                'sector' => $beat->sector,
                'visits' => $beat->templateStops->map(function (BeatStop $stop) {
                    return [
                        'id' => $stop->id,
                        'customer' => [
                            'id' => $stop->customer->id,
                            'name' => $stop->customer->name,
                            'phone_number' => $stop->customer->phone_number,
                            'address' => $stop->customer->address,
                        ],
                        'notes' => $stop->notes,
                        'status' => $stop->status,
                    ];
                }),
            ],
        ]);
    }

    public function edit(Beat $beat): Response
    {
        $beat->load('templateStops.customer');
        $customers = Customer::select('id', 'name', 'phone_number', 'address')->get();

        return Inertia::render('Beats/Edit', [
            'batch' => [
                'id' => $beat->id,
                'name' => $beat->name,
                'day_of_week' => $beat->day_of_week?->value,
                'visits' => $beat->templateStops->map(function (BeatStop $stop) {
                    return [
                        'id' => $stop->id,
                        'customer_id' => $stop->customer_id,
                        'notes' => $stop->notes,
                    ];
                }),
            ],
            'customers' => $customers,
            'days_of_week' => collect(DayOfWeek::cases())->map(fn ($day) => [
                'value' => $day->value,
                'label' => $day->label(),
            ])->values(),
        ]);
    }

    public function update(Request $request, Beat $beat)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'day_of_week' => ['required', 'string', 'in:'.implode(',', array_column(DayOfWeek::cases(), 'value'))],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.customer_id' => ['required', 'exists:customers,id'],
            'stops.*.notes' => ['nullable', 'string'],
        ]);

        $beat->update([
            'name' => $validated['name'],
            'day_of_week' => $validated['day_of_week'],
        ]);

        // Replace template stops entirely
        $beat->templateStops()->delete();

        foreach ($validated['stops'] as $stopData) {
            $beat->templateStops()->create([
                'customer_id' => $stopData['customer_id'],
                'notes' => $stopData['notes'] ?? null,
                'status' => BeatStop::STATUS_PLANNED,
            ]);
        }

        return redirect()->route('beats.show', $beat)
            ->with('success', 'Beat mis à jour avec succès');
    }

    public function destroy(Beat $beat)
    {
        $beat->delete();

        return redirect()->route('beats.index')
            ->with('success', 'Beat supprimé avec succès');
    }

    public function exportPdf(Beat $beat): \Illuminate\Http\Response
    {
        $beat->load(['commercial:id,name', 'sector:id,name']);

        $customersWithDebtData = $beat->templateStops()
            ->with(['customer' => function ($query) {
                $query->select('id', 'name', 'address')
                    ->withCount(['salesInvoices as unpaid_invoices_count' => function ($query) {
                        $query->where('total_amount', '>', 0)
                            ->whereColumn('total_payments', '<', 'total_amount');
                    }])
                    ->withSum(['salesInvoices as total_debt' => function ($query) {
                        $query->where('total_amount', '>', 0)
                            ->whereColumn('total_payments', '<', 'total_amount');
                    }], 'total_amount')
                    ->withSum(['salesInvoices as total_paid' => function ($query) {
                        $query->where('total_amount', '>', 0)
                            ->whereColumn('total_payments', '<', 'total_amount');
                    }], 'total_payments');
            }])
            ->get()
            ->map(function (BeatStop $stop) {
                $customer = $stop->customer;
                $totalDebt = (int) (($customer->total_debt ?? 0) - ($customer->total_paid ?? 0));

                return [
                    'name' => $customer->name,
                    'address' => $customer->address,
                    'total_debt' => $totalDebt,
                    'unpaid_invoices_count' => (int) ($customer->unpaid_invoices_count ?? 0),
                ];
            })
            ->sortBy('name')
            ->values();

        $pdf = Pdf::loadView('pdf.beat-customers', [
            'beat' => $beat,
            'customers' => $customersWithDebtData,
            'generated_at' => now()->format('d/m/Y H:i'),
        ]);

        $filename = 'beat_'.str_replace(' ', '_', strtolower($beat->name)).'_'.now()->format('d_m_Y').'.pdf';

        return $pdf->download($filename);
    }

    public function getHistory(Beat $beat, Request $request): JsonResponse
    {
        $customerIds = $beat->templateStops()->pluck('customer_id')->toArray();
        $includeAllDaySales = $request->boolean('include_all_day_sales', false);

        if (empty($customerIds) && ! $includeAllDaySales) {
            return response()->json([
                'beat' => [
                    'id' => $beat->id,
                    'name' => $beat->name,
                    'day_of_week_label' => $beat->day_of_week?->label(),
                ],
                'history' => [],
            ]);
        }

        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        // Discover the actual dates within the range that had sales, restricted to
        // the beat's scheduled day of week. Dates with no sales are excluded.
        $invoicesQuery = $includeAllDaySales
            ? SalesInvoice::query()
            : SalesInvoice::whereIn('customer_id', $customerIds);

        $salesDates = $invoicesQuery
            ->selectRaw('DATE(created_at) as sale_date')
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->groupBy('sale_date')
            ->orderByDesc('sale_date')
            ->pluck('sale_date')
            ->map(fn (string $date) => Carbon::parse($date))
            ->filter(fn (Carbon $date) => DayOfWeek::fromCarbon($date) === $beat->day_of_week)
            ->values();

        $salesFilter = $includeAllDaySales
            ? VenteStatsFilter::regardlessOfPaymentStatus()
            : VenteStatsFilter::regardlessOfPaymentStatus()->forCustomers($customerIds);

        $history = $salesDates->map(function (Carbon $date) use ($salesFilter): array {
            $startOfDay = $date->copy()->startOfDay();
            $endOfDay = $date->copy()->endOfDay();

            return [
                'date' => $date->toDateString(),
                'label' => ucfirst($date->locale('fr')->isoFormat('dddd D MMMM YYYY')),
                'total_sales' => $this->salesInvoiceStatsService->totalSales($startOfDay, $endOfDay, $salesFilter),
                'total_estimated_profit' => $this->salesInvoiceStatsService->totalEstimatedProfits($startOfDay, $endOfDay, $salesFilter),
                'total_realized_profit' => $this->salesInvoiceStatsService->totalRealizedProfits($startOfDay, $endOfDay, $salesFilter),
                'invoices_count' => $this->salesInvoiceStatsService->salesInvoicesCount($startOfDay, $endOfDay, $salesFilter),
                'total_commissions' => $this->salesInvoiceStatsService->totalCommercialCommissions($startOfDay, $endOfDay, $salesFilter),
                'total_delivery_cost' => $this->salesInvoiceStatsService->totalDeliveryCost($startOfDay, $endOfDay, null, $salesFilter),
            ];
        });

        return response()->json([
            'beat' => [
                'id' => $beat->id,
                'name' => $beat->name,
                'day_of_week_label' => $beat->day_of_week?->label(),
            ],
            'history' => $history,
        ]);
    }

    public function getLeftOutCustomersForDate(Beat $beat, Request $request): JsonResponse
    {
        $request->validate(['date' => ['required', 'date']]);

        $date = Carbon::parse($request->input('date'));
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $customerIds = $beat->templateStops()->pluck('customer_id')->toArray();

        $customerIdsWhoOrderedThatDay = SalesInvoice::whereIn('customer_id', $customerIds)
            ->whereDate('created_at', '>=', $startOfDay)
            ->whereDate('created_at', '<=', $endOfDay)
            ->pluck('customer_id')
            ->unique()
            ->toArray();

        $leftOutCustomerIds = array_values(array_diff($customerIds, $customerIdsWhoOrderedThatDay));

        $leftOutCustomers = Customer::whereIn('id', $leftOutCustomerIds)
            ->orderBy('name')
            ->get(['id', 'name', 'phone_number','address']);

        return response()->json([
            'date' => $date->toDateString(),
            'label' => ucfirst($date->locale('fr')->isoFormat('dddd D MMMM YYYY')),
            'total_customers' => count($customerIds),
            'left_out_customers' => $leftOutCustomers,
        ]);
    }

    public function exportLeftOutCustomersPdf(Beat $beat, Request $request): \Illuminate\Http\Response
    {
        $request->validate(['date' => ['required', 'date']]);

        $beat->load(['commercial:id,name', 'sector:id,name']);

        $date = Carbon::parse($request->input('date'));
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $customerIds = $beat->templateStops()->pluck('customer_id')->toArray();

        $customerIdsWhoOrderedThatDay = SalesInvoice::whereIn('customer_id', $customerIds)
            ->whereDate('created_at', '>=', $startOfDay)
            ->whereDate('created_at', '<=', $endOfDay)
            ->pluck('customer_id')
            ->unique()
            ->toArray();

        $leftOutCustomerIds = array_values(array_diff($customerIds, $customerIdsWhoOrderedThatDay));

        $leftOutCustomers = Customer::whereIn('id', $leftOutCustomerIds)
            ->orderBy('name')
            ->get(['id', 'name', 'phone_number','address']);

        $pdf = Pdf::loadView('pdf.beat-left-out-customers', [
            'beat' => $beat,
            'date' => $date,
            'date_label' => ucfirst($date->locale('fr')->isoFormat('dddd D MMMM YYYY')),
            'total_customers' => count($customerIds),
            'left_out_customers' => $leftOutCustomers,
            'generated_at' => now()->format('d/m/Y H:i'),
        ]);

        $filename = 'clients_sans_achat_'
            .str_replace(' ', '_', strtolower($beat->name))
            .'_'.$date->format('d_m_Y')
            .'.pdf';

        return $pdf->download($filename);
    }

    public function addCustomers(Request $request, Beat $beat)
    {
        $request->validate([
            'customer_ids' => ['required', 'array'],
            'customer_ids.*' => ['required', 'exists:customers,id'],
        ]);

        $existingCustomerIds = $beat->templateStops()->pluck('customer_id')->toArray();
        $newCustomerIds = array_diff($request->customer_ids, $existingCustomerIds);

        foreach ($newCustomerIds as $customerId) {
            $beat->templateStops()->create([
                'customer_id' => $customerId,
                'status' => BeatStop::STATUS_PLANNED,
            ]);
        }

        return back()->with('success', count($newCustomerIds).' client(s) ajouté(s) avec succès');
    }
}
