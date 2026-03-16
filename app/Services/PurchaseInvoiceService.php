<?php

namespace App\Services;

use App\Models\PurchaseInvoice;

class PurchaseInvoiceService
{
    /**
     * Distribute the invoice's total transportation cost equally across its item lines,
     * then persist the allocated amount on each PurchaseInvoiceItem.
     *
     * The allocation is by number of item lines (not by quantity or weight).
     * Integer remainder (from division) is distributed one XOF at a time to the first lines.
     *
     * TODO: Replace the equal-per-line strategy with a weight-based or volume-based
     *       distribution once products have weight / volume attributes. The per-unit
     *       cost will then be proportional to the physical footprint of each product.
     *
     * Example: 10 000 XOF across 3 lines → [3 334, 3 333, 3 333]
     */
    public function distributeTransportationCostToInvoiceItems(PurchaseInvoice $invoice): void
    {
        $totalTransportationCost = (int) $invoice->transportation_cost;

        if ($totalTransportationCost === 0) {
            return;
        }

        $items = $invoice->items;
        $numberOfItems = $items->count();

        if ($numberOfItems === 0) {
            return;
        }

        $baseAllocationPerLine = intdiv($totalTransportationCost, $numberOfItems);
        $remainder = $totalTransportationCost - ($baseAllocationPerLine * $numberOfItems);

        foreach ($items as $index => $item) {
            // Distribute the remainder one XOF at a time starting from the first lines.
            $allocationForLine = $baseAllocationPerLine + ($index < $remainder ? 1 : 0);

            $item->update(['transportation_cost' => $allocationForLine]);
        }
    }
}
