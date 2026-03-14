<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a traceable FK from a car_load_item back to the car load it physically
     * rolled over from. Replaces relying on the boolean `from_previous_car_load`
     * for any logic that needs to know the *origin* car load.
     *
     * Backfill logic: every item in CarLoad B that has from_previous_car_load = true
     * should point to CarLoad B's `previous_car_load_id` (= CarLoad A).
     * This is safe because createFromInventory() and createCarLoadFromAnotherPreviousCarLoad()
     * both always set previous_car_load_id on the new car load.
     */
    public function up(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->foreignId('from_previous_car_load_id')
                ->nullable()
                ->after('from_previous_car_load')
                ->constrained('car_loads')
                ->nullOnDelete();
        });

        // Backfill: join car_load_items with their parent car_loads to resolve
        // the origin car load for all existing rolled-over items.
        DB::statement('
            UPDATE car_load_items
            SET from_previous_car_load_id = (
                SELECT previous_car_load_id
                FROM car_loads
                WHERE car_loads.id = car_load_items.car_load_id
            )
            WHERE from_previous_car_load = 1
              AND (
                SELECT previous_car_load_id
                FROM car_loads
                WHERE car_loads.id = car_load_items.car_load_id
              ) IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dropForeign(['from_previous_car_load_id']);
            $table->dropColumn('from_previous_car_load_id');
        });
    }
};
