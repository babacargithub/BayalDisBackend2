<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run()
    {

            $products = [
                [
                    'id' => 1,
                    'name' => '1KG Carton 1000pcs',
                    'price' => 36000.00,
                    'created_at' => '2025-01-03 23:49:35',
                    'updated_at' => '2025-11-03 15:58:20',
                    'cost_price' => 29000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 3,
                    'name' => '1KG paquet 20pcs',
                    'price' => 800.00,
                    'created_at' => '2025-01-03 23:49:35',
                    'updated_at' => '2025-11-03 15:59:01',
                    'cost_price' => 580,
                    'parent_id' => 1,
                    'base_quantity' => 20
                ],
                [
                    'id' => 7,
                    'name' => '2KG paquet 10pcs',
                    'price' => 1000.00,
                    'created_at' => '2025-01-18 11:21:27',
                    'updated_at' => '2025-02-01 10:55:22',
                    'cost_price' => 675,
                    'parent_id' => 17,
                    'base_quantity' => 10
                ],
                [
                    'id' => 8,
                    'name' => 'Sachet 15F',
                    'price' => 500.00,
                    'created_at' => '2025-01-21 22:35:06',
                    'updated_at' => '2025-01-21 22:35:06',
                    'cost_price' => 250,
                    'parent_id' => null,
                    'base_quantity' => 0
                ],
                [
                    'id' => 9,
                    'name' => '2 compartiments GM 5pcs',
                    'price' => 1000.00,
                    'created_at' => '2025-01-21 22:37:55',
                    'updated_at' => '2025-02-01 10:55:46',
                    'cost_price' => 600,
                    'parent_id' => 20,
                    'base_quantity' => 5
                ],
                [
                    'id' => 10,
                    'name' => '500g - 20pcs',
                    'price' => 700.00,
                    'created_at' => '2025-01-21 22:40:49',
                    'updated_at' => '2025-02-23 00:28:55',
                    'cost_price' => 600,
                    'parent_id' => 18,
                    'base_quantity' => 20
                ],
                [
                    'id' => 11,
                    'name' => 'Pot à Sauce 2000 pcs',
                    'price' => 30000.00,
                    'created_at' => '2025-01-21 22:41:47',
                    'updated_at' => '2025-02-04 15:51:11',
                    'cost_price' => 18000,
                    'parent_id' => null,
                    'base_quantity' => 2000
                ],
                [
                    'id' => 12,
                    'name' => 'Pm 2 compartiments 5 pcs',
                    'price' => 1000.00,
                    'created_at' => '2025-01-21 22:42:30',
                    'updated_at' => '2025-02-01 10:57:52',
                    'cost_price' => 600,
                    'parent_id' => 20,
                    'base_quantity' => 5
                ],
                [
                    'id' => 13,
                    'name' => 'Transparent 1000ml 10pcs',
                    'price' => 1250.00,
                    'created_at' => '2025-01-22 14:16:53',
                    'updated_at' => '2025-02-01 10:56:15',
                    'cost_price' => 850,
                    'parent_id' => 21,
                    'base_quantity' => 10
                ],
                [
                    'id' => 14,
                    'name' => 'Transparent 1000ml 5pcs',
                    'price' => 625.00,
                    'created_at' => '2025-01-22 14:17:34',
                    'updated_at' => '2025-02-01 10:56:38',
                    'cost_price' => 450,
                    'parent_id' => 21,
                    'base_quantity' => 5
                ],
                [
                    'id' => 15,
                    'name' => 'Pot à sauce 100 pcs',
                    'price' => 1250.00,
                    'created_at' => '2025-01-22 16:28:00',
                    'updated_at' => '2025-02-01 10:57:01',
                    'cost_price' => 900,
                    'parent_id' => 11,
                    'base_quantity' => 100
                ],
                [
                    'id' => 16,
                    'name' => 'Gobelet paquet 50pcs',
                    'price' => 2000.00,
                    'created_at' => '2025-01-29 13:30:25',
                    'updated_at' => '2025-02-01 10:57:16',
                    'cost_price' => 1400,
                    'parent_id' => 19,
                    'base_quantity' => 50
                ],
                [
                    'id' => 17,
                    'name' => '2KG carton 400pcs',
                    'price' => 36000.00,
                    'created_at' => '2025-02-01 10:46:16',
                    'updated_at' => '2025-02-01 10:46:16',
                    'cost_price' => 27000,
                    'parent_id' => null,
                    'base_quantity' => 400
                ],
                [
                    'id' => 18,
                    'name' => '500g carton 1000pcs',
                    'price' => 36000.00,
                    'created_at' => '2025-02-01 10:46:49',
                    'updated_at' => '2025-02-23 00:28:12',
                    'cost_price' => 30000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 19,
                    'name' => 'Gobelet carton 1000 pcs',
                    'price' => 32000.00,
                    'created_at' => '2025-02-01 10:47:24',
                    'updated_at' => '2025-02-01 10:47:24',
                    'cost_price' => 28000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 20,
                    'name' => '2 Compart carton 250 pcs',
                    'price' => 35000.00,
                    'created_at' => '2025-02-01 10:48:06',
                    'updated_at' => '2025-02-01 10:48:06',
                    'cost_price' => 30000,
                    'parent_id' => null,
                    'base_quantity' => 250
                ],
                [
                    'id' => 21,
                    'name' => 'Transparent 1000ml carton 500pcs',
                    'price' => 47000.00,
                    'created_at' => '2025-02-01 10:48:52',
                    'updated_at' => '2025-02-01 10:48:52',
                    'cost_price' => 43000,
                    'parent_id' => null,
                    'base_quantity' => 500
                ],
                [
                    'id' => 22,
                    'name' => 'Transparent ml 250 rond',
                    'price' => 36000.00,
                    'created_at' => '2025-02-05 14:34:13',
                    'updated_at' => '2025-02-05 14:34:13',
                    'cost_price' => 32000,
                    'parent_id' => null,
                    'base_quantity' => 500
                ],
                [
                    'id' => 23,
                    'name' => 'Tasse A Jeter 4oz carton',
                    'price' => 7500.00,
                    'created_at' => '2025-02-12 00:43:51',
                    'updated_at' => '2025-02-12 00:44:15',
                    'cost_price' => 6000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 24,
                    'name' => 'Tasse A jeter 3oz carton',
                    'price' => 7500.00,
                    'created_at' => '2025-02-12 00:45:05',
                    'updated_at' => '2025-02-12 00:45:05',
                    'cost_price' => 6000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 25,
                    'name' => 'Tasse A jeter 2.5oz',
                    'price' => 7500.00,
                    'created_at' => '2025-02-12 00:46:03',
                    'updated_at' => '2025-02-12 00:46:03',
                    'cost_price' => 6000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 26,
                    'name' => 'Tasse A Jeter 5oz carton',
                    'price' => 8000.00,
                    'created_at' => '2025-02-12 00:48:54',
                    'updated_at' => '2025-02-12 11:41:22',
                    'cost_price' => 7000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 27,
                    'name' => 'Tasse A Jeter 6oz carton',
                    'price' => 8000.00,
                    'created_at' => '2025-02-12 00:49:25',
                    'updated_at' => '2025-02-12 00:49:25',
                    'cost_price' => 7000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 28,
                    'name' => 'Gobelet Bouchon Oeuf 1000 pcs',
                    'price' => 40000.00,
                    'created_at' => '2025-04-29 18:38:12',
                    'updated_at' => '2025-04-29 18:38:12',
                    'cost_price' => 33000,
                    'parent_id' => null,
                    'base_quantity' => 1000
                ],
                [
                    'id' => 29,
                    'name' => 'Pot à Sauce 50pcs',
                    'price' => 700.00,
                    'created_at' => '2025-05-31 19:54:20',
                    'updated_at' => '2025-05-31 19:54:20',
                    'cost_price' => 500,
                    'parent_id' => 11,
                    'base_quantity' => 50
                ],
                [
                    'id' => 30,
                    'name' => 'Carton Papier Falcon 1000G/6',
                    'price' => 35000.00,
                    'created_at' => '2025-07-31 13:02:15',
                    'updated_at' => '2025-11-08 18:19:15',
                    'cost_price' => 25000,
                    'parent_id' => null,
                    'base_quantity' => 6
                ],
                [
                    'id' => 31,
                    'name' => 'Papier Falcon rouleau',
                    'price' => 5750.00,
                    'created_at' => '2025-07-31 13:05:07',
                    'updated_at' => '2025-07-31 13:05:52',
                    'cost_price' => 4166,
                    'parent_id' => 30,
                    'base_quantity' => 1
                ]
            ];
            $parent_products = array_filter($products, function ($product) {
                return $product['parent_id'] === null;
            });
            $child_products = array_filter($products, function ($product) {
                return $product['parent_id'] !== null;
            });
            Product::insert($parent_products);
            Product::insert($child_products);

    }
}