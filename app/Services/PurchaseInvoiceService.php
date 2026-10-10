<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    /**
     * Total owed to suppliers: the value (quantity × unit_price) of every line
     * of every purchase invoice not yet flagged as paid.
     *
     * Transportation cost is excluded: it is allocated on top of the supplier price
     * and is not part of the amount owed to the supplier.
     */
    public function getTotalUnpaidSupplierDebtAmount(): int
    {
        return (int) PurchaseInvoiceItem::query()
            ->whereIn('purchase_invoice_id', PurchaseInvoice::query()->where('is_paid', false)->select('id'))
            ->sum(DB::raw('quantity * unit_price'));
    }

    public function getUnpaidPurchaseInvoicesCount(): int
    {
        return PurchaseInvoice::query()->where('is_paid', false)->count();
    }

    /**
     * Distribute the invoice's total transportation cost across its item lines
     * proportionally to each line's value (quantity × unit_price).
     *
     * Integer rounding is handled with the largest-remainder method: each line
     * receives floor(exact_share), then the leftover XOF are given one at a time
     * to the lines with the largest fractional parts.
     *
     * Example: 1 000 XOF across two lines worth 3 000 and 7 000 → [300, 700]
     * Example: 10 000 XOF across two lines worth 1 000 and 2 000 → [3 333, 6 667]
     */
    public function distributeTransportationCostToInvoiceItems(PurchaseInvoice $invoice): void
    {
        $totalTransportationCost = (int) $invoice->transportation_cost;

        if ($totalTransportationCost === 0) {
            return;
        }

        $items = $invoice->items;

        if ($items->isEmpty()) {
            return;
        }

        $totalInvoiceValue = $items->sum(fn ($item) => $item->quantity * $item->unit_price);

        if ($totalInvoiceValue === 0) {
            return;
        }

        // Compute exact proportional share for each item, then floor it.
        $allocations = $items->map(function ($item) use ($totalTransportationCost, $totalInvoiceValue) {
            $lineValue = $item->quantity * $item->unit_price;
            $exactShare = $totalTransportationCost * $lineValue / $totalInvoiceValue;

            return [
                'item' => $item,
                'floored_allocation' => (int) floor($exactShare),
                'fraction' => $exactShare - floor($exactShare),
            ];
        });

        $totalFloored = $allocations->sum('floored_allocation');
        $remainderXofToDistribute = $totalTransportationCost - $totalFloored;

        // Give the remaining XOF (one at a time) to the lines with the largest fractional parts.
        $allocationsWithRemainder = $allocations
            ->sortByDesc('fraction')
            ->values()
            ->map(function ($entry, $index) use ($remainderXofToDistribute) {
                $entry['final_allocation'] = $entry['floored_allocation'] + ($index < $remainderXofToDistribute ? 1 : 0);

                return $entry;
            });

        foreach ($allocationsWithRemainder as $entry) {
            $entry['item']->update(['transportation_cost' => $entry['final_allocation']]);
        }
    }
}
