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
        Schema::create('commercial_category_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commercials')->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained('product_categories')->cascadeOnDelete();
            // Rate as a decimal, e.g. 0.0100 represents 1%. Stored with 4 decimal places
            // to support sub-percent rates like 0.5% = 0.0050.
            $table->decimal('rate', 8, 4)->comment('Commission rate, e.g. 0.0100 = 1%');
            $table->unique(['commercial_id', 'product_category_id'], 'unique_commercial_category_rate');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_category_commission_rates');
    }
};
