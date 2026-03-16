<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Total transportation cost declared at the invoice level.
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->unsignedInteger('transportation_cost')
                ->default(0)
                ->after('comment')
                ->comment('Total transportation cost in XOF for this invoice shipment');
        });

        // Allocated share of transportation cost per item line.
        // Populated by PurchaseInvoiceService::distributeTransportationCostToInvoiceItems().
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->unsignedInteger('transportation_cost')
                ->default(0)
                ->after('unit_price')
                ->comment('Allocated transportation cost for this item line (sum across all lines equals invoice transportation_cost)');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn('transportation_cost');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('transportation_cost');
        });
    }
};
