<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerTag;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerTagController extends Controller
{
    public function index(): \Inertia\Response
    {
        $customerTags = CustomerTag::query()
            ->withCount('customers')
            ->orderBy('name')
            ->get();

        return Inertia::render('Clients/CustomerTags', [
            'customerTags' => $customerTags,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:customer_tags,name',
            'color' => 'required|string|max:20',
        ]);

        CustomerTag::create($validated);

        return redirect()->back()->with('success', 'Étiquette créée avec succès');
    }

    public function update(Request $request, CustomerTag $customerTag): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:customer_tags,name,'.$customerTag->id,
            'color' => 'required|string|max:20',
        ]);

        $customerTag->update($validated);

        return redirect()->back()->with('success', 'Étiquette mise à jour avec succès');
    }

    public function destroy(CustomerTag $customerTag): \Illuminate\Http\RedirectResponse
    {
        $customerTag->delete();

        return redirect()->back()->with('success', 'Étiquette supprimée avec succès');
    }

    public function syncCustomerTags(Request $request, Customer $customer): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'exists:customer_tags,id',
        ]);

        $customer->tags()->sync($validated['tag_ids'] ?? []);

        return redirect()->back()->with('success', 'Étiquettes mises à jour avec succès');
    }
}
