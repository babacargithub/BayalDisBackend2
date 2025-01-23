<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->onDelete('cascade');
            // Make commercial_id nullable since it will come from the invoice
            $table->foreignId('commercial_id')->nullable()->change();
            // customer_id should be required for SINGLE ventes but nullable for INVOICE_ITEM ventes
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->dropForeign(['sales_invoice_id']);
            $table->dropColumn('sales_invoice_id');
            $table->foreignId('commercial_id')->change();
            $table->foreignId('customer_id')->change();
        });
    }
}; 