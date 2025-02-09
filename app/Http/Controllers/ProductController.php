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
            'products' => Product::with(['stockEntries' => function($query) {
                    $query->orderBy('created_at', 'desc');
                }])
                ->get()
                ->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                        'cost_price' => $product->cost_price,
                        'price' => $product->price,
                        'stock_available' => $product->stock_available,
                        'stock_value' => $product->stock_value,
                        'parent_id' => $product->parent_id,
                        'base_quantity' => $product->base_quantity,
                        "total_sold" => $product->total_sold,
                        'is_base_product' => $product->is_base_product,
                        'stock_entries' => $product->stockEntries->map(function ($entry) {
                            return [
                                'id' => $entry->id,
                                'quantity' => $entry->quantity,
                                'quantity_left' => $entry->quantity_left,
                                'unit_price' => $entry->unit_price,
                                'created_at' => $entry->created_at
                            ];
                        })
                    ];
                }),
            'total_stock_value' => Product::with('stockEntries')
                ->get()
                ->sum('stock_value'),
            'base_products' => Product::whereNull('parent_id')
                ->select('id', 'name')
                ->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'parent_id' => 'nullable|exists:products,id',
            'base_quantity' => 'required|integer|min:0'
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
            'parent_id' => 'nullable|exists:products,id',
            'base_quantity' => 'required|integer|min:0'
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

    public function updateStockEntries(Request $request, Product $product)
    {
        $validated = $request->validate([
            'stock_entries' => 'required|array',
            'stock_entries.*.id' => 'required|exists:stock_entries,id',
            'stock_entries.*.quantity_left' => 'required|integer|min:0'
        ]);

        try {
            DB::beginTransaction();

            foreach ($validated['stock_entries'] as $entry) {
                StockEntry::where('id', $entry['id'])
                    ->where('product_id', $product->id)
                    ->update(['quantity_left' => $entry['quantity_left']]);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Stock mis à jour avec succès');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Erreur lors de la mise à jour du stock');
        }
    }

    public function transformToVariants(Request $request, Product $product)
    {
        $validated = $request->validate([
            'variant_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'unused_quantity' => 'required|integer|min:0',
        ], [
            'variant_id.required' => 'Le produit variant est obligatoire',
            'variant_id.exists' => 'Le produit variant sélectionné n\'existe pas',
            'quantity.required' => 'La quantité est obligatoire',
            'quantity.integer' => 'La quantité doit être un nombre entier',
            'quantity.min' => 'La quantité doit être supérieure à 0',
            'unused_quantity.required' => 'La quantité non utilisée est obligatoire',
            'unused_quantity.integer' => 'La quantité non utilisée doit être un nombre entier',
            'unused_quantity.min' => 'La quantité non utilisée doit être positive ou nulle',
        ]);

        try {
            DB::transaction(function () use ($product, $validated) {
                $variant = Product::findOrFail($validated['variant_id']);
                
                // Verify this is a valid parent-child relationship
                if ($variant->parent_id !== $product->id) {
                    throw new \Exception('Le produit sélectionné n\'est pas un variant de ce produit');
                }

                // Calculate total pieces needed
                $totalPiecesNeeded = $validated['quantity'] * $variant->base_quantity + $validated['unused_quantity'];
                
                // Check if parent product has enough stock
                if ($product->stock < $totalPiecesNeeded) {
                    throw new \Exception('Stock insuffisant. Stock disponible: ' . $product->stock . ' pièces');
                }

                // Decrement parent stock
                $product->decrementStock($totalPiecesNeeded);

                // Increment variant stock
                // create a new stock entry for the variant
                StockEntry::create([
                    'product_id' => $variant->id,
                    'quantity' => $validated['quantity'],
                    'quantity_left' => $validated['quantity'],
                    'unit_price' => ($product->cost_price / $product->base_quantity) * $validated['quantity']
                ]);
            });

            return redirect()->back()->with('success', 'Transformation effectuée avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }
    }
} 