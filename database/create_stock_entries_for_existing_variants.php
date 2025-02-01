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
            if (!PurchaseInvoice::where('comment', 'Facture virtuelle pour les produits variants existants')->exists()) {
           
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

                // Create stock entry
                StockEntry::create([
                    'product_id' => $variant->id,
                    'purchase_invoice_item_id' => $invoiceItem->id,
                    'quantity' => $quantityToStock,
                    'quantity_left' => $quantityToStock - $totalSold, // Subtract sold quantity
                    'unit_price' => $variant->cost_price
                ]);
            }
        DB::commit();
        echo "Stock entries created successfully";
     
   