<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CustomerCategory;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use App\Models\Customer;

class CustomerCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = CustomerCategory::with('customers')->get();
        $customers = Customer::select('id', 'name', 'phone_number')->get();
        
        return Inertia::render('Clients/CustomerCategories', [
            'categories' => $categories,
            'customers' => $customers
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        CustomerCategory::create($validated);

        return redirect()->route('customer-categories.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(CustomerCategory $customerCategory)
    {
        return Inertia::render('CustomerCategories/Show', ['category' => $customerCategory]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CustomerCategory $customerCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $customerCategory->update($validated);

        return redirect()->route('customer-categories.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CustomerCategory $customerCategory): RedirectResponse
    {
        $customerCategory->delete();

        return redirect()->route('customer-categories.index');
    }

    public function addCustomers(Request $request, CustomerCategory $customerCategory)
    {
        $validated = $request->validate([
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'exists:customers,id'
        ]);


        // Add new customers to the category
        Customer::whereIn('id', $validated['customer_ids'])
            ->update(['customer_category_id' => $customerCategory->id]);

        return redirect()->back();
    }
}
