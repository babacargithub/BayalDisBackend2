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
        Schema::create('monthly_fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->string('cost_pool');
            $table->string('sub_category');
            $table->unsignedInteger('amount');
            $table->string('label')->nullable()->comment('Free-text description of the cost entry, e.g. "Loyer Mensuel", "Assurance risque incendie"');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedInteger('per_vehicle_amount')->nullable();
            $table->unsignedSmallInteger('active_vehicle_count')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['period_year', 'period_month', 'cost_pool']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_fixed_costs');
    }
};
