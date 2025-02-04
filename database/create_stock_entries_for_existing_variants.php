<?php
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\StockEntry;
use Illuminate\Support\Facades\DB;
echo "Creating stock entries for existing variants";
$product = Product::whereName("1KG Carton 1000pcs");
$product->update(["base_quantity" => 1000]);
Product::whereName("Pot à Sauce 2000 pcs")->update(["base_quantity" => 2000]);

DB::beginTransaction();
// Create default supplier
if (!Supplier::where('name', 'Fournisseur par défaut')->exists()) {


    $supplier = Supplier::create([
        'name' => 'Fournisseur par défaut',
        'email' => 'default@supplier.com',
        'phone' => '000000000',
        'address' => 'Adresse par défaut'
    ]);
}

// Create virtual purchase invoice
// Check if the invoice already exists
$invoice = PurchaseInvoice::where('comment', 'Facture virtuelle pour les produits variants existants')->first();
if ($invoice == null) {

    $invoice = PurchaseInvoice::create([
        'supplier_id' => $supplier->id,
        'date' => now(),
        "invoice_number" => "INV-".now()->format('YmdHis'),
        "invoice_date" => now(),
        'due_date' => now(),
        'status' => 'completed',
        'is_draft' => false,
        'is_paid' => true,
        'is_stocked' => true,
        'comment' => 'Facture virtuelle pour les produits variants existants'
    ]);
}
// Get all variants with their total sales
$variants = Product::whereNotNull('parent_id')
    ->with('ventes')
    ->get();

foreach ($variants as $variant) {
    // Calculate total quantity sold
    $totalSold = $variant->ventes->sum('quantity');

    // We use parent base_quanity to de
    $quantityToStock = $totalSold ;

    // Create purchase invoice item
    $invoiceItem = PurchaseInvoiceItem::create([
        'purchase_invoice_id' => $invoice->id,
        'product_id' => $variant->id,
        'quantity' => $quantityToStock,
        'unit_price' => $variant->cost_price,
    ]);

    // Create stock entry if not exists
    $variantStockEntry = StockEntry::where('product_id', $variant->id)->first();
    if ($variantStockEntry == null){
       $variantStockEntry = StockEntry::create([
            'product_id' => $variant->id,
            'purchase_invoice_item_id' => $invoiceItem->id,
            'quantity' => $quantityToStock,
            'quantity_left' => $quantityToStock - $totalSold, // Subtract sold quantity
            'unit_price' => $variant->cost_price
        ]);
    }
    dump($totalSold, $variant->name, $quantityToStock);

    if ($totalSold > 0) { // update parent stock entry
        $quantities = $variant->convertQuantityToParentQuantity($totalSold);
        $parentQuantityUsed = $quantities['parent_quantity'];
        $remainingQuantity = $quantities['remaining_variant_quantity'];
        $variantStockEntry->quantity_left = $remainingQuantity;
        $variantStockEntry->save();

        $parentStockEntries = StockEntry::where('product_id', $variant->parent_id)
            ->orderBy("created_at")
            ->get();// Track remaining quantity to deduct from parent entries
        $remainingParentQuantityToDeduct = $parentQuantityUsed;
        foreach ($parentStockEntries as $parentEntry) {
            // Skip if no more quantity to deduct
            if ($remainingParentQuantityToDeduct <= 0) {
                break;
            }

            // Calculate how much we can deduct from this entry
            $quantityToDeduct = min($remainingParentQuantityToDeduct, $parentEntry->quantity_left);

            if ($quantityToDeduct > 0) {
                // Update the parent entry's remaining quantity
                $parentEntry->quantity_left -= $quantityToDeduct;
                $parentEntry->save();

                // Update remaining quantity to deduct
                $remainingParentQuantityToDeduct -= $quantityToDeduct;
            }
        }// If we couldn't deduct all quantity, log a warning
        if ($remainingParentQuantityToDeduct > 0) {
            echo "\nWarning: Could not find enough parent stock for variant {$variant->id}. Missing quantity: {$remainingParentQuantityToDeduct}\n";
        }
    }
    dump("Finished for ".$variant->name);
}
DB::commit();
echo "Stock entries created successfully";
     
   