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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plate_number')->nullable();
            $table->unsignedInteger('insurance_monthly')->default(0);
            $table->unsignedInteger('maintenance_monthly')->default(0);
            $table->unsignedInteger('repair_reserve_monthly')->default(0);
            $table->unsignedInteger('depreciation_monthly')->default(0);
            $table->unsignedInteger('driver_salary_monthly')->default(0);
            $table->unsignedSmallInteger('working_days_per_month')->default(26);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
