<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Introduces the commercial_work_periods table as the single owner of
 * (commercial_id, period_start_date, period_end_date), and replaces the
 * denormalized triplet on commissions, commercial_objective_tiers, and
 * commercial_penalties with a commercial_work_period_id FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Create the canonical work-period table.
        Schema::create('commercial_work_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commercial_id')->constrained('commercials')->cascadeOnDelete();
            $table->date('period_start_date')
                ->comment('Inclusive start of the work period (typically a Monday)');
            $table->date('period_end_date')
                ->comment('Inclusive end of the work period (typically a Saturday)');
            $table->unique(
                ['commercial_id', 'period_start_date', 'period_end_date'],
                'unique_commercial_work_period'
            );
            $table->timestamps();
        });

        // 2. Refactor commissions: drop the FK first (MySQL requires this before dropping any
        //    index that the FK references), then drop the unique constraint and denormalized columns.
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropForeign(['commercial_id']);
            $table->dropUnique('unique_commercial_commission_period');
            $table->dropColumn(['commercial_id', 'period_start_date', 'period_end_date']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('commercial_work_period_id')
                ->after('id')
                ->constrained('commercial_work_periods')
                ->cascadeOnDelete();
            // One commission per work period.
            $table->unique('commercial_work_period_id', 'unique_commission_per_work_period');
        });

        // 3. Refactor commercial_objective_tiers.
        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->dropForeign(['commercial_id']);
            $table->dropUnique('unique_commercial_objective_tier');
            $table->dropColumn(['commercial_id', 'period_start_date', 'period_end_date']);
        });

        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->foreignId('commercial_work_period_id')
                ->after('id')
                ->constrained('commercial_work_periods')
                ->cascadeOnDelete();
            $table->unique(
                ['commercial_work_period_id', 'tier_level'],
                'unique_commercial_objective_tier'
            );
        });

        // 4. Refactor commercial_penalties (no unique constraint to rebuild).
        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->dropForeign(['commercial_id']);
            $table->dropColumn(['commercial_id', 'period_start_date', 'period_end_date']);
        });

        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->foreignId('commercial_work_period_id')
                ->after('id')
                ->constrained('commercial_work_periods')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Restore commercial_penalties.
        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->dropForeign(['commercial_work_period_id']);
            $table->dropColumn('commercial_work_period_id');
        });

        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->foreignId('commercial_id')->after('id')->constrained('commercials')->cascadeOnDelete();
            $table->date('period_start_date')->after('commercial_id');
            $table->date('period_end_date')->after('period_start_date');
        });

        // Restore commercial_objective_tiers.
        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->dropUnique('unique_commercial_objective_tier');
            $table->dropForeign(['commercial_work_period_id']);
            $table->dropColumn('commercial_work_period_id');
        });

        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->foreignId('commercial_id')->after('id')->constrained('commercials')->cascadeOnDelete();
            $table->date('period_start_date')->after('commercial_id');
            $table->date('period_end_date')->after('period_start_date');
            $table->unique(
                ['commercial_id', 'period_start_date', 'period_end_date', 'tier_level'],
                'unique_commercial_objective_tier'
            );
        });

        // Restore commissions.
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('unique_commission_per_work_period');
            $table->dropForeign(['commercial_work_period_id']);
            $table->dropColumn('commercial_work_period_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('commercial_id')->after('id')->constrained('commercials')->cascadeOnDelete();
            $table->date('period_start_date')->after('commercial_id');
            $table->date('period_end_date')->after('period_start_date');
            $table->unique(
                ['commercial_id', 'period_start_date', 'period_end_date'],
                'unique_commercial_commission_period'
            );
        });

        Schema::dropIfExists('commercial_work_periods');
    }
};
