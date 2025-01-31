<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function index()
    {
        return Inertia::render('Produits/Index', [
            'products' => Product::with('stockEntries')
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'cost_price' => $product->cost_price,
                        'price' => $product->price,
                        'stock_available' => $product->stock_available,
                        'stock_value' => $product->stock_value
                    ];
                }),
            'total_stock_value' => Product::with('stockEntries')
                ->get()
                ->sum('stock_value')
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
        ]);

        Product::create($validated);

        return redirect()->back()->with('success', 'Produit ajouté avec succès');
    }

    public function update(Request $request, Product $produit)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
        ]);

        $produit->update($validated);

        return redirect()->back()->with('success', 'Produit mis à jour avec succès');
    }

    public function destroy(Product $produit)
    {
        $produit->delete();
        return redirect()->back()->with('success', 'Produit supprimé avec succès');
    }

    public function updateStock(Request $request, Product $product)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'purchase_invoice_item_id' => 'required|exists:purchase_invoice_items,id'
        ]);

        try {
            DB::beginTransaction();

            StockEntry::create([
                'product_id' => $product->id,
                'purchase_invoice_item_id' => $validated['purchase_invoice_item_id'],
                'quantity' => $validated['quantity'],
                'quantity_left' => $validated['quantity'],
                'unit_price' => $validated['unit_price']
            ]);

            DB::commit();

            return redirect()->back()->with('success', 'Stock mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Erreur lors de la mise à jour du stock');
        }
    }
} 