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
        Schema::create('commercial_objective_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commercials')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            // Tier ordering within the same period: tier_level 1 is the lowest threshold,
            // tier_level 2 is the next, etc. Bonuses are NON-CUMULATIVE — only the highest
            // achieved tier applies.
            $table->unsignedTinyInteger('tier_level')
                ->comment('Tier ordering, 1 = lowest threshold. Only the highest achieved tier pays out.');
            // Minimum CA (chiffre d\'affaires / encaissement) the commercial must reach
            // to unlock this tier's bonus.
            $table->unsignedBigInteger('ca_threshold')
                ->comment('Minimum cumulative encaissement in XOF to unlock this tier bonus');
            $table->unsignedInteger('bonus_amount')
                ->comment('Flat bonus in XOF paid when this tier is achieved (not cumulative)');
            $table->unique(
                ['commercial_id', 'period_year', 'period_month', 'tier_level'],
                'unique_commercial_objective_tier'
            );
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_objective_tiers');
    }
};
