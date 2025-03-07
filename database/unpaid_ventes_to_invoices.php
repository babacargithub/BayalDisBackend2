<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Vente;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

// Get all unpaid ventes grouped by customer
$unpaidVentes = Vente::where('paid', false)
    ->with(['customer', 'product'])
    ->where("sales_invoice_id", null)
    ->get()
    ->groupBy('customer_id');

DB::transaction(function () use ($unpaidVentes) {
    foreach ($unpaidVentes as $customerId => $ventes) {
        // Create a new sales invoice for each customer
        $invoice = SalesInvoice::create([
            'customer_id' => $customerId,
            'paid' => false,
            'should_be_paid_at' => $ventes->first()->should_be_paid_at,
            'comment' => 'Facture créée à partir des ventes impayées',
        ]);

        // Create invoice items from ventes
        foreach ($ventes as $vente) {

            // Update the vente to mark it as converted
            $vente->type = 'INVOICE_ITEM';
            $vente->sales_invoice_id = $invoice->id;
            $vente->save();
        }

        echo "Created invoice #{$invoice->id} for customer #{$customerId} with " . count($ventes) . " items\n";
    }
});

echo "\nDone converting unpaid ventes to invoices.\n"; 