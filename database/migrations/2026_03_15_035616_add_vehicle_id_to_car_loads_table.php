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
            $table->foreignId('vehicle_id')->nullable()->after('team_id')->constrained()->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn('vehicle_id');
        });
    }
};
