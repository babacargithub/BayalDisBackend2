<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index()
    {
        return Inertia::render('Produits/Index', [
            'produits' => Product::withCount('ventes')->latest()->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        Product::create($validated);

        return redirect()->back()->with('success', 'Produit ajouté avec succès');
    }

    public function update(Request $request, Product $produit)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
        ]);

        $produit->update($validated);

        return redirect()->back()->with('success', 'Produit mis à jour avec succès');
    }

    public function destroy(Product $produit)
    {
        $produit->delete();
        return redirect()->back()->with('success', 'Produit supprimé avec succès');
    }
} 