<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Commercial;
use App\Models\Sector;
use App\Models\Ligne;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()
            ->with(['commercial:id,name', 'ventes' => function($query) {
                $query->select('id', 'customer_id', 'paid');
            }])
            ->select('id', 'name', 'phone_number', 'owner_number', 'commercial_id', 'description', 'address', 'gps_coordinates', 'is_prospect', 'created_at');

        // Filter by commercial_id if provided
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

        return Inertia::render('Clients/Index', [
            'clients' => $query->latest()
                ->paginate(25)
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
                    'has_unpaid_ventes' => $customer->ventes->contains('paid', false),
                    'ventes_count' => $customer->ventes->count(),
                ]),
            'commerciaux' => Commercial::select('id', 'name')->get(),
            'filters' => $request->only(['prospect_status', 'commercial_id']),
            'sectors' => Sector::with(['ligne', 'customers'])->get(),
            'lignes' => Ligne::all(),
            'can' => [
                'create' => Auth::user()->can('create', Customer::class),
                'edit' => Auth::user()->can('edit', Customer::class),
                'delete' => Auth::user()->can('delete', Customer::class),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'commercial_id' => 'nullable|exists:commercials,id',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
        ]);

        Customer::create($validated);

        return redirect()->back()->with('success', 'Client créé avec succès');
    }

    public function update(Request $request, $id)
    {
        $customer = Customer::findOrFail($id);

        // Debug incoming request data
        \Log::info('Update Client Request:', [
            'request_data' => $request->all(),
            'client_id' => $customer->id
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'nullable|string|max:255',
            'gps_coordinates' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:255',
        ]);

        $customer->update($validated);

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
            'ventes' => $ventes
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
                    "has_debt" => $client->has_debt,
                    "total_debt" => $client->total_debt,
                    'sector' => $client->sector ? [
                        'id' => $client->sector->id,
                        'name' => $client->sector->name,
                    ] : null,
                ];
            }),
            'googleMapsApiKey' => config('services.google.maps_api_key')
        ]);
    }

    public function show(Customer $client)
    {
        $client->load(['commercial:id,name', 'ventes' => function($query) {
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
                    ]
                ])
            ]
        ]);
    }
} 