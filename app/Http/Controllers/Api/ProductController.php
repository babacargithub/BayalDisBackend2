<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Commercial;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::latest()->get();
        return response()->json($products);
    }

    public function show(Product $produit)
    {
        return $produit;
    }

    public function destroy(Product $produit)
    {
        try {
            // Check if product has related ventes
            if ($produit->ventes()->exists()) {
                return response()->json([
                    'message' => 'Impossible de supprimer ce produit car il a des ventes associées'
                ], 422);
            }

            $produit->delete();
            return response()->json(['message' => 'Produit supprimé avec succès']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur lors de la suppression du produit: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getClientsAndProducts(Request $request)
    {
        $commercial = $request->user()->commercial;
        $products = Product::orderBy('name', 'asc')->get();
        $clients = $commercial->customers()->latest()->get();

        return response()->json([
            'products' => $products,
            'customers' => $clients,
        ]);
    }
} 