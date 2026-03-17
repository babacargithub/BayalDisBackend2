<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves finalization from the daily_commissions table to commercial_work_periods,
 * and adds an optional work_day to commercial_penalties so penalties can be
 * assigned to a specific calendar day within a period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_work_periods', function (Blueprint $table) {
            $table->boolean('is_finalized')->default(false)->after('period_end_date');
            $table->timestamp('finalized_at')->nullable()->after('is_finalized');
        });

        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->date('work_day')->nullable()->after('commercial_work_period_id')
                ->comment('The specific work day this penalty applies to, or null for period-level penalties');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_penalties', function (Blueprint $table) {
            $table->dropColumn('work_day');
        });

        Schema::table('commercial_work_periods', function (Blueprint $table) {
            $table->dropColumn(['is_finalized', 'finalized_at']);
        });
    }
};
