<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = ProductCategory::query()
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'commission_rate' => $category->commission_rate !== null ? (float) $category->commission_rate : null,
                'products_count' => $category->products_count,
            ]);

        return Inertia::render('ProductCategories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:product_categories,name',
            'description' => 'nullable|string',
            'commission_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        ProductCategory::create($validated);

        return redirect()->back()->with('success', 'Catégorie créée avec succès.');
    }

    public function update(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:product_categories,name,'.$productCategory->id,
            'description' => 'nullable|string',
            'commission_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        $productCategory->update($validated);

        return redirect()->back()->with('success', 'Catégorie mise à jour avec succès.');
    }

    public function destroy(ProductCategory $productCategory): RedirectResponse
    {
        if ($productCategory->products()->exists()) {
            return redirect()->back()
                ->withErrors(['error' => 'Impossible de supprimer cette catégorie car elle contient des produits.']);
        }

        $productCategory->delete();

        return redirect()->back()->with('success', 'Catégorie supprimée.');
    }
}
