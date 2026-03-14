<?php

use App\Enums\CarLoadItemSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->string('source')
                ->default(CarLoadItemSource::Warehouse->value)
                ->after('from_previous_car_load')
                ->comment('Origin of this item: warehouse (loaded from stock), transformed_from_parent (created by variant transformation inside the car load), from_previous_car_load (carried over from a previous car load inventory)');
        });

        // Backfill: items already flagged as from_previous_car_load get the matching source value
        DB::table('car_load_items')
            ->where('from_previous_car_load', true)
            ->update(['source' => CarLoadItemSource::FromPreviousCarLoad->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
