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
            'ventes' => $ventes,
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
            'phone_number' => 'required|string|max:255',
            'owner_number' => 'required|string|max:255',
            'gps_coordinates' => 'required|string',
        ]);

        $commercial = $request->user()->commercial;
        
        $customer = $commercial->customers()->create([
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'owner_number' => $validated['owner_number'],
            'gps_coordinates' => $validated['gps_coordinates'],
        ]);

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
            'owner_number' => 'required|string|max:255',
            'gps_coordinates' => 'required|string',
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
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|exists:customers,id',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'paid' => 'required|boolean',
            'should_be_paid_at' => 'required|date',
        ]);

        $commercial = $request->user()->commercial;

        // Verify that the customer belongs to this salesperson
        $customer = Customer::findOrFail($validated['customer_id']);
        if ($customer->commercial_id !== $commercial->id) {
            return response()->json(['message' => 'Client non autorisé'], 403);
        }

        $vente = $commercial->ventes()->create([
            'product_id' => $validated['product_id'],
            'customer_id' => $validated['customer_id'],
            'quantity' => $validated['quantity'],
            'price' => $validated['price'],
            'paid' => $validated['paid'],
            'should_be_paid_at' => $validated['should_be_paid_at'],
        ]);

        return response()->json($vente->load(['customer:id,name', 'product:id,name']), 201);
    }
}
