<?php

namespace Tests\Feature\Product;

use App\Enums\CarLoadStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\CarLoad;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Team;
use App\Models\User;
use App\Models\Vente;
use App\Services\ProductService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ProductService — covers all warehouse FIFO stock operations,
 * car load stock operations, unit conversion logic, and display formatting.
 *
 * Each public method on ProductService has a dedicated section below.
 * All calculation logic is tested at boundary values, zero values, and happy paths.
 */
class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = app(ProductService::class);
    }

    // =========================================================================
    // SECTION 1 — getProductAvailableStockInWarehouse
    // Maps to testing-sheet rows 2.6, 2.7
    // =========================================================================

    public function test_get_product_available_stock_in_warehouse_sums_quantity_left_across_all_stock_entries(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500);
        $this->makeStockEntry($product, quantityLeft: 15, unitPrice: 500);
        $this->makeStockEntry($product, quantityLeft: 3, unitPrice: 500);

        $this->assertSame(28, $this->productService->getProductAvailableStockInWarehouse($product));
    }

    public function test_get_product_available_stock_in_warehouse_returns_zero_when_no_stock_entries_exist(): void
    {
        $product = $this->makeParentProduct();

        $this->assertSame(0, $this->productService->getProductAvailableStockInWarehouse($product));
    }

    public function test_get_product_available_stock_in_warehouse_returns_zero_when_all_stock_entries_are_depleted_to_zero(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500);
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500);

        $this->assertSame(0, $this->productService->getProductAvailableStockInWarehouse($product));
    }

    public function test_get_product_available_stock_in_warehouse_only_counts_stock_for_the_given_product(): void
    {
        $productA = $this->makeParentProduct(name: 'Product A');
        $productB = $this->makeParentProduct(name: 'Product B');
        $this->makeStockEntry($productA, quantityLeft: 10, unitPrice: 500);
        $this->makeStockEntry($productB, quantityLeft: 99, unitPrice: 500);

        $this->assertSame(10, $this->productService->getProductAvailableStockInWarehouse($productA));
    }

    // =========================================================================
    // SECTION 2 — getProductWarehouseStockValue
    // Maps to testing-sheet rows 1.11, 1.12
    // =========================================================================

    public function test_get_product_warehouse_stock_value_computes_sum_of_quantity_left_times_unit_price(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500);
        $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 800);

        // 10 × 500 + 5 × 800 = 5000 + 4000 = 9000
        $this->assertSame(9000, $this->productService->getProductWarehouseStockValue($product));
    }

    public function test_get_product_warehouse_stock_value_returns_zero_when_all_stock_entries_are_depleted(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500);

        $this->assertSame(0, $this->productService->getProductWarehouseStockValue($product));
    }

    public function test_get_product_warehouse_stock_value_excludes_depleted_entries_from_computation(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 999); // depleted — must not be counted
        $this->makeStockEntry($product, quantityLeft: 4, unitPrice: 500);

        // Only the non-depleted entry: 4 × 500 = 2000
        $this->assertSame(2000, $this->productService->getProductWarehouseStockValue($product));
    }

    public function test_get_product_warehouse_stock_value_returns_zero_when_no_stock_entries_exist(): void
    {
        $product = $this->makeParentProduct();

        $this->assertSame(0, $this->productService->getProductWarehouseStockValue($product));
    }

    // =========================================================================
    // SECTION 3 — getTotalQuantitySold
    // =========================================================================

    public function test_get_total_quantity_sold_sums_quantity_across_all_ventes_for_the_product(): void
    {
        $product = $this->makeParentProduct();
        $this->makeVenteForProduct($product, quantity: 5);
        $this->makeVenteForProduct($product, quantity: 3);
        $this->makeVenteForProduct($product, quantity: 7);

        $this->assertSame(15, $this->productService->getTotalQuantitySold($product));
    }

    public function test_get_total_quantity_sold_returns_zero_when_no_ventes_exist_for_the_product(): void
    {
        $product = $this->makeParentProduct();

        $this->assertSame(0, $this->productService->getTotalQuantitySold($product));
    }

    public function test_get_total_quantity_sold_does_not_count_ventes_from_other_products(): void
    {
        $productA = $this->makeParentProduct(name: 'Product A');
        $productB = $this->makeParentProduct(name: 'Product B');
        $this->makeVenteForProduct($productA, quantity: 10);
        $this->makeVenteForProduct($productB, quantity: 99);

        $this->assertSame(10, $this->productService->getTotalQuantitySold($productA));
    }

    // =========================================================================
    // SECTION 4 — decreaseWarehouseStockUsingFifo
    // Maps to testing-sheet rows 2.1, 2.2, 2.3, 2.4 (+ boundary variants)
    // =========================================================================

    public function test_decrease_warehouse_stock_using_fifo_consumes_oldest_entry_first_when_it_has_sufficient_stock(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $olderEntry = $this->makeStockEntry($product, quantityLeft: 20, unitPrice: 500, createdAt: $now->copy()->subHour());
        $newerEntry = $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500, createdAt: $now);

        $this->productService->decreaseWarehouseStockUsingFifo($product, 8);

        $olderEntry->refresh();
        $newerEntry->refresh();

        $this->assertSame(12, $olderEntry->quantity_left, 'Oldest entry must be decremented first');
        $this->assertSame(10, $newerEntry->quantity_left, 'Newer entry must remain untouched');
    }

    public function test_decrease_warehouse_stock_using_fifo_spans_across_multiple_entries_consuming_oldest_first(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $oldestEntry = $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500, createdAt: $now->copy()->subHours(3));
        $middleEntry = $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500, createdAt: $now->copy()->subHours(1));
        $newestEntry = $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500, createdAt: $now);

        // 8 units: exhausts oldest (5), then takes 3 from middle
        $this->productService->decreaseWarehouseStockUsingFifo($product, 8);

        $oldestEntry->refresh();
        $middleEntry->refresh();
        $newestEntry->refresh();

        $this->assertSame(0, $oldestEntry->quantity_left, 'Oldest entry must be fully depleted');
        $this->assertSame(2, $middleEntry->quantity_left, 'Middle entry must be partially consumed (5 − 3 = 2)');
        $this->assertSame(10, $newestEntry->quantity_left, 'Newest entry must remain untouched');
    }

    public function test_decrease_warehouse_stock_using_fifo_throws_insufficient_stock_exception_when_total_stock_is_insufficient(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500);

        $this->expectException(InsufficientStockException::class);

        $this->productService->decreaseWarehouseStockUsingFifo($product, 10);
    }

    public function test_decrease_warehouse_stock_using_fifo_throws_when_requesting_exactly_one_more_unit_than_available(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 9, unitPrice: 500);

        $this->expectException(InsufficientStockException::class);

        $this->productService->decreaseWarehouseStockUsingFifo($product, 10);
    }

    public function test_decrease_warehouse_stock_using_fifo_consuming_exactly_all_available_stock_leaves_every_entry_at_zero(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $firstEntry = $this->makeStockEntry($product, quantityLeft: 7, unitPrice: 500, createdAt: $now->copy()->subHour());
        $secondEntry = $this->makeStockEntry($product, quantityLeft: 3, unitPrice: 500, createdAt: $now);

        $this->productService->decreaseWarehouseStockUsingFifo($product, 10);

        $firstEntry->refresh();
        $secondEntry->refresh();

        $this->assertSame(0, $firstEntry->quantity_left);
        $this->assertSame(0, $secondEntry->quantity_left);
    }

    public function test_decrease_warehouse_stock_using_fifo_throws_insufficient_stock_exception_when_no_stock_entries_exist(): void
    {
        $product = $this->makeParentProduct();

        $this->expectException(InsufficientStockException::class);

        $this->productService->decreaseWarehouseStockUsingFifo($product, 1);
    }

    // =========================================================================
    // SECTION 5 — decrementStock routing (warehouse vs car load)
    // The car load FIFO logic itself is tested exhaustively in CarLoadStockTest.
    // Here we verify that ProductService routes to the correct path.
    // =========================================================================

    public function test_decrement_stock_routes_to_warehouse_fifo_when_car_load_is_null(): void
    {
        $product = $this->makeParentProduct();
        $warehouseEntry = $this->makeStockEntry($product, quantityLeft: 20, unitPrice: 500);

        $this->productService->decrementStock($product, 8, updateMainStock: false, carLoad: null);

        $warehouseEntry->refresh();
        $this->assertSame(12, $warehouseEntry->quantity_left, 'Warehouse FIFO must be used when no car load is provided');
    }

    public function test_decrement_stock_routes_to_warehouse_fifo_when_update_main_stock_is_true_regardless_of_car_load(): void
    {
        $product = $this->makeParentProduct();
        $warehouseEntry = $this->makeStockEntry($product, quantityLeft: 20, unitPrice: 500);
        $carLoad = $this->makeActiveCarLoad();
        $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 50, 'quantity_left' => 50, 'loaded_at' => Carbon::now()]);

        // updateMainStock=true always goes to warehouse, even when carLoad is supplied
        $this->productService->decrementStock($product, 5, updateMainStock: true, carLoad: $carLoad);

        $warehouseEntry->refresh();
        $this->assertSame(15, $warehouseEntry->quantity_left, 'Warehouse must be decremented when updateMainStock=true');
    }

    public function test_decrement_stock_routes_to_car_load_fifo_when_car_load_is_provided_and_update_main_stock_is_false(): void
    {
        $product = $this->makeParentProduct();
        $warehouseEntry = $this->makeStockEntry($product, quantityLeft: 100, unitPrice: 500);
        $carLoad = $this->makeActiveCarLoad();
        $carLoadItem = $carLoad->items()->create(['product_id' => $product->id, 'quantity_loaded' => 20, 'quantity_left' => 20, 'loaded_at' => Carbon::now()]);

        $this->productService->decrementStock($product, 8, updateMainStock: false, carLoad: $carLoad);

        $carLoadItem->refresh();
        $warehouseEntry->refresh();

        $this->assertSame(12, $carLoadItem->quantity_left, 'Car load item must be decremented');
        $this->assertSame(100, $warehouseEntry->quantity_left, 'Warehouse must remain untouched');
    }

    // =========================================================================
    // SECTION 6 — incrementWarehouseStockOnLatestEntry
    // Maps to testing-sheet row 2.5
    // =========================================================================

    public function test_increment_warehouse_stock_on_latest_entry_works_correctly_with_a_single_stock_entry(): void
    {
        $product = $this->makeParentProduct();
        $entry = $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500);

        $this->productService->incrementWarehouseStockOnLatestEntry($product, 5);

        $entry->refresh();
        $this->assertSame(15, $entry->quantity_left);
    }

    public function test_increment_warehouse_stock_on_latest_entry_adds_quantity_to_the_newest_stock_entry(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $olderEntry = $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500, createdAt: $now->copy()->subHour());
        $newerEntry = $this->makeStockEntry($product, quantityLeft: 3, unitPrice: 500, createdAt: $now);

        $this->productService->incrementWarehouseStockOnLatestEntry($product, 7);

        $newerEntry->refresh();
        $olderEntry->refresh();

        $this->assertSame(10, $newerEntry->quantity_left, 'Newest entry must receive the increment (3 + 7 = 10)');
        $this->assertSame(5, $olderEntry->quantity_left, 'Older entry must remain untouched');
    }

    // =========================================================================
    // SECTION 7 — getOldestNonEmptyStockEntryInWarehouse
    // =========================================================================

    public function test_get_oldest_non_empty_stock_entry_in_warehouse_returns_oldest_entry_with_positive_quantity_left(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $oldestEntry = $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500, createdAt: $now->copy()->subHours(2));
        $this->makeStockEntry($product, quantityLeft: 10, unitPrice: 500, createdAt: $now);

        $result = $this->productService->getOldestNonEmptyStockEntryInWarehouse($product);

        $this->assertSame($oldestEntry->id, $result->id, 'Must return the oldest non-empty entry');
    }

    public function test_get_oldest_non_empty_stock_entry_in_warehouse_skips_depleted_entries_and_returns_oldest_with_remaining_stock(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500, createdAt: $now->copy()->subHours(3)); // depleted
        $firstNonEmptyEntry = $this->makeStockEntry($product, quantityLeft: 4, unitPrice: 500, createdAt: $now->copy()->subHours(1));
        $this->makeStockEntry($product, quantityLeft: 8, unitPrice: 500, createdAt: $now);

        $result = $this->productService->getOldestNonEmptyStockEntryInWarehouse($product);

        $this->assertSame($firstNonEmptyEntry->id, $result->id, 'Must skip the depleted entry and return the oldest one with stock');
    }

    public function test_get_oldest_non_empty_stock_entry_in_warehouse_throws_exception_when_all_entries_are_depleted(): void
    {
        $product = $this->makeParentProduct();
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500);
        $this->makeStockEntry($product, quantityLeft: 0, unitPrice: 500);

        $this->expectException(\Exception::class);

        $this->productService->getOldestNonEmptyStockEntryInWarehouse($product);
    }

    public function test_get_oldest_non_empty_stock_entry_in_warehouse_throws_exception_when_no_entries_exist_at_all(): void
    {
        $product = $this->makeParentProduct();

        $this->expectException(\Exception::class);

        $this->productService->getOldestNonEmptyStockEntryInWarehouse($product);
    }

    // =========================================================================
    // SECTION 8 — convertVariantQuantityToParentQuantity
    // Maps to testing-sheet rows 2.8, 2.9, 2.10, 2.11, 2.12
    // Conversion formula: ratio = parent.base_quantity / variant.base_quantity
    // =========================================================================

    public function test_convert_variant_quantity_to_parent_quantity_returns_correct_parent_quantity_for_clean_ratio(): void
    {
        // Parent (carton) = 12 packs; ratio = 12/1 = 12; 24 packs = exactly 2 cartons
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 1);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 24);

        $this->assertSame(2, $result['parent_quantity']);
        $this->assertEqualsWithDelta(2.0, $result['decimal_parent_quantity'], 0.0001);
        $this->assertSame(0, $result['remaining_variant_quantity']);
    }

    public function test_convert_variant_quantity_to_parent_quantity_returns_correct_decimal_parent_quantity_for_non_integer_result(): void
    {
        // 30 packs ÷ ratio(12) = 2.5 cartons (decimal result)
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 1);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 30);

        $this->assertEqualsWithDelta(2.5, $result['decimal_parent_quantity'], 0.0001);
    }

    public function test_convert_variant_quantity_to_parent_quantity_applies_ceiling_and_returns_correct_remaining_variant_quantity(): void
    {
        // 30 packs → ceil(30/12) = 3 cartons; remaining = (3 × 12) − 30 = 6 packs
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 1);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 30);

        $this->assertSame(2, $result['parent_quantity'], 'floar(30/12) = 3 cartons needed');
        $this->assertSame(6, $result['remaining_variant_quantity'], '(3 × 12) − 30 = 6 packs returned');
    }
