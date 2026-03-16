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
        Schema::table('car_loads', function (Blueprint $table) {
            // Snapshot of the vehicle's daily fixed cost rate at the time the vehicle is assigned.
            // Frozen so historical trip cost calculations remain accurate even if vehicle costs change later.
            $table->unsignedInteger('fixed_daily_cost')->nullable()->after('vehicle_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            $table->dropColumn('fixed_daily_cost');
        });
    }
};
