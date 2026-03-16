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
        Schema::create('commercial_product_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commercials')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // Product-level override — takes precedence over the category-level rate for this commercial.
            $table->decimal('rate', 8, 4)->comment('Commission rate override for this specific product, e.g. 0.0150 = 1.5%');
            $table->unique(['commercial_id', 'product_id'], 'unique_commercial_product_rate');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_product_commission_rates');
    }
};
