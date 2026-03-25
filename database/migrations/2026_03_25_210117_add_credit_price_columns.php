<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Higher price applied when the customer pays on credit (deferred payment).
            // Null means no credit price is defined for this product.
            $table->unsignedBigInteger('credit_price')->nullable()->after('price');
        });

        Schema::table('pricing_policies', function (Blueprint $table) {
            // When true, unpaid invoices will use each product's credit_price instead of the normal price.
            $table->boolean('apply_credit_price')->default(false)->after('apply_to_deferred_only');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            // The total difference between credit prices and normal prices across all items.
            // Set at invoice creation time when apply_credit_price is active. Zero otherwise.
            $table->unsignedBigInteger('credit_price_difference')->default(0)->after('total_realized_profit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('credit_price');
        });

        Schema::table('pricing_policies', function (Blueprint $table) {
            $table->dropColumn('apply_credit_price');
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn('credit_price_difference');
        });
    }
};
