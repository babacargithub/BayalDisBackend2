<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Support\Facades\DB;

class ProductFamilyService
{
    public function createFamily(string $name, ?int $productCategoryId): ProductFamily
    {
        return ProductFamily::create([
            'name' => $name,
            'product_category_id' => $productCategoryId,
        ]);
    }

    public function updateFamily(ProductFamily $productFamily, string $name, ?int $productCategoryId): ProductFamily
    {
        $productFamily->update([
            'name' => $name,
            'product_category_id' => $productCategoryId,
        ]);

        return $productFamily;
    }

    /**
     * Deleting a family never deletes its products: they simply become family-less.
     */
    public function deleteFamily(ProductFamily $productFamily): void
    {
        DB::transaction(function () use ($productFamily) {
            Product::query()
                ->where('product_family_id', $productFamily->id)
                ->update(['product_family_id' => null]);

            $productFamily->delete();
        });
    }

    /**
     * Moves the given products into the family (a product belongs to at most one family).
     * Only the family link changes: category, prices, stock and commissions are untouched.
     *
     * @param  array<int, int>  $productIds
     * @return int number of products now in the family
     */
    public function addProductsToFamily(ProductFamily $productFamily, array $productIds): int
    {
        return DB::transaction(function () use ($productFamily, $productIds) {
            return Product::query()
                ->whereIn('id', array_unique($productIds))
                ->update(['product_family_id' => $productFamily->id]);
        });
    }

    public function removeProductFromFamily(ProductFamily $productFamily, Product $product): void
    {
        if ($product->product_family_id !== $productFamily->id) {
            return;
        }

        $product->update(['product_family_id' => null]);
    }
}
