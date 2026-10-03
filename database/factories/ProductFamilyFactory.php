<?php

namespace Database\Factories;

use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductFamily>
 */
class ProductFamilyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Famille '.fake()->unique()->word(),
            'product_category_id' => null,
        ];
    }
}
