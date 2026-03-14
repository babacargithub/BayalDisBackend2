<?php

namespace Tests\Feature;

use App\Enums\CarLoadItemSource;
use App\Enums\CarLoadStatus;
use App\Models\CarLoad;
use App\Models\CarLoadItem;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\CarLoadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarLoadTransformToVariantsTest extends TestCase
{
    use RefreshDatabase;

    private CarLoadService $carLoadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carLoadService = app(CarLoadService::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeSellingCarLoad(): CarLoad
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'Team '.uniqid(), 'user_id' => $user->id]);

        return CarLoad::create([
            'name' => 'CarLoad '.uniqid(),
            'team_id' => $team->id,
            'status' => CarLoadStatus::Selling,
            'load_date' => Carbon::now()->subDay(),
            'return_date' => Carbon::now()->addDay(),
            'returned' => false,
        ]);
    }

    /**
     * Makes a parent product (e.g. a carton) and one or more variants (e.g. individual packs).
     * base_quantity on the parent means "how many smallest units fit in one carton".
     * base_quantity on the variant means "how many smallest units fit in one pack".
     *
     * @return array{parent: Product, variants: Product[]}
     */
    private function makeParentWithVariants(int $numberOfVariants = 1): array
    {
        $parent = Product::create([
            'name' => 'Carton 1KG',
            'price' => 12_000,
            'cost_price' => 9_000,
            'base_quantity' => 1000, // 1 carton = 1000 base units
            'stock_available' => 0,
        ]);

        $variants = [];
        for ($index = 0; $index < $numberOfVariants; $index++) {
            $variants[] = Product::create([
                'name' => '1KG Pack '.($index + 1),
                'price' => 700,
                'cost_price' => 500,
                'parent_id' => $parent->id,
                'base_quantity' => 20, // 1 pack = 20 base units → 50 packs per carton
                'stock_available' => 0,
            ]);
        }

        return ['parent' => $parent, 'variants' => $variants];
    }

    private function loadParentIntoCarLoad(CarLoad $carLoad, Product $parent, int $quantityLoaded, int $quantityLeft): CarLoadItem
    {
        return CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $parent->id,
            'quantity_loaded' => $quantityLoaded,
            'quantity_left' => $quantityLeft,
            'loaded_at' => now(),
            'source' => CarLoadItemSource::Warehouse,
        ]);
    }

    private function buildTransformData(Product $parent, array $variants, int $cartonsToTransform): array
    {
        // Each carton has base_quantity 1000, each pack has base_quantity 20 → 50 packs per carton
        $packsPerCarton = $parent->base_quantity / $variants[0]->base_quantity;

        return [
            'quantityOfBaseProductToTransform' => $cartonsToTransform,
            'items' => array_map(fn (Product $variant) => [
                'product_id' => $variant->id,
                'quantity' => $cartonsToTransform * $packsPerCarton,
                'unused_quantity' => 0,
            ], $variants),
        ];
    }

    // ─── Happy path ──────────────────────────────────────────────────────────────

    public function test_transform_decreases_parent_stock_and_creates_variant_items(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);
        $variant = $variants[0];

        $this->loadParentIntoCarLoad($carLoad, $parent, 10, 10);

        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, [$variant], 2), $carLoad);

        // Parent stock decreased by 2
        $this->assertEquals(8, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $parent));

        // Variant item created with correct quantities (2 cartons × 50 packs = 100)
        $variantItem = $carLoad->items()->where('product_id', $variant->id)->first();
        $this->assertNotNull($variantItem);
        $this->assertEquals(100, $variantItem->quantity_loaded);
        $this->assertEquals(100, $variantItem->quantity_left);
    }

    public function test_transformed_item_has_source_transformed_from_parent(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 1), $carLoad);

        $variantItem = $carLoad->items()->where('product_id', $variants[0]->id)->first();
        $this->assertEquals(CarLoadItemSource::TransformedFromParent, $variantItem->source);
    }

    public function test_transform_uses_fifo_to_consume_parent_stock_across_multiple_batches(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        $olderBatch = $this->loadParentIntoCarLoad($carLoad, $parent, 3, 3);
        // Add second batch 1 hour later
        $newerBatch = CarLoadItem::create([
            'car_load_id' => $carLoad->id,
            'product_id' => $parent->id,
            'quantity_loaded' => 5,
            'quantity_left' => 5,
            'loaded_at' => now()->addHour(),
            'source' => CarLoadItemSource::Warehouse,
        ]);

        // Transform 4 cartons — should exhaust older batch (3) and consume 1 from newer
        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 4), $carLoad);

        $this->assertEquals(0, $olderBatch->fresh()->quantity_left);
        $this->assertEquals(4, $newerBatch->fresh()->quantity_left);
    }

    public function test_transform_with_multiple_variants_creates_one_item_per_variant(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(2);

        $this->loadParentIntoCarLoad($carLoad, $parent, 10, 10);

        $data = [
            'quantityOfBaseProductToTransform' => 2,
            'items' => [
                ['product_id' => $variants[0]->id, 'quantity' => 60, 'unused_quantity' => 10], // 60 - 10 = 50 actual
                ['product_id' => $variants[1]->id, 'quantity' => 40, 'unused_quantity' => 0],  // 40 actual
            ],
        ];

        $this->carLoadService->transformToVariants($parent, $data, $carLoad);

        $this->assertEquals(
            50,
            $carLoad->items()->where('product_id', $variants[0]->id)->value('quantity_loaded')
        );
        $this->assertEquals(
            40,
            $carLoad->items()->where('product_id', $variants[1]->id)->value('quantity_loaded')
        );
    }

    public function test_variant_item_with_full_unused_quantity_is_skipped(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $data = [
            'quantityOfBaseProductToTransform' => 1,
            'items' => [
                // quantity == unused_quantity → actualQuantity = 0 → must be skipped
                ['product_id' => $variants[0]->id, 'quantity' => 50, 'unused_quantity' => 50],
            ],
        ];

        $this->carLoadService->transformToVariants($parent, $data, $carLoad);

        // No variant item should have been created
        $this->assertEquals(0, $carLoad->items()->where('product_id', $variants[0]->id)->count());
        // Parent stock still decreased (1 carton consumed even though output was 0 — edge case)
        $this->assertEquals(4, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $parent));
    }

    public function test_transform_comment_references_parent_product_name(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 1), $carLoad);

        $variantItem = $carLoad->items()->where('product_id', $variants[0]->id)->first();
        $this->assertStringContainsString($parent->name, $variantItem->comment);
    }

    // ─── Failure cases ────────────────────────────────────────────────────────────

    public function test_throws_exception_when_parent_product_is_not_in_car_load(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        // Parent intentionally NOT loaded into the car load

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/pas dans votre chargement/');

        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 1), $carLoad);
    }

    public function test_throws_insufficient_stock_exception_when_parent_stock_is_too_low(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        $this->loadParentIntoCarLoad($carLoad, $parent, 3, 3); // only 3 available

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Stock insuffisant/');

        $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 5), $carLoad); // requests 5
    }

    public function test_throws_exception_when_variant_does_not_belong_to_parent(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent] = $this->makeParentWithVariants(1);

        $unrelatedProduct = Product::create([
            'name' => 'Unrelated Product',
            'price' => 500,
            'cost_price' => 300,
            'stock_available' => 0,
        ]);

        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $data = [
            'quantityOfBaseProductToTransform' => 1,
            'items' => [
                ['product_id' => $unrelatedProduct->id, 'quantity' => 50, 'unused_quantity' => 0],
            ],
        ];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/n\'est pas un variant/');

        $this->carLoadService->transformToVariants($parent, $data, $carLoad);
    }

    public function test_parent_stock_is_not_decreased_when_product_not_in_car_load(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);

        // Load parent into a DIFFERENT car load — not in $carLoad
        $otherCarLoad = $this->makeSellingCarLoad();
        $this->loadParentIntoCarLoad($otherCarLoad, $parent, 10, 10);

        try {
            $this->carLoadService->transformToVariants($parent, $this->buildTransformData($parent, $variants, 1), $carLoad);
        } catch (\Exception) {
            // Expected — verify other car load stock is untouched
        }

        $this->assertEquals(10, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($otherCarLoad, $parent));
    }

    public function test_transform_is_atomic_invalid_variant_rolls_back_parent_stock_decrease(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);
        $unrelatedProduct = Product::create([
            'name' => 'Bad Variant',
            'price' => 500,
            'cost_price' => 300,
            'stock_available' => 0,
        ]);

        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $data = [
            'quantityOfBaseProductToTransform' => 1,
            'items' => [
                ['product_id' => $variants[0]->id, 'quantity' => 50, 'unused_quantity' => 0],
                ['product_id' => $unrelatedProduct->id, 'quantity' => 10, 'unused_quantity' => 0], // invalid — triggers rollback
            ],
        ];

        try {
            $this->carLoadService->transformToVariants($parent, $data, $carLoad);
        } catch (\Exception) {
            // Expected
        }

        // Transaction rolled back — parent stock must be unchanged
        $this->assertEquals(5, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $parent));
        // No variant items created
        $this->assertEquals(0, $carLoad->items()->where('product_id', $variants[0]->id)->count());
    }

    // ─── Negative / zero quantity guards ─────────────────────────────────────────

    public function test_negative_quantity_to_transform_is_rejected(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);
        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/supérieure à zéro/');

        $this->carLoadService->transformToVariants($parent, [
            'quantityOfBaseProductToTransform' => -1,
            'items' => [['product_id' => $variants[0]->id, 'quantity' => 50, 'unused_quantity' => 0]],
        ], $carLoad);
    }

    public function test_zero_quantity_to_transform_is_rejected(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);
        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/supérieure à zéro/');

        $this->carLoadService->transformToVariants($parent, [
            'quantityOfBaseProductToTransform' => 0,
            'items' => [['product_id' => $variants[0]->id, 'quantity' => 0, 'unused_quantity' => 0]],
        ], $carLoad);
    }

    public function test_negative_quantity_does_not_modify_parent_stock(): void
    {
        $carLoad = $this->makeSellingCarLoad();
        ['parent' => $parent, 'variants' => $variants] = $this->makeParentWithVariants(1);
        $this->loadParentIntoCarLoad($carLoad, $parent, 5, 5);

        try {
            $this->carLoadService->transformToVariants($parent, [
                'quantityOfBaseProductToTransform' => -3,
                'items' => [['product_id' => $variants[0]->id, 'quantity' => 50, 'unused_quantity' => 0]],
            ], $carLoad);
        } catch (\Exception) {
            // Expected
        }

        // Negative quantity must never increase or decrease parent stock
        $this->assertEquals(5, $this->carLoadService->getTotalQuantityLeftOfProductInCarLoad($carLoad, $parent));
    }
}
