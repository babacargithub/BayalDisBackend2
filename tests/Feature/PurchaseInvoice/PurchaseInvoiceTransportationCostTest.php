<?php

namespace Tests\Feature\PurchaseInvoice;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for PurchaseInvoiceService::distributeTransportationCostToInvoiceItems
 * and the end-to-end flow of transportation cost through to StockEntry.
 */
class PurchaseInvoiceTransportationCostTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PurchaseInvoiceService;
    }

    private function makeInvoiceWithItems(int $transportationCost, array $itemQuantities): PurchaseInvoice
    {
        $supplier = Supplier::create([
            'name' => 'Test Supplier',
        ]);

        $invoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-'.rand(1000, 9999),
            'invoice_date' => now(),
            'status' => 'pending',
            'transportation_cost' => $transportationCost,
        ]);

        foreach ($itemQuantities as $quantity) {
            $product = Product::create([
                'name' => 'Product '.rand(1, 9999),
                'price' => 5_000,
                'cost_price' => 3_000,
                'base_quantity' => 1,
            ]);

            $invoice->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 3_000,
            ]);
        }

        return $invoice->load('items');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // distributeTransportationCostToInvoiceItems
    // ──────────────────────────────────────────────────────────────────────────

    public function test_distributes_cost_equally_when_it_divides_evenly_across_items(): void
    {
        // 9 000 XOF across 3 lines → 3 000 each, no remainder.
        $invoice = $this->makeInvoiceWithItems(9_000, [1, 2, 3]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $invoice->refresh()->load('items');
        foreach ($invoice->items as $item) {
            $this->assertEquals(3_000, $item->transportation_cost);
        }
    }

    public function test_distributes_remainder_one_xof_at_a_time_to_first_lines(): void
    {
        // 10 000 XOF across 3 lines → base 3 333, remainder 1 → [3 334, 3 333, 3 333].
        $invoice = $this->makeInvoiceWithItems(10_000, [5, 5, 5]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $invoice->refresh()->load('items');
        $allocations = $invoice->items->pluck('transportation_cost')->sort()->values()->all();

        // Sum must equal the original total.
        $this->assertEquals(10_000, array_sum($allocations));

        // First line gets the extra 1 XOF; the other two are equal.
        $this->assertEquals(3_333, $allocations[0]);
        $this->assertEquals(3_333, $allocations[1]);
        $this->assertEquals(3_334, $allocations[2]);
    }

    public function test_does_nothing_when_transportation_cost_is_zero(): void
    {
        $invoice = $this->makeInvoiceWithItems(0, [5, 3]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $invoice->refresh()->load('items');
        foreach ($invoice->items as $item) {
            $this->assertEquals(0, $item->transportation_cost);
        }
    }

    public function test_entire_cost_goes_to_the_single_item_when_invoice_has_one_item(): void
    {
        $invoice = $this->makeInvoiceWithItems(7_500, [10]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $invoice->refresh()->load('items');
        $this->assertEquals(7_500, $invoice->items->first()->transportation_cost);
    }

    public function test_does_nothing_when_invoice_has_no_items(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier']);
        $invoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'INV-EMPTY',
            'invoice_date' => now(),
            'status' => 'pending',
            'transportation_cost' => 5_000,
        ]);

        // Should not throw; nothing to distribute.
        $this->service->distributeTransportationCostToInvoiceItems($invoice->load('items'));

        $this->assertEquals(0, PurchaseInvoiceItem::count());
    }

    public function test_total_allocated_across_items_always_equals_invoice_transportation_cost(): void
    {
        // 7 items, 10 001 XOF — a prime-ish number to stress the remainder logic.
        $invoice = $this->makeInvoiceWithItems(10_001, [1, 2, 3, 4, 5, 6, 7]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $invoice->refresh()->load('items');
        $totalAllocated = $invoice->items->sum('transportation_cost');

        $this->assertEquals(10_001, $totalAllocated);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // transportation_cost_per_unit accessor on PurchaseInvoiceItem
    // ──────────────────────────────────────────────────────────────────────────

    public function test_transportation_cost_per_unit_divides_line_cost_by_quantity(): void
    {
        $invoice = $this->makeInvoiceWithItems(9_000, [3]);

        $this->service->distributeTransportationCostToInvoiceItems($invoice);

        $item = $invoice->items->first()->fresh();

        // 9 000 total / 1 item → 9 000 line cost / 3 units = 3 000 per unit.
        $this->assertEquals(9_000, $item->transportation_cost);
        $this->assertEquals(3_000, $item->transportation_cost_per_unit);
    }

    public function test_transportation_cost_per_unit_returns_zero_when_no_cost_allocated(): void
    {
        $invoice = $this->makeInvoiceWithItems(0, [5]);

        $item = $invoice->items->first()->fresh();

        $this->assertEquals(0, $item->transportation_cost_per_unit);
    }
}
