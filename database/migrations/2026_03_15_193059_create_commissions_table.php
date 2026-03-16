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
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commercials')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // --- Commission breakdown (all amounts in XOF) ---

            // Sum of (payment_amount × rate) for every payment line in the period.
            $table->unsignedInteger('base_commission')->default(0)
                ->comment('Sum of (payment_amount × rate) across all payment lines');

            // Bonus earned by selling all required product categories (full basket).
            // = base_commission × (basket_multiplier − 1), or 0 if basket not achieved.
            $table->unsignedInteger('basket_bonus')->default(0)
                ->comment('Bonus from full-basket multiplier achievement');

            // Bonus from the highest achieved objective tier (non-cumulative).
            $table->unsignedInteger('objective_bonus')->default(0)
                ->comment('Flat bonus from the highest achieved objective tier');

            // Sum of all CommercialPenalty amounts for this period.
            $table->unsignedInteger('total_penalties')->default(0)
                ->comment('Total penalty deductions for the period');

            // net_commission = base_commission + basket_bonus + objective_bonus − total_penalties
            $table->unsignedInteger('net_commission')->default(0)
                ->comment('Final payable commission after bonuses and penalties');

            // --- Meta / audit ---

            $table->boolean('basket_achieved')->default(false)
                ->comment('Whether the commercial sold all required categories this period');

            // The multiplier that was actually applied (snapshot of CommissionPeriodSetting at compute time).
            $table->decimal('basket_multiplier_applied', 5, 2)->nullable()
                ->comment('Frozen basket multiplier value at computation time');

            // Which objective tier was achieved (NULL if none).
            $table->unsignedTinyInteger('achieved_tier_level')->nullable()
                ->comment('Highest tier_level achieved; NULL if no tier was reached');

            $table->boolean('is_finalized')->default(false)
                ->comment('Once finalized, the commission is locked and cannot be recomputed');

            $table->timestamp('finalized_at')->nullable();

            $table->unique(['commercial_id', 'period_year', 'period_month'], 'unique_commercial_commission_period');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
