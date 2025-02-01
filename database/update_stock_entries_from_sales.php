<?php
// autoload the laravel app
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;
// boot the laravel app


        // Get all products
        $products = Product::all();

        foreach ($products as $product) {
            // Get total quantity sold for this product
            $totalSold = Vente::where('product_id', $product->id)->sum('quantity');

            if ($totalSold > 0) {
                // Get all stock entries for this product ordered by creation date (FIFO)
                $stockEntries = StockEntry::where('product_id', $product->id)
                    ->orderBy('created_at')
                    ->get();

                $remainingToDeduct = $totalSold;

                foreach ($stockEntries as $entry) {
                    if ($remainingToDeduct <= 0) break;

                    // Calculate how much we can deduct from this entry
                    $deductFromThis = min($remainingToDeduct, $entry->quantity);
                    
                    // Update the quantity_left
                    $entry->quantity_left = $entry->quantity - $deductFromThis;
                    $entry->save();

                    // Update remaining quantity to deduct
                    $remainingToDeduct -= $deductFromThis;
                }
            }
        }
   