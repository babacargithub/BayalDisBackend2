<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// SQLite connection
$sqlite = new PDO('sqlite:'.__DIR__.'/database.sqlite');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// MySQL connection (using Laravel's connection)
$mysql = DB::connection()->getPdo();



// Update profits for existing ventes
echo "\nUpdating profits for existing ventes...\n";

try {
    DB::beginTransaction();

    // Get all ventes with their related products
    $ventes = DB::table('ventes')
        ->join('products', 'ventes.product_id', '=', 'products.id')
        ->select('ventes.id', 'ventes.quantity', 'ventes.price', 'products.cost_price')
        ->get();

    foreach ($ventes as $vente) {
        $profit = ($vente->price - $vente->cost_price) * $vente->quantity;
        DB::table('ventes')
            ->where('id', $vente->id)
            ->update(['profit' => $profit]);
        echo ".";
    }

    DB::commit();
    echo "\nProfits updated successfully!\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "\nError updating profits: " . $e->getMessage() . "\n";
} 