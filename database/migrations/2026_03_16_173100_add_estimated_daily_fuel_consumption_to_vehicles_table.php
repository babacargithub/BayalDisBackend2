<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('estimated_daily_fuel_consumption')
                ->default(0)
                ->after('working_days_per_month')
                ->comment('Estimated daily fuel cost in XOF');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('estimated_daily_fuel_consumption');
        });
    }
};
