<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table("stock_entries", function (Blueprint $table) {
            $table->unsignedBigInteger("purchase_invoice_item_id")->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table("stock_entries", function (Blueprint $table) {
            $table->unsignedBigInteger("purchase_invoice_item_id")->nullable(false)->change();
        });
    }
}; 