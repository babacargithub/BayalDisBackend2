<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ProductService::getFormattedDisplayOfCartonAndPaquets
 *
 * This method converts a decimal parent-unit quantity into a
 * human-readable {cartons, paquets} breakdown.
 *
 * Critical edge case: negative quantities (inventory deficits) must
 * produce the correct absolute magnitude, not a floor-division artefact.
 */
class ProductServiceFormattedDisplayTest extends TestCase
{
    use RefreshDatabase;

    private ProductService $productService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->productService = app(ProductService::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a parent product whose base_quantity represents its total units
     * (e.g. 1000 for a carton of 1000 pcs), with one variant product.
     */
    private function makeParentProductWithVariant(
        int $parentBaseQuantity,
        int $variantBaseQuantity,
        string $parentName = 'Carton',
        string $variantName = 'Paquet'
    ): Product {
        $parent = Product::create([
            'name' => $parentName,
            'price' => 35000,
            'cost_price' => 28650,
            'base_quantity' => $parentBaseQuantity,
        ]);

        Product::create([
            'name' => $variantName,
            'price' => 700,
            'cost_price' => 573,
            'base_quantity' => $variantBaseQuantity,
            'parent_id' => $parent->id,
        ]);

        return $parent;
    }

    // -------------------------------------------------------------------------
    // Positive quantity — baseline correctness
    // -------------------------------------------------------------------------

    public function test_positive_whole_carton_quantity_returns_correct_cartons_and_zero_paquets(): void
    {
        // 1KG carton: 1 000 pcs/carton, variant = 20 pcs/paquet → 50 paquets/carton
        $parentProduct = $this->makeParentProductWithVariant(parentBaseQuantity: 1000, variantBaseQuantity: 20);

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 3.0);

        $this->assertSame(3, $result['cartons']);
        $this->assertSame(0, $result['paquets']);
    }

    public function test_positive_mixed_quantity_returns_correct_cartons_and_paquets(): void
    {
        // 38 cartons + 23 paquets → 38 + 23/50 = 38.46
        $parentProduct = $this->makeParentProductWithVariant(parentBaseQuantity: 1000, variantBaseQuantity: 20);

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 38.46);

        $this->assertSame(38, $result['cartons']);
        $this->assertSame(23, $result['paquets']);
    }

    // -------------------------------------------------------------------------
    // Negative quantity — the bug under test
    // -------------------------------------------------------------------------

    /**
     * Real-world case from CarLoad #12:
     * 1KG Carton (1 000 pcs), variant = 1KG Paquet 20 pcs → 50 paquets/carton.
     * resultDecimal = -10/50 = -0.2  (10 paquets short, less than one carton).
     *
     * Bug:  floor(-0.2) = -1, decimal part = -0.2 − (−1) = 0.8 → 40 paquets ❌
     * Fix:  use abs() before extracting decimal → 0.2 → 10 paquets            ✓
     */
    public function test_negative_sub_carton_deficit_of_10_paquets_returns_0_cartons_and_10_paquets(): void
    {
        // 50 paquets per carton (1000/20)
        $parentProduct = $this->makeParentProductWithVariant(parentBaseQuantity: 1000, variantBaseQuantity: 20);

        // −10 paquets expressed as decimal parent units: −10/50 = −0.2
        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, -0.2);

        $this->assertSame(0, $result['cartons'],
            'Should show 0 cartons for a sub-carton deficit');
        $this->assertSame(10, $result['paquets'],
            'Should show 10 paquets missing, not 40 (floor-division artefact)');
    }

    /**
     * Pot à Sauce 2 000 pcs, variant = Pot à Sauce 100 pcs → 20 paquets/carton.
     * resultDecimal = -1/20 = -0.05  (1 paquet short).
     *
     * Bug:  floor(-0.05) = -1, decimal = 0.95 → 19 paquets ❌
     * Fix:  abs(-0.05) = 0.05 → 1 paquet               ✓
     */
    public function test_negative_sub_carton_deficit_of_1_paquet_pot_a_sauce_returns_0_cartons_and_1_paquet(): void
    {
        // 20 paquets per carton (2000/100)
        $parentProduct = $this->makeParentProductWithVariant(
            parentBaseQuantity: 2000,
            variantBaseQuantity: 100,
            parentName: 'Pot à Sauce 2000pcs',
            variantName: 'Pot à Sauce 100pcs'
        );

        // −1 paquet as decimal parent units: −1/20 = −0.05
        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, -0.05);

        $this->assertSame(0, $result['cartons'],
            'Should show 0 cartons for a sub-carton deficit');
        $this->assertSame(1, $result['paquets'],
            'Should show 1 paquet missing, not 19 (floor-division artefact)');
    }

    /**
     * Transparent 1 000ml carton 500 pcs, variant = 10 pcs → 50 paquets/carton.
     * resultDecimal = -1/50 = -0.02  (1 paquet short).
     *
     * Bug:  floor(-0.02) = -1, decimal = 0.98 → 49 paquets ❌
     * Fix:  abs(-0.02) = 0.02 → 1 paquet                ✓
     */
    public function test_negative_sub_carton_deficit_of_1_paquet_transparent_returns_0_cartons_and_1_paquet(): void
    {
        // 50 paquets per carton (500/10)
        $parentProduct = $this->makeParentProductWithVariant(
            parentBaseQuantity: 500,
            variantBaseQuantity: 10,
            parentName: 'Transparent 1000ml 500pcs',
            variantName: 'Transparent 1000ml 10pcs'
        );

        // −1 paquet as decimal parent units: −1/50 = −0.02
        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, -0.02);

        $this->assertSame(0, $result['cartons'],
            'Should show 0 cartons for a sub-carton deficit');
        $this->assertSame(1, $result['paquets'],
            'Should show 1 paquet missing, not 49 (floor-division artefact)');
    }

    /**
     * Deficit spanning more than one full carton: −1.2 should display as 1 carton + 10 paquets.
     */
    public function test_negative_deficit_spanning_one_full_carton_returns_1_carton_and_10_paquets(): void
    {
        // 50 paquets per carton (1000/20)
        $parentProduct = $this->makeParentProductWithVariant(parentBaseQuantity: 1000, variantBaseQuantity: 20);

        // −1.2 = −(1 carton + 10 paquets)
        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, -1.2);

        $this->assertSame(1, $result['cartons'],
            'Should show 1 carton for a deficit of 1.2 cartons');
        $this->assertSame(10, $result['paquets'],
            'Should show 10 paquets for the 0.2 fractional part of −1.2');
    }

    /**
     * Zero quantity should return zeroes for both cartons and paquets.
     */
    public function test_zero_quantity_returns_zero_cartons_and_zero_paquets(): void
    {
        $parentProduct = $this->makeParentProductWithVariant(parentBaseQuantity: 1000, variantBaseQuantity: 20);

        $result = $this->productService->getFormattedDisplayOfCartonAndPaquets($parentProduct, 0.0);

        $this->assertSame(0, $result['cartons']);
        $this->assertSame(0, $result['paquets']);
    }
}
