<?php

namespace App\Services\Commission;

use App\Models\Commercial;
use App\Models\CommercialCategoryCommissionRate;
use App\Models\CommercialProductCommissionRate;
use App\Models\Product;

/**
 * Resolves the commission rate for a given commercial × product pair.
 *
 * Lookup priority:
 *   1. Product-level override (CommercialProductCommissionRate) — most specific.
 *   2. Category-level default (CommercialCategoryCommissionRate) — based on the
 *      product's category if no product-level override is set.
 *   3. 0.0 — no commission configured; the commercial earns nothing for this product.
 */
class CommissionRateResolverService
{
    /**
     * Returns the commission rate as a float, e.g. 0.0100 for 1%.
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
            $categoryLevelDefault = CommercialCategoryCommissionRate::where('commercial_id', $commercial->id)
                ->where('product_category_id', $product->product_category_id)
                ->first();

            if ($categoryLevelDefault !== null) {
                return (float) $categoryLevelDefault->rate;
            }
        }

        return 0.0;
    }
}
