<?php

namespace App\Services\Commission;

use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialProductCommissionRate;
use App\Models\Product;
use App\Models\ProductCategory;

/**
 * Resolves the commission rate for a given commercial × product pair.
 *
 * Lookup priority (first match wins):
 *   1. Commercial × product override (CommercialProductCommissionRate) — most specific.
 *   2. Commercial × category override (CommercialCategoryCommissionRate).
 *   3. Category default rate (ProductCategory.commission_rate) — applies to all commercials.
 *   4. 0.0 — no commission configured; the commercial earns nothing for this product.
 */
class CommissionRateResolverService
{
    /**
     * Returns the commission rate as a float, e.g., 0.0100 for 1%.
     * Returns 0.0 if no rate is configured for this commercial × product pair.
     */
    public function resolveRateForCommercialAndProduct(Commercial $commercial, Product $product): float
    {
        $productLevelOverride = CommercialProductCommissionRate::where('commercial_id', $commercial->id)
            ->where('product_id', $product->id)
            ->first();

        if ($productLevelOverride !== null) {
            return (float) $productLevelOverride->rate;
        }

        if ($product->product_category_id !== null) {
            // Priority 2: commercial × category override
            $commercialCategoryOverride = CommercialCategoryCommissionRate::where('commercial_id', $commercial->id)
                ->where('product_category_id', $product->product_category_id)
                ->first();

            if ($commercialCategoryOverride !== null) {
                return (float) $commercialCategoryOverride->rate;
            }

            // Priority 3: category default rate (applies to all commercials)
            $productCategory = $product->relationLoaded('productCategory')
                ? $product->productCategory
                : ProductCategory::find($product->product_category_id);

            if ($productCategory !== null && $productCategory->commission_rate !== null) {
                return (float) $productCategory->commission_rate;
            }
        }

        return 0.0;
    }
}
