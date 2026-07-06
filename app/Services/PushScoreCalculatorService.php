<?php

namespace App\Services;

use App\Models\SalesInvoice;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Computes the weighted product diversity score (push score) for a single invoice.
 *
 * FORMULA
 *   effective_weight(product) = products.push_weight ?? category.push_weight ?? 1.0
 *   raw_score                 = Σ effective_weight(p) for each DISTINCT product on the invoice
 *   push_score                = CLAMP(ROUND(raw_score / perfect_ceiling × 100, 2), 1, 100)
 *
 * An invoice with no items returns 0.0 (no score — not even the minimum 1).
 *
 * WEIGHT RESOLUTION (highest priority first)
 *   1. products.push_weight  — explicit per-product override
 *   2. product_categories.push_weight  — category default (1.00 out of the box)
 *   3. Hardcoded fallback 1.0  — for products without a category
 *
 * The perfect_ceiling is read from config('bayal.push_score.perfect_ceiling').
 * Raising it makes the scale harder; lowering it rewards smaller variety more.
 */
readonly class PushScoreCalculatorService
{
    private float $perfectCeiling;

    public function __construct()
    {
        $this->perfectCeiling = (float) config('bayal.push_score.perfect_ceiling', 5.0);
    }

    /**
     * Compute the push score (0–100) for the given invoice.
     *
     * The entire weight resolution and aggregation runs in a single SQL query —
     * no Eloquent models are loaded into memory regardless of how many items the
     * invoice has.
     *
     * Returns 0.0 when the invoice has no items.
     * Returns a value in [1, 100] for any invoice with at least one item.
     */
    public function computeForInvoice(SalesInvoice $invoice): float
    {
        // Inner query: one row per distinct product with its resolved effective weight.
        // Grouping by product_id deduplicates — a product appearing on multiple lines
        // only contributes its weight once. COALESCE implements the priority chain:
        // product override → category default → 1.0.
        $weightedScoreSum = (float) DB::table(function ($innerQuery) use ($invoice): void {
            $innerQuery->from('ventes')
                ->where('ventes.sales_invoice_id', $invoice->id)
                ->where('ventes.type', Vente::TYPE_INVOICE)
                ->join('products', 'products.id', '=', 'ventes.product_id')
                ->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
                ->selectRaw('COALESCE(products.push_weight, product_categories.push_weight, 1.0) AS effective_weight')
                ->groupBy('ventes.product_id', 'products.push_weight', 'product_categories.push_weight');
        }, 'distinct_products')
            ->sum('effective_weight');

        if ($weightedScoreSum === 0.0) {
            return 0.0;
        }

        return (float) max(1.0, min(100.0, round($weightedScoreSum / $this->perfectCeiling * 100, 2)));
    }
}
