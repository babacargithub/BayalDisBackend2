<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {

            // check if the column does not exist
            if (!Schema::hasColumn('car_load_items', 'loaded_at')) {
                // Add new team_id column
                $table->date('loaded_at')->nullable();
            }
            if (!Schema::hasColumn('car_load_items', 'quantity_left')) {
                // Add new team_id column
                $table->integer('quantity_left')->default(0);
            }

        });
    }

    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            // check if the column exists
            if (Schema::hasColumn('car_load_items', 'loaded_at')) {
                $table->dropColumn('loaded_at');
            }
            if (Schema::hasColumn('car_load_items', 'quantity_left')) {
                $table->dropColumn('quantity_left');
            }
        });
    }
}; 