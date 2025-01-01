<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commercial;
use App\Models\Customer;
use App\Models\Vente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalespersonController extends Controller
{
    /**
     * Get today's customer count for the authenticated salesperson
     */
    public function getTodayCustomersCount(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $count = $commercial->customers()
            ->whereDate('created_at', today())
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    /**
     * Get all ventes created by the authenticated salesperson
     */
    public function getVentes(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $query = $commercial->ventes()
            ->with(['customer:id,name,phone_number', 'product:id,name,price'])
            ->latest();

        // Filter by date if provided
        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('created_at', $date);
        }

        $ventes = $query->get();

        return response()->json([
            'ventes' => $ventes->map(function (Vente $vente) {
                return [
                    'id' => $vente->id,
                    'product' => $vente->product->name,
                    'customer' => $vente->customer->name,
                    'customer_phone_number' => $vente->customer->phone_number,
                    'quantity' => $vente->quantity,
                    'price' => $vente->price,
                    'total' => $vente->price * $vente->quantity,
                    'paid' => (bool)$vente->paid,
                    'paid_at' => $vente->paid_at?->format('Y-m-d H:i:s'),
                    'should_be_paid_at' => $vente->should_be_paid_at?->format('Y-m-d H:i:s'),
                    'created_at' => $vente->created_at->format('Y-m-d H:i:s'),
                ];
            }),
            'total' => $ventes->sum(function ($vente) {
                return $vente->price * $vente->quantity;
            }),
        ]);
    }

    /**
     * Get all customers created by the authenticated salesperson
     */
    public function getCustomers(Request $request)
    {
        $commercial = $request->user()->commercial;
        
        $query = $commercial->customers()->latest();

        // Get today's count if requested
        $todayCount = null;
        if ($request->has('include_today_count')) {
            $todayCount = $commercial->customers()
                ->whereDate('created_at', today())
                ->count();
        }

        $customers = $query->get();

        return response()->json([
            'customers' => $customers,
            'today_count' => $todayCount,
        ]);
    }

    /**
     * Create a new customer
     */
    public function createCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|numeric|digits:9|unique:customers,phone_number',
            'owner_number' => 'required|numeric|digits:9|string|max:9',
            'address' => 'nullable|string|max:255',
        ]);

        $commercial = $request->user()->commercial;
        
        $customer = $commercial->customers()->create($validated);

        return response()->json($customer, 201);
    }

    /**
     * Update an existing customer
     */
    public function updateCustomer(Request $request, Customer $customer)
    {
        // Check if the customer belongs to the authenticated salesperson
        if ($customer->commercial_id !== $request->user()->commercial->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:255',
            'owner_phone_number' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'address' => 'nullable|string|max:255',
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Create a new vente
     */
    public function createVente(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'paid' => 'required|boolean',
            'should_be_paid_at' => 'nullable|required_if:paid,false|date',
        ]);

        $commercial = $request->user()->commercial;

        // Verify that the customer belongs to this salesperson
        $customer = $commercial->customers()->findOrFail($validated['customer_id']);
        
        $vente = $commercial->ventes()->create([
            'product_id' => $validated['product_id'],
            'customer_id' => $validated['customer_id'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'paid' => $validated['paid'],
            'paid_at' => $validated['paid'] ? now() : null,
            'should_be_paid_at' => $validated['should_be_paid_at'] ?? null,
        ]);

        // Load the relationships
        $vente->load(['customer', 'product']);

        return response()->json([
            'vente' => [
                'id' => $vente->id,
                'product' => $vente->product->name,
                'customer' => $vente->customer->name,
                'customer_phone_number' => $vente->customer->phone_number,
                'quantity' => $vente->quantity,
                'price' => $vente->price,
                'total' => $vente->price * $vente->quantity,
                'paid' => (bool)$vente->paid,
                'paid_at' => $vente->paid_at?->format('Y-m-d H:i:s'),
                'should_be_paid_at' => $vente->should_be_paid_at?->format('Y-m-d H:i:s'),
                'created_at' => $vente->created_at->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }
}
