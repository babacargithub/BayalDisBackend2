<?php

namespace Tests\Feature\Product;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\ProductFamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Product families are a display/grouping label only: they must never alter
 * prices, costs, categories (commissions) or stock of the products they group.
 */
class ProductFamilyTest extends TestCase
{
    use RefreshDatabase;

    private ProductFamilyService $productFamilyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productFamilyService = app(ProductFamilyService::class);
    }

    public function test_family_can_be_created_with_a_category(): void
    {
        $disposablesCategory = ProductCategory::create(['name' => 'JETABLES']);

        $this->actingAs(User::factory()->create())
            ->post(route('product-families.store'), [
                'name' => 'Tasse à jeter',
                'product_category_id' => $disposablesCategory->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_families', [
            'name' => 'Tasse à jeter',
            'product_category_id' => $disposablesCategory->id,
        ]);
    }

    public function test_family_can_be_created_without_a_category(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('product-families.store'), ['name' => 'Sans catégorie'])
            ->assertRedirect();

        $this->assertDatabaseHas('product_families', [
            'name' => 'Sans catégorie',
            'product_category_id' => null,
        ]);
    }

    public function test_family_name_is_required_and_unique(): void
    {
        ProductFamily::factory()->create(['name' => 'Tasse à jeter']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('product-families.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->post(route('product-families.store'), ['name' => 'Tasse à jeter'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, ProductFamily::count());
    }

    public function test_family_category_must_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('product-families.store'), ['name' => 'Fantôme', 'product_category_id' => 9999])
            ->assertSessionHasErrors('product_category_id');
    }

    public function test_family_can_be_renamed_and_keep_its_own_name_without_unique_error(): void
    {
        $productFamily = ProductFamily::factory()->create(['name' => 'Tasse à jeter']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('product-families.update', $productFamily), ['name' => 'Tasse à jeter'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->put(route('product-families.update', $productFamily), ['name' => 'Gobelet à jeter'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Gobelet à jeter', $productFamily->fresh()->name);
    }

    public function test_family_cannot_be_renamed_to_the_name_of_another_family(): void
    {
        ProductFamily::factory()->create(['name' => 'Assiette']);
        $productFamily = ProductFamily::factory()->create(['name' => 'Tasse']);

        $this->actingAs(User::factory()->create())
            ->put(route('product-families.update', $productFamily), ['name' => 'Assiette'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Tasse', $productFamily->fresh()->name);
    }

    public function test_bulk_add_puts_all_selected_products_in_the_family_and_leaves_others_alone(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $firstProduct = $this->createProduct('Tasse 15cl');
        $secondProduct = $this->createProduct('Tasse 20cl');
        $thirdProduct = $this->createProduct('Tasse 30cl');
        $untouchedProduct = $this->createProduct('Assiette');

        $this->actingAs(User::factory()->create())
            ->post(route('product-families.add-products', $productFamily), [
                'product_ids' => [$firstProduct->id, $secondProduct->id, $thirdProduct->id],
            ])
            ->assertRedirect();

        $this->assertSame(3, $productFamily->products()->count());
        $this->assertSame($productFamily->id, $firstProduct->fresh()->product_family_id);
        $this->assertSame($productFamily->id, $secondProduct->fresh()->product_family_id);
        $this->assertSame($productFamily->id, $thirdProduct->fresh()->product_family_id);
        $this->assertNull($untouchedProduct->fresh()->product_family_id);
    }

    public function test_bulk_add_with_duplicate_ids_counts_each_product_once(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $product = $this->createProduct('Tasse 15cl');

        $addedProductsCount = $this->productFamilyService->addProductsToFamily(
            $productFamily,
            [$product->id, $product->id, $product->id],
        );

        $this->assertSame(1, $addedProductsCount);
        $this->assertSame(1, $productFamily->products()->count());
    }

    public function test_bulk_add_moves_a_product_from_its_previous_family(): void
    {
        $previousFamily = ProductFamily::factory()->create();
        $newFamily = ProductFamily::factory()->create();
        $product = $this->createProduct('Tasse 15cl', productFamilyId: $previousFamily->id);

        $this->productFamilyService->addProductsToFamily($newFamily, [$product->id]);

        $this->assertSame($newFamily->id, $product->fresh()->product_family_id);
        $this->assertSame(0, $previousFamily->products()->count());
        $this->assertSame(1, $newFamily->products()->count());
    }

    public function test_bulk_add_does_not_change_category_prices_or_stock_data_of_products(): void
    {
        $category = ProductCategory::create(['name' => 'HYGIENE', 'commission_rate' => 0.05]);
        $productFamily = ProductFamily::factory()->create();
        $product = $this->createProduct('Serviette', categoryId: $category->id);
        $originalAttributes = $product->only([
            'product_category_id', 'price', 'credit_price', 'cost_price', 'packaging_cost', 'base_quantity', 'parent_id',
        ]);

        $this->productFamilyService->addProductsToFamily($productFamily, [$product->id]);

        $this->assertSame(
            $originalAttributes,
            $product->fresh()->only([
                'product_category_id', 'price', 'credit_price', 'cost_price', 'packaging_cost', 'base_quantity', 'parent_id',
            ]),
        );
    }

    public function test_bulk_add_requires_at_least_one_existing_product(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('product-families.add-products', $productFamily), ['product_ids' => []])
            ->assertSessionHasErrors('product_ids');

        $this->actingAs($user)
            ->post(route('product-families.add-products', $productFamily), ['product_ids' => [9999]])
            ->assertSessionHasErrors('product_ids.0');

        $this->assertSame(0, $productFamily->products()->count());
    }

    public function test_product_can_be_removed_from_its_family_without_being_deleted(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $product = $this->createProduct('Tasse 15cl', productFamilyId: $productFamily->id);
        $otherProductInFamily = $this->createProduct('Tasse 20cl', productFamilyId: $productFamily->id);

        $this->actingAs(User::factory()->create())
            ->delete(route('product-families.remove-product', [$productFamily, $product]))
            ->assertRedirect();

        $this->assertNull($product->fresh()->product_family_id);
        $this->assertSame($productFamily->id, $otherProductInFamily->fresh()->product_family_id);
    }

    public function test_removing_a_product_from_a_family_it_does_not_belong_to_changes_nothing(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $otherFamily = ProductFamily::factory()->create();
        $product = $this->createProduct('Tasse 15cl', productFamilyId: $otherFamily->id);

        $this->productFamilyService->removeProductFromFamily($productFamily, $product);

        $this->assertSame($otherFamily->id, $product->fresh()->product_family_id);
    }

    public function test_deleting_a_family_keeps_all_its_products_and_makes_them_family_less(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $firstProduct = $this->createProduct('Tasse 15cl', productFamilyId: $productFamily->id);
        $secondProduct = $this->createProduct('Tasse 20cl', productFamilyId: $productFamily->id);
        $productOfAnotherFamily = $this->createProduct('Assiette', productFamilyId: ProductFamily::factory()->create()->id);

        $this->actingAs(User::factory()->create())
            ->delete(route('product-families.destroy', $productFamily))
            ->assertRedirect();

        $this->assertDatabaseMissing('product_families', ['id' => $productFamily->id]);
        $this->assertNull($firstProduct->fresh()->product_family_id);
        $this->assertNull($secondProduct->fresh()->product_family_id);
        $this->assertNotNull($productOfAnotherFamily->fresh()->product_family_id);
        $this->assertSame(3, Product::count());
    }

    public function test_deleting_a_category_keeps_its_families(): void
    {
        $category = ProductCategory::create(['name' => 'JETABLES']);
        $productFamily = ProductFamily::factory()->create(['product_category_id' => $category->id]);

        $category->delete();

        $this->assertNull($productFamily->fresh()->product_category_id);
    }

    public function test_product_without_family_is_still_valid(): void
    {
        $product = $this->createProduct('Produit seul');

        $this->assertNull($product->fresh()->product_family_id);
        $this->assertNull($product->fresh()->family);
    }

    public function test_products_index_exposes_families_with_category_name_and_product_counts(): void
    {
        $disposablesCategory = ProductCategory::create(['name' => 'JETABLES']);
        $cupFamily = ProductFamily::factory()->create(['name' => 'Tasse à jeter', 'product_category_id' => $disposablesCategory->id]);
        $emptyFamily = ProductFamily::factory()->create(['name' => 'Assiette', 'product_category_id' => null]);
        $this->createProduct('Tasse 15cl', productFamilyId: $cupFamily->id);
        $this->createProduct('Tasse 20cl', productFamilyId: $cupFamily->id);
        $this->createProduct('Produit seul');

        $this->actingAs(User::factory()->create())
            ->get(route('produits.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('product_families', 2)
                ->where('product_families.0.id', $emptyFamily->id)
                ->where('product_families.0.category_name', null)
                ->where('product_families.0.products_count', 0)
                ->where('product_families.1.id', $cupFamily->id)
                ->where('product_families.1.category_name', 'JETABLES')
                ->where('product_families.1.products_count', 2));
    }

    public function test_products_index_exposes_the_family_id_of_each_product(): void
    {
        $productFamily = ProductFamily::factory()->create();
        $productInFamily = $this->createProduct('Tasse 15cl', productFamilyId: $productFamily->id);
        $productWithoutFamily = $this->createProduct('Produit seul');

        $this->actingAs(User::factory()->create())
            ->get(route('produits.index'))
            ->assertInertia(function (AssertableInertia $page) use ($productInFamily, $productWithoutFamily, $productFamily) {
                $products = collect($page->toArray()['props']['products'])->keyBy('id');
                $this->assertSame($productFamily->id, $products[$productInFamily->id]['product_family_id']);
                $this->assertNull($products[$productWithoutFamily->id]['product_family_id']);
            });
    }

    private function createProduct(string $name, ?int $categoryId = null, ?int $productFamilyId = null): Product
    {
        return Product::create([
            'name' => $name,
            'price' => 1500,
            'credit_price' => 1700,
            'cost_price' => 1000,
            'packaging_cost' => 25,
            'base_quantity' => 1,
            'product_category_id' => $categoryId,
            'product_family_id' => $productFamilyId,
        ]);
    }
}
