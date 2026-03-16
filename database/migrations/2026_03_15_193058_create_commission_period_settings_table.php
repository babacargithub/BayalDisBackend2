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
        Schema::create('commission_period_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            // Multiplier applied to base commission when the commercial achieves a "full basket"
            // (i.e., sells at least one product from every required category).
            // e.g. 1.30 = 30% bonus on top of base commission.
            $table->decimal('basket_multiplier', 5, 2)->default(1.30)
                ->comment('Multiplier applied when all required categories are sold, e.g. 1.30 = +30%');
            // JSON array of product_category IDs that must ALL be present to qualify for the basket bonus.
            $table->json('required_category_ids')
                ->comment('Product category IDs that must all appear in sales to earn the basket multiplier');
            $table->unique(['period_year', 'period_month'], 'unique_commission_period');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_period_settings');
    }
};
