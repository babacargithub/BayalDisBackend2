<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Default packaging cost per unit for this product.
            // 0 = no packaging cost (product is pre-packaged or packaging is negligible).
            // When a purchase invoice is put in stock, this default is pre-filled on the
            // StockEntry.packaging_cost field and can be overridden per batch if needed.
            $table->unsignedInteger('packaging_cost')->default(0)->after('cost_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('packaging_cost');
        });
    }
};
