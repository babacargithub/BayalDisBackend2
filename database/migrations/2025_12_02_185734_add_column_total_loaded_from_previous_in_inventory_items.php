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
        Schema::table('car_load_inventory_items', function (Blueprint $table) {
            if (!Schema::hasColumn('car_load_inventory_items', 'total_loaded_from_previous')) {
                $table->float('total_loaded_from_previous')->default(0.00);
            }

        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_load_inventory_items', function (Blueprint $table) {
            //
            if (Schema::hasColumn('car_load_inventory_items', 'total_loaded_from_previous')) {
                $table->dropColumn('total_loaded_from_previous');
            }
        });
    }
};