// TODO change tests to apply floar instead of ceiling
    public function test_convert_variant_quantity_to_parent_quantity_applies_ceiling_for_a_single_partial_unit(): void
    {
        // 1 pack out of 12 → needs 1 full carton; remaining = 11 packs
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 1);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 1);

        $this->assertSame(0, $result['parent_quantity'], 'ceil(1/12) = 1 carton needed');
        $this->assertEqualsWithDelta(1 / 12, $result['decimal_parent_quantity'], 0.0001);
        $this->assertSame(1, $result['remaining_variant_quantity'], '(1 × 12) − 1 = 11 packs unused');
    }

    public function test_convert_variant_quantity_to_parent_quantity_returns_all_zeros_when_called_on_a_base_product_with_no_parent(): void
    {
        // A base product (no parent_id) returns a zeroed result — conversion not applicable
        $baseProduct = $this->makeParentProduct(baseQuantity: 12);

        $result = $this->productService->convertVariantQuantityToParentQuantity($baseProduct, 30);

        $this->assertSame(0, $result['parent_quantity']);
        $this->assertEqualsWithDelta(0.0, $result['decimal_parent_quantity'], 0.0001);
        $this->assertSame(0, $result['remaining_variant_quantity']);
    }

    public function test_convert_variant_quantity_to_parent_quantity_returns_all_zeros_when_quantity_is_zero(): void
    {
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 1);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 0);

        $this->assertSame(0, $result['parent_quantity']);
        $this->assertEqualsWithDelta(0.0, $result['decimal_parent_quantity'], 0.0001);
        $this->assertSame(0, $result['remaining_variant_quantity']);
    }

    public function test_convert_variant_quantity_to_parent_quantity_works_correctly_with_one_to_one_conversion_ratio(): void
    {
        // Same base_quantity for parent and variant → ratio = 1; no conversion needed
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $variantProduct = $this->makeVariantProduct($parentProduct, baseQuantity: 12);

        $result = $this->productService->convertVariantQuantityToParentQuantity($variantProduct, 5);

        $this->assertSame(5, $result['parent_quantity']);
        $this->assertEqualsWithDelta(5.0, $result['decimal_parent_quantity'], 0.0001);
        $this->assertSame(0, $result['remaining_variant_quantity']);
    }

    // =========================================================================
    // SECTION 9 — getFormattedDisplayOfCartonAndPaquets
    // Maps to testing-sheet rows 2.13, 2.14, 2.15
    // =========================================================================

    public function test_get_formatted_display_of_carton_and_paquets_returns_whole_cartons_with_zero_paquets_for_integer_quantity(): void
    {
        // 3.0 cartons → cartons = 3, decimal = 0.0, paquets = 0 × (12/1) = 0
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $this->makeVariantProduct($parentProduct, baseQuantity: 1, name: 'Pack');

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 3.0);

        $this->assertSame(3, $result['cartons']);
        $this->assertSame(0, $result['paquets']);
        $this->assertSame('Pack', $result['first_variant_name']);
    }

    public function test_get_formatted_display_of_carton_and_paquets_returns_correct_cartons_and_paquets_for_mixed_quantity(): void
    {
        // 2.5 cartons → cartons = 2, decimal = 0.5, paquets = 0.5 × (12/1) = 6
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $this->makeVariantProduct($parentProduct, baseQuantity: 1, name: 'Pack');

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 2.5);

        $this->assertSame(2, $result['cartons']);
        $this->assertSame(6, $result['paquets']);
    }

    public function test_get_formatted_display_of_carton_and_paquets_returns_zero_for_zero_quantity_input(): void
    {
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);
        $this->makeVariantProduct($parentProduct, baseQuantity: 1, name: 'Pack');

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 0.0);

        $this->assertSame(0, $result['cartons']);
        $this->assertSame(0, $result['paquets']);
    }

    public function test_get_formatted_display_of_carton_and_paquets_returns_empty_variant_name_and_zero_paquets_when_product_has_no_variants(): void
    {
        $parentProduct = $this->makeParentProduct(baseQuantity: 12);

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 2.5);

        $this->assertSame(2, $result['cartons']);
        $this->assertSame(0, $result['paquets']);
        $this->assertSame('', $result['first_variant_name']);
    }

    public function test_get_formatted_display_of_carton_and_paquets_computes_paquets_correctly_with_non_unit_variant_base_quantity(): void
    {
        // Parent: base_quantity = 48; variant: base_quantity = 4; display ratio = 48/4 = 12
        // 1.5 cartons → cartons = 1, decimal = 0.5, paquets = 0.5 × 12 = 6
        $parentProduct = $this->makeParentProduct(baseQuantity: 48);
        $this->makeVariantProduct($parentProduct, baseQuantity: 4, name: 'Half-Pack');

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 1.5);

        $this->assertSame(1, $result['cartons']);
        $this->assertSame(6, $result['paquets']);
        $this->assertSame('Half-Pack', $result['first_variant_name']);
    }

    // =========================================================================
    // SECTION 10 — Product model accessor delegation (is_base_product)
    // Maps to testing-sheet rows 2.16, 2.17
    // =========================================================================

    public function test_product_is_base_product_attribute_returns_true_for_product_with_no_parent(): void
    {
        $parentProduct = $this->makeParentProduct();

        $this->assertTrue($parentProduct->is_base_product);
    }

    public function test_product_is_base_product_attribute_returns_false_for_variant_product(): void
    {
        $parentProduct = $this->makeParentProduct();
        $variantProduct = $this->makeVariantProduct($parentProduct);

        $this->assertFalse($variantProduct->is_base_product);
    }

    // =========================================================================
    // SECTION 11 — increaseStock routing (warehouse vs car load)
    // Car load path requires auth; we test the warehouse path directly.
    // Full car load increase semantics are covered in CarLoadStockTest.
    // =========================================================================

    public function test_increase_stock_routes_to_warehouse_on_latest_entry_when_update_warehouse_stock_is_true(): void
    {
        $product = $this->makeParentProduct();
        $now = Carbon::now();

        $this->makeStockEntry($product, quantityLeft: 5, unitPrice: 500, createdAt: $now->copy()->subHour());
        $latestEntry = $this->makeStockEntry($product, quantityLeft: 3, unitPrice: 500, createdAt: $now);

        $this->productService->increaseStock($product, 7, updateWarehouseStock: true);

        $latestEntry->refresh();
        $this->assertSame(10, $latestEntry->quantity_left, 'Latest warehouse entry must receive the increment (3 + 7 = 10)');
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function makeParentProduct(
        string $name = '',
        int $price = 10000,
        int $costPrice = 6000,
        int $baseQuantity = 1,
    ): Product {
        return Product::create([
            'name' => $name ?: 'Product '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => $baseQuantity,
        ]);
    }

    private function makeVariantProduct(
        Product $parentProduct,
        int $baseQuantity = 1,
        int $price = 1000,
        int $costPrice = 600,
        string $name = '',
    ): Product {
        return Product::create([
            'name' => $name ?: 'Variant '.uniqid(),
            'price' => $price,
            'cost_price' => $costPrice,
            'base_quantity' => $baseQuantity,
            'parent_id' => $parentProduct->id,
        ]);
    }

    private function makeStockEntry(
        Product $product,
        int $quantityLeft,
        int $unitPrice,
        ?Carbon $createdAt = null,
    ): StockEntry {
        $entry = StockEntry::create([
            'product_id' => $product->id,
            'quantity' => $quantityLeft,
            'quantity_left' => $quantityLeft,
            'unit_price' => $unitPrice,
        ]);

        if ($createdAt !== null) {
            $entry->timestamps = false;
            $entry->created_at = $createdAt;
            $entry->save();
        }

        return $entry;
    }

    private function makeActiveCarLoad(): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Team '.uniqid(),
            'user_id' => $user->id,
        ]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    private function makeVenteForProduct(Product $product, int $quantity): Vente
    {
        return Vente::create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'type' => Vente::TYPE_INVOICE,
        ]);
    }
}
