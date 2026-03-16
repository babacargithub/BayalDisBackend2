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
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->unsignedInteger('cost_price_per_unit')
                ->nullable()
                ->after('quantity_left')
                ->comment('FIFO cost locked at load time (unit_price + transportation_cost + packaging_cost from StockEntry). Null for legacy items. For TransformedFromParent items: (parent_cost / base_quantity_ratio) + variant.packaging_cost.');
        });
    }

    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dropColumn('cost_price_per_unit');
        });
    }
};
