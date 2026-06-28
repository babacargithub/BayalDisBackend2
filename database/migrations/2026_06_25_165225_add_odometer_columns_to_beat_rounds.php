<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beat_rounds', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('commercial_id')->constrained('vehicles')->nullOnDelete();
            $table->unsignedInteger('odometer_start_km')->nullable()->after('vehicle_id');
            $table->unsignedInteger('odometer_end_km')->nullable()->after('odometer_start_km');
        });
    }

    public function down(): void
    {
        Schema::table('beat_rounds', function (Blueprint $table): void {
            $table->dropForeign(['vehicle_id']);
            $table->dropColumn(['vehicle_id', 'odometer_start_km', 'odometer_end_km']);
        });
    }
};
