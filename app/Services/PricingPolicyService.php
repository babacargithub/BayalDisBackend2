<?php

namespace App\Services;

use App\Models\PricingPolicy;
use App\Models\Product;
use Carbon\CarbonInterface;

class PricingPolicyService
{
    public function getActivePolicy(): ?PricingPolicy
    {
        return PricingPolicy::query()->where('active', true)->orderByDesc('id')->first();
    }

    /**
     * Replace each item's price with the product's credit_price when the active policy
     * has apply_credit_price enabled and the invoice is unpaid.
     *
     * Items with no credit_price set on their product are left unchanged.
     * Each item is an associative array with keys: product_id, quantity, price.
     */
    public function applyCreditPricingToItems(array $items, bool $isPaid): array
    {
        $policy = $this->getActivePolicy();
        if ($policy === null || ! $policy->apply_credit_price || $isPaid) {
            return $items;
        }

        $productIds = array_column($items, 'product_id');
        $productsByCreditPrice = Product::query()
            ->whereIn('id', $productIds)
            ->whereNotNull('credit_price')
            ->get()
            ->keyBy('id');

        foreach ($items as &$item) {
            $product = $productsByCreditPrice[$item['product_id']] ?? null;
            if ($product !== null) {
                $item['price'] = $product->credit_price;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Apply the active pricing policy to the provided items and return adjusted items.
     *
     * Each item is an associative array with keys: product_id, quantity, price.
     */
    public function applyPolicyToItems(array $items, ?CarbonInterface $shouldBePaidAt, bool $isPaid): array
    {
        $policy = $this->getActivePolicy();
        if ($policy === null) {
            return $items;
        }

        if ($policy->apply_to_deferred_only) {
            if ($isPaid) {
                return $items;
            }
            if ($shouldBePaidAt === null) {
                return $items;
            }
            $threshold = now()->copy()->addDays($policy->grace_days);
            if ($shouldBePaidAt->lessThanOrEqualTo($threshold)) {
                return $items;
            }
        }

        $percent = $policy->surcharge_percent;
        if ($percent <= 0) {
            return $items;
        }

        $multiplier = 1 + ($percent / 100);

        foreach ($items as &$item) {
            if (! isset($item['price'])) {
                continue;
            }
            $item['price'] = (int) round($item['price'] * $multiplier);
        }
        unset($item);

        return $items;
    }
}
