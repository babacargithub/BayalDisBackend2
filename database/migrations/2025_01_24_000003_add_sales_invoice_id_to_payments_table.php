<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->onDelete('cascade');
            // Make order_id nullable since payments can now be associated with either orders or invoices
            $table->foreignId('order_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['sales_invoice_id']);
            $table->dropColumn('sales_invoice_id');
            $table->foreignId('order_id')->change();
        });
    }
}; 