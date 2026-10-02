<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\StockEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The products page shows per-unit transport and packaging costs of every stock entry
 * in the "Gestion du stock" dialog. These costs must be part of the Inertia payload,
 * otherwise the front-end silently falls back to 0.
 */
class ProductIndexStockEntriesPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_index_exposes_transportation_and_packaging_cost_of_each_stock_entry(): void
    {
        $product = $this->createProduct();
        $olderStockEntry = $this->createStockEntry($product, transportationCost: 550, packagingCost: 25, createdAt: now()->subDay());
        $newerStockEntry = $this->createStockEntry($product, transportationCost: 1410, packagingCost: 0, createdAt: now());

        $this->actingAs(User::factory()->create())
            ->get(route('produits.index'))
            ->assertInertia(function (AssertableInertia $page) use ($olderStockEntry, $newerStockEntry) {
                $page->has('products', 1)
                    ->has('products.0.stock_entries', 2)
                    ->where('products.0.stock_entries.0.id', $newerStockEntry->id)
                    ->where('products.0.stock_entries.0.transportation_cost', 1410)
                    ->where('products.0.stock_entries.0.packaging_cost', 0)
                    ->where('products.0.stock_entries.1.id', $olderStockEntry->id)
                    ->where('products.0.stock_entries.1.transportation_cost', 550)
                    ->where('products.0.stock_entries.1.packaging_cost', 25);
            });
    }

    public function test_products_index_exposes_zero_transportation_cost_when_stock_entry_has_none(): void
    {
        $product = $this->createProduct();
        $this->createStockEntry($product, transportationCost: 0, packagingCost: 0, createdAt: now());

        $this->actingAs(User::factory()->create())
            ->get(route('produits.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.0.stock_entries.0.transportation_cost', 0));
    }

    private function createProduct(): Product
    {
        return Product::create([
            'name' => 'Product '.uniqid(),
            'price' => 10000,
            'cost_price' => 6000,
            'base_quantity' => 1,
        ]);
    }

    private function createStockEntry(Product $product, int $transportationCost, int $packagingCost, $createdAt): StockEntry
    {
        $stockEntry = StockEntry::create([
            'product_id' => $product->id,
            'quantity' => 10,
            'quantity_left' => 10,
            'unit_price' => 5000,
            'transportation_cost' => $transportationCost,
            'packaging_cost' => $packagingCost,
        ]);

        $stockEntry->timestamps = false;
        $stockEntry->created_at = $createdAt;
        $stockEntry->save();

        return $stockEntry;
    }
}
