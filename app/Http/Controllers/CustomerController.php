<?php

namespace App\Http\Controllers;

use App\Models\Beat;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Sector;
use App\Services\CustomerService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class CustomerController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $query = Customer::query()
            ->with(['commercial:id,name', 'tags:id,name,color'])
            ->select('id', 'name', 'phone_number', 'owner_number', 'commercial_id', 'description', 'address', 'gps_coordinates', 'is_prospect', 'created_at');

        if ($request->filled('commercial_id')) {
            $query->where('commercial_id', $request->commercial_id);
        }

        if ($request->filled('prospect_status')) {
            if ($request->prospect_status === 'prospects') {
                $query->prospects();
            } elseif ($request->prospect_status === 'customers') {
                $query->nonProspects();
            }
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('customer_tags.id', $request->tag_id));
        }

        return Inertia::render('Clients/Index', [
            'clients' => $query->latest()
                ->paginate(20)
                ->through(fn ($customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'owner_number' => $customer->owner_number,
                    'description' => $customer->description,
                    'address' => $customer->address,
                    'gps_coordinates' => $customer->gps_coordinates,
                    'is_prospect' => $customer->is_prospect,
                    'created_at' => $customer->created_at,
                    'commercial' => $customer->commercial ? [
                        'id' => $customer->commercial->id,
                        'name' => $customer->commercial->name,
                    ] : null,
                    'tags' => $customer->tags->map(fn ($tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color,
                    ]),
                ]),
            'commerciaux' => Commercial::select('id', 'name')->get(),
            'allTags' => CustomerTag::select('id', 'name', 'color')->orderBy('name')->get(),
            'filters' => $request->only(['prospect_status', 'commercial_id', 'tag_id']),
            'can' => [
                'create' => Auth::user()->can('create', Customer::class),
                'edit' => Auth::user()->can('edit', Customer::class),
                'delete' => Auth::user()->can('delete', Customer::class),
            ],
        ]);
    }

    public function topCustomers(Request $request, CustomerService $customerService): \Inertia\Response
    {
        $sortBy = $request->get('sort', 'volume');

        return Inertia::render('Clients/TopCustomers', [
            'topCustomers' => $customerService->getTopCustomers($sortBy),
            'sort' => $sortBy,
        ]);
    }

    public function exportTopCustomersPdf(Request $request, CustomerService $customerService): \Illuminate\Http\Response
    {
        $sortBy = $request->get('sort', 'volume');
        $topCustomers = $customerService->getTopCustomers($sortBy);

        $sortLabel = $sortBy === 'frequency' ? 'fréquence des achats' : 'volume d\'achats';

        $pdf = Pdf::loadView('pdf.top-customers', [
            'topCustomers' => $topCustomers,
            'sortLabel' => $sortLabel,
            'date' => now()->format('d/m/Y à H:i'),
        ]);

        return $pdf->download('top-clients-'.$sortBy.'-'.now()->format('Y-m-d').'.pdf');
    }

    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $searchQuery = $request->get('q', '');

        if (strlen($searchQuery) < 2) {
            return response()->json([]);
        }

        $customers = Customer::query()
            ->with('commercial:id,name')
            ->select('id', 'name', 'phone_number', 'owner_number', 'address', 'gps_coordinates', 'is_prospect', 'commercial_id', 'description')
            ->where(function ($query) use ($searchQuery) {
                $query->where('name', 'like', "%{$searchQuery}%")
                    ->orWhere('phone_number', 'like', "%{$searchQuery}%");
            })
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
                'owner_number' => $customer->owner_number,
                'address' => $customer->address,
                'gps_coordinates' => $customer->gps_coordinates,
                'is_prospect' => $customer->is_prospect,
                'description' => $customer->description,
                'commercial' => $customer->commercial ? [
                    'id' => $customer->commercial->id,
                    'name' => $customer->commercial->name,
                ] : null,
            ]);

        return response()->json($customers);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'commercial_id' => 'nullable|exists:commercials,id',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:customer_tags,id',
        ]);

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        $customer = Customer::create($validated);
        $customer->tags()->sync($tagIds);

        return redirect()->back()->with('success', 'Client créé avec succès');
    }

    public function update(Request $request, $id): \Illuminate\Http\RedirectResponse
    {
        $customer = Customer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:customer_tags,id',
        ]);

        $tagIds = $validated['tag_ids'] ?? [];
        unset($validated['tag_ids']);

        $customer->update($validated);
        $customer->tags()->sync($tagIds);

        return redirect()->back()->with('success', 'Client mis à jour avec succès');
    }

    public function destroy(Customer $client)
    {
        try {
            $customer = $client;
            if ($customer->ventes()->exists()) {
                return back()->with('error', 'Impossible de supprimer ce client car il a des ventes associées');
            }

            $customer->delete();

            return back()->with('success', 'Client supprimé avec succès');
        } catch (\Exception $e) {
            return back()->with('error', 'Erreur lors de la suppression du client');
        }
    }

    public function history(Customer $client)
    {
        $orders = $client->orders()
            ->with(['items.product'])
            ->orderBy('created_at', 'desc')
            ->get();
        $ventes = $client->ventes()->with(['product'])->get();

        return Inertia::render('Clients/CustomerHistory', [
            'customer' => $client,
            'orders' => $orders,
            'ventes' => $ventes,
        ]);
    }

    public function map()
    {
        $clients = Customer::query()
            ->select('id', 'name', 'phone_number', 'address', 'gps_coordinates', 'is_prospect', 'description', 'sector_id')
            ->with('sector:id,name')
            ->whereNotNull('gps_coordinates')
            ->get();

        return Inertia::render('Clients/Map', [
            'clients' => $clients->map(function ($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone_number' => $client->phone_number,
                    'address' => $client->address,
                    'gps_coordinates' => $client->gps_coordinates,
                    'is_prospect' => $client->is_prospect,
                    'description' => $client->description,
                    'has_debt' => $client->has_debt,
                    'total_debt' => $client->total_debt,
                    'sector' => $client->sector ? [
                        'id' => $client->sector->id,
                        'name' => $client->sector->name,
                    ] : null,
                ];
            }),
        ]);
    }

    public function areaAnalysis(CustomerService $customerService): \Inertia\Response
    {
        return Inertia::render('Clients/AreaAnalysis', [
            'customers' => $customerService->getCustomersWithFinancialMetricsForAreaAnalysis(),
            'sectorMetrics' => $customerService->getSectorFinancialMetricsForAreaAnalysis(),
        ]);
    }

    public function customerActivity(Request $request, CustomerService $customerService): \Inertia\Response
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());
        $filterType = $request->get('filter_type', 'with_invoice');
        $sectorId = $request->get('sector_id');
        $minimumAmount = $request->get('minimum_amount');
        $minimumAverageAmount = $request->get('minimum_average_amount');
        $inactiveDays = $request->get('inactive_days');

        $customers = match ($filterType) {
            'by_sector' => $sectorId
                ? $customerService->getCustomersInSectorWithInvoicesInDateRange((int) $sectorId, $startDate, $endDate)
                : collect(),
            'above_amount' => $minimumAmount
                ? $customerService->getCustomersWithInvoicesAboveAmountInDateRange((int) $minimumAmount, $startDate, $endDate)
                : collect(),
            'above_average_amount' => $minimumAverageAmount
                ? $customerService->getCustomersWithAverageInvoiceAboveAmountInDateRange((int) $minimumAverageAmount, $startDate, $endDate)
                : collect(),
            'churning' => $inactiveDays
                ? $customerService->getChurningCustomersInDateRange((int) $inactiveDays, $startDate, $endDate)
                : collect(),
            default => $customerService->getCustomersWithInvoicesInDateRange($startDate, $endDate),
        };

        $beats = Beat::with('commercial:id,name')
            ->latest()
            ->get()
            ->map(fn (Beat $beat) => [
                'id' => $beat->id,
                'name' => $beat->name,
                'day_of_week_label' => $beat->day_of_week?->label(),
                'commercial_name' => $beat->commercial?->name,
            ]);

        return Inertia::render('Clients/Activity', [
            'customers' => $customers,
            'sectors' => Sector::query()->select('id', 'name')->orderBy('name')->get(),
            'beats' => $beats,
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'filter_type' => $filterType,
                'sector_id' => $sectorId ? (int) $sectorId : null,
                'minimum_amount' => $minimumAmount ? (int) $minimumAmount : null,
                'minimum_average_amount' => $minimumAverageAmount ? (int) $minimumAverageAmount : null,
                'inactive_days' => $inactiveDays ? (int) $inactiveDays : null,
            ],
        ]);
    }

    public function show(Customer $client)
    {
        $client->load(['commercial:id,name', 'ventes' => function ($query) {
            $query->select('id', 'customer_id', 'paid', 'created_at', 'product_id')
                ->with('product:id,name');
        }]);

        return Inertia::render('Clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone_number' => $client->phone_number,
                'owner_number' => $client->owner_number,
                'description' => $client->description,
                'address' => $client->address,
                'gps_coordinates' => $client->gps_coordinates,
                'is_prospect' => $client->is_prospect,
                'created_at' => $client->created_at,
                'commercial' => $client->commercial ? [
                    'id' => $client->commercial->id,
                    'name' => $client->commercial->name,
                ] : null,
                'ventes' => $client->ventes->map(fn ($vente) => [
                    'id' => $vente->id,
                    'paid' => $vente->paid,
                    'created_at' => $vente->created_at,
                    'product' => [
                        'id' => $vente->product->id,
                        'name' => $vente->product->name,
                    ],
                ]),
            ],
        ]);
    }
}
