<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Services\PurchaseInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseInvoiceTransportationCostDistributionTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $purchaseInvoiceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchaseInvoiceService = new PurchaseInvoiceService;
    }

    private function createInvoiceWithItems(int $transportationCost, array $itemDefinitions): PurchaseInvoice
    {
        $supplier = Supplier::create([
            'name' => 'Test Supplier',
            'contact' => 'Test Contact',
            'email' => 'test@test.com',
            'phone' => '0000000000',
            'address' => 'Test Address',
            'tax_number' => 'TN-TEST',
        ]);

        $invoice = PurchaseInvoice::create([
            'supplier_id' => $supplier->id,
            'invoice_number' => 'TEST-'.uniqid(),
            'invoice_date' => now(),
            'transportation_cost' => $transportationCost,
            'status' => 'pending',
            'is_draft' => false,
            'is_paid' => false,
            'is_stocked' => false,
        ]);

        foreach ($itemDefinitions as $definition) {
            $product = Product::create([
                'name' => 'Product '.uniqid(),
                'price' => $definition['unit_price'],
                'cost_price' => $definition['unit_price'],
                'base_quantity' => 1,
            ]);

            $invoice->items()->create([
                'product_id' => $product->id,
                'quantity' => $definition['quantity'],
                'unit_price' => $definition['unit_price'],
            ]);
        }

        return $invoice->fresh(['items']);
    }

    public function test_distribution_is_proportional_to_line_value_with_exact_division(): void
    {
        // Line A: 1 × 1 000 = 1 000 XOF (10% of total)
        // Line B: 1 × 9 000 = 9 000 XOF (90% of total)
        // Transport 1 000 → A: 100, B: 900
        $invoice = $this->createInvoiceWithItems(1000, [
            ['quantity' => 1, 'unit_price' => 1000],
            ['quantity' => 1, 'unit_price' => 9000],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        $this->assertEquals(100, $items[0]->transportation_cost);
        $this->assertEquals(900, $items[1]->transportation_cost);
    }

    public function test_distribution_uses_quantity_times_unit_price_as_weight(): void
    {
        // Line A: 10 × 100 = 1 000 XOF (25% of 4 000)
        // Line B: 5  × 600 = 3 000 XOF (75% of 4 000)
        // Transport 4 000 → A: 1 000, B: 3 000
        $invoice = $this->createInvoiceWithItems(4000, [
            ['quantity' => 10, 'unit_price' => 100],
            ['quantity' => 5,  'unit_price' => 600],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        $this->assertEquals(1000, $items[0]->transportation_cost);
        $this->assertEquals(3000, $items[1]->transportation_cost);
    }

    public function test_rounding_remainder_goes_to_line_with_largest_fractional_part(): void
    {
        // Line A: 1 × 1 000 = 1 000 (1/3 of 3 000) → exact = 333.33, fraction = 0.33
        // Line B: 1 × 2 000 = 2 000 (2/3 of 3 000) → exact = 666.66, fraction = 0.66
        // Largest fraction is B → B gets floor+1 = 667, A gets floor = 333
        $invoice = $this->createInvoiceWithItems(1000, [
            ['quantity' => 1, 'unit_price' => 1000],
            ['quantity' => 1, 'unit_price' => 2000],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        $this->assertEquals(333, $items[0]->transportation_cost);
        $this->assertEquals(667, $items[1]->transportation_cost);
    }

    public function test_total_allocated_always_equals_transportation_cost_regardless_of_rounding(): void
    {
        $transportationCost = 10000;
        $invoice = $this->createInvoiceWithItems($transportationCost, [
            ['quantity' => 3, 'unit_price' => 700],
            ['quantity' => 7, 'unit_price' => 500],
            ['quantity' => 2, 'unit_price' => 1100],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $totalAllocated = $invoice->fresh(['items'])->items->sum('transportation_cost');
        $this->assertEquals($transportationCost, $totalAllocated,
            'Sum of all allocated transportation costs must equal the invoice transportation cost exactly'
        );
    }

    public function test_zero_transportation_cost_leaves_all_items_unchanged(): void
    {
        $invoice = $this->createInvoiceWithItems(0, [
            ['quantity' => 5, 'unit_price' => 1000],
            ['quantity' => 3, 'unit_price' => 2000],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        foreach ($items as $item) {
            $this->assertEquals(0, $item->transportation_cost);
        }
    }

    public function test_single_item_receives_entire_transportation_cost(): void
    {
        $invoice = $this->createInvoiceWithItems(5000, [
            ['quantity' => 10, 'unit_price' => 200],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        $this->assertEquals(5000, $items[0]->transportation_cost);
    }

    public function test_equal_value_lines_receive_equal_allocation(): void
    {
        $invoice = $this->createInvoiceWithItems(2000, [
            ['quantity' => 2, 'unit_price' => 1000],
            ['quantity' => 2, 'unit_price' => 1000],
        ]);

        $this->purchaseInvoiceService->distributeTransportationCostToInvoiceItems($invoice);

        $items = $invoice->fresh(['items'])->items;
        $this->assertEquals(1000, $items[0]->transportation_cost);
        $this->assertEquals(1000, $items[1]->transportation_cost);
    }
}
