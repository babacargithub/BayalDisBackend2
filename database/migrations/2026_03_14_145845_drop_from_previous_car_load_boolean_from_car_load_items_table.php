<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The boolean `from_previous_car_load` column has been superseded by the
     * nullable FK `from_previous_car_load_id` (added in the previous migration).
     * The CarLoadItem model now derives the boolean via an accessor
     * ($this->from_previous_car_load_id !== null), so the column is redundant
     * and is dropped here to avoid future confusion.
     */
    public function up(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dropColumn('from_previous_car_load');
        });
    }

    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->boolean('from_previous_car_load')->default(false)->after('source');
        });
    }
};
