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

    public function getCustomersAndProducts(Request $request)
    {
        $commercial = $request->user()->commercial;
        $products = Product::orderBy('name', 'asc')->get();
        $customers = $commercial->customers()->latest()->get();

        return response()->json([
            'products' => $products,
            'customers' => $customers,
        ]);
    }
} 