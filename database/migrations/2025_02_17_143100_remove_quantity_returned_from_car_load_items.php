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
            // if the column exists, drop it
            if (Schema::hasColumn('car_load_items', 'quantity_returned')) {
                $table->dropColumn('quantity_returned');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            if (!Schema::hasColumn('car_load_items', 'quantity_returned')) {
                $table->integer('quantity_returned')->default(0)->after('quantity_loaded');
            }
        });
    }
}; 