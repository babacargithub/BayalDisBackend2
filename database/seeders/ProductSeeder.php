<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run()
    {
        $products = [
            ['name' => 'Boîte à emporter 500ml', 'price' => 1500],
            ['name' => 'Boîte à emporter 750ml', 'price' => 2000],
            ['name' => 'Boîte à emporter 1L', 'price' => 2500],
            ['name' => 'Sac plastique petit', 'price' => 500],
            ['name' => 'Sac plastique moyen', 'price' => 750],
            ['name' => 'Sac plastique grand', 'price' => 1000],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
} 