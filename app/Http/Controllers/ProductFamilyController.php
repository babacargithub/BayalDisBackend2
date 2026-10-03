<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddProductsToProductFamilyRequest;
use App\Http\Requests\StoreProductFamilyRequest;
use App\Http\Requests\UpdateProductFamilyRequest;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\ProductFamilyService;
use Illuminate\Http\RedirectResponse;

class ProductFamilyController extends Controller
{
    public function __construct(private readonly ProductFamilyService $productFamilyService) {}

    public function store(StoreProductFamilyRequest $request): RedirectResponse
    {
        $this->productFamilyService->createFamily(
            $request->validated('name'),
            $request->validated('product_category_id'),
        );

        return redirect()->back()->with('success', 'Famille créée avec succès.');
    }

    public function update(UpdateProductFamilyRequest $request, ProductFamily $productFamily): RedirectResponse
    {
        $this->productFamilyService->updateFamily(
            $productFamily,
            $request->validated('name'),
            $request->validated('product_category_id'),
        );

        return redirect()->back()->with('success', 'Famille mise à jour avec succès.');
    }

    public function destroy(ProductFamily $productFamily): RedirectResponse
    {
        $this->productFamilyService->deleteFamily($productFamily);

        return redirect()->back()->with('success', 'Famille supprimée. Ses produits ont été conservés.');
    }

    public function addProducts(AddProductsToProductFamilyRequest $request, ProductFamily $productFamily): RedirectResponse
    {
        $this->productFamilyService->addProductsToFamily($productFamily, $request->validated('product_ids'));

        return redirect()->back()->with('success', 'Produits ajoutés à la famille.');
    }

    public function removeProduct(ProductFamily $productFamily, Product $product): RedirectResponse
    {
        $this->productFamilyService->removeProductFromFamily($productFamily, $product);

        return redirect()->back()->with('success', 'Produit retiré de la famille.');
    }
}
