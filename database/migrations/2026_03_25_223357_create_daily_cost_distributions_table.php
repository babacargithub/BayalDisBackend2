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
        Schema::create('daily_cost_distributions', function (Blueprint $table): void {
            $table->id();

            // Unique per day — prevents double-distribution.
            $table->date('distribution_date')->unique();

            // Total amount redistributed from MERCHANDISE_SALES to cost accounts on this day.
            $table->unsignedInteger('total_amount_distributed');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_cost_distributions');
    }
};
