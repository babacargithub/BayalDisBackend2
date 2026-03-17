<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactors the commissions table into daily_commissions.
 *
 * Key changes:
 *  - Adds a work_day (date) column — one record per calendar day per work period.
 *  - Drops is_finalized / finalized_at (finalization moves to commercial_work_periods).
 *  - Replaces the one-per-work-period unique constraint with a per-day unique constraint.
 *  - Renames commission_payment_lines.commission_id to daily_commission_id.
 *
 * Existing data is truncated (dev environment).
 * Migration is written to be idempotent: each step checks current state before acting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isMysql = DB::getDriverName() === 'mysql';

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        // 1. Truncate dependent tables.
        if (Schema::hasTable('commission_payment_lines')) {
            DB::table('commission_payment_lines')->truncate();
        }
        if (Schema::hasTable('commissions')) {
            DB::table('commissions')->truncate();
        }
        if (Schema::hasTable('daily_commissions')) {
            DB::table('daily_commissions')->truncate();
        }

        if ($isMysql) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // 2. Drop commission_id from commission_payment_lines (if still present).
        if (Schema::hasColumn('commission_payment_lines', 'commission_id')) {
            Schema::table('commission_payment_lines', function (Blueprint $table) {
                $table->dropForeign(['commission_id']);
                $table->dropColumn('commission_id');
            });
        }

        // 3. Rename commissions → daily_commissions (if not already renamed).
        if (Schema::hasTable('commissions') && ! Schema::hasTable('daily_commissions')) {
            Schema::rename('commissions', 'daily_commissions');
        }

        // 4a. Drop the FK on commercial_work_period_id so MySQL lets us drop the unique index.
        //     The FK name uses the old table name 'commissions_commercial_work_period_id_foreign'.
        if ($this->foreignKeyExists('daily_commissions', 'commissions_commercial_work_period_id_foreign')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->dropForeign('commissions_commercial_work_period_id_foreign');
            });
        } elseif ($this->foreignKeyExists('daily_commissions', 'daily_commissions_commercial_work_period_id_foreign')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->dropForeign(['commercial_work_period_id']);
            });
        }

        // 4b. Drop the old one-per-period unique constraint (if still present).
        if ($this->indexExists('daily_commissions', 'unique_commission_per_work_period')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->dropUnique('unique_commission_per_work_period');
            });
        }

        // 4c. Drop finalization columns (if still present).
        $columnsToDrop = array_filter(
            ['is_finalized', 'finalized_at'],
            fn (string $column) => Schema::hasColumn('daily_commissions', $column),
        );
        if (! empty($columnsToDrop)) {
            Schema::table('daily_commissions', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn(array_values($columnsToDrop));
            });
        }

        // 4d. Add work_day (if not yet present).
        if (! Schema::hasColumn('daily_commissions', 'work_day')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->date('work_day')->after('commercial_work_period_id');
            });
        }

        // 4e. Re-add the FK on commercial_work_period_id (if not yet present).
        if (! $this->foreignKeyExists('daily_commissions', 'daily_commissions_commercial_work_period_id_foreign')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->foreign('commercial_work_period_id')
                    ->references('id')
                    ->on('commercial_work_periods')
                    ->cascadeOnDelete();
            });
        }

        // 4f. Add the new per-day unique constraint (if not yet present).
        if (! $this->indexExists('daily_commissions', 'unique_daily_commission_per_work_day')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                $table->unique(
                    ['commercial_work_period_id', 'work_day'],
                    'unique_daily_commission_per_work_day',
                );
            });
        }

        // 5. Add daily_commission_id FK on commission_payment_lines (if not yet present).
        if (! Schema::hasColumn('commission_payment_lines', 'daily_commission_id')) {
            Schema::table('commission_payment_lines', function (Blueprint $table) {
                $table->foreignId('daily_commission_id')
                    ->after('id')
                    ->constrained('daily_commissions')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Drop the new FK column from commission_payment_lines.
        if (Schema::hasColumn('commission_payment_lines', 'daily_commission_id')) {
            Schema::table('commission_payment_lines', function (Blueprint $table) {
                $table->dropForeign(['daily_commission_id']);
                $table->dropColumn('daily_commission_id');
            });
        }

        // Restore daily_commissions → commissions.
        if (Schema::hasTable('daily_commissions')) {
            Schema::table('daily_commissions', function (Blueprint $table) {
                if ($this->indexExists('daily_commissions', 'unique_daily_commission_per_work_day')) {
                    $table->dropUnique('unique_daily_commission_per_work_day');
                }
                $table->dropForeign(['commercial_work_period_id']);
            });

            Schema::table('daily_commissions', function (Blueprint $table) {
                if (Schema::hasColumn('daily_commissions', 'work_day')) {
                    $table->dropColumn('work_day');
                }
                $table->boolean('is_finalized')->default(false)->after('achieved_tier_level');
                $table->timestamp('finalized_at')->nullable()->after('is_finalized');
                $table->unique('commercial_work_period_id', 'unique_commission_per_work_period');
                $table->foreign('commercial_work_period_id')
                    ->references('id')
                    ->on('commercial_work_periods')
                    ->cascadeOnDelete();
            });

            Schema::rename('daily_commissions', 'commissions');
        }

        // Restore commission_id FK on commission_payment_lines.
        Schema::table('commission_payment_lines', function (Blueprint $table) {
            $table->foreignId('commission_id')
                ->after('id')
                ->constrained('commissions')
                ->cascadeOnDelete();
        });
    }

    private function foreignKeyExists(string $table, string $foreignKeyName): bool
    {
        return collect(Schema::getForeignKeys($table))
            ->contains(fn (array $foreignKey) => $foreignKey['name'] === $foreignKeyName);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => $index['name'] === $indexName);
    }
};
