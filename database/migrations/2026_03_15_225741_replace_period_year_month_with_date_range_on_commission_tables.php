<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * The four commission tables that use period_year + period_month are all
     * migrated the same way: drop the two integer columns and replace them
     * with period_start_date + period_end_date (dates). Unique constraints
     * are rebuilt accordingly.
     */
    public function up(): void
    {
        $tables = [
            'commissions',
            'commission_period_settings',
            'commercial_objective_tiers',
            'commercial_penalties',
        ];

        // SQLite and MySQL both require unique indexes to be dropped before the
        // columns they reference can be removed. Drop old constraints first.
        $oldUniqueConstraints = [
            ['table' => 'commissions',               'name' => 'unique_commercial_commission_period'],
            ['table' => 'commission_period_settings', 'name' => 'unique_commission_period'],
            ['table' => 'commercial_objective_tiers', 'name' => 'unique_commercial_objective_tier'],
        ];

        foreach ($oldUniqueConstraints as $constraint) {
            if (Schema::hasColumn($constraint['table'], 'period_year')) {
                try {
                    Schema::table($constraint['table'], function (Blueprint $table) use ($constraint) {
                        $table->dropUnique($constraint['name']);
                    });
                } catch (\Throwable) {
                    // Index didn't exist — safe to ignore.
                }
            }
        }

        foreach ($tables as $tableName) {
            if (Schema::hasColumn($tableName, 'period_year')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn(['period_year', 'period_month']);
                });
            }

            if (! Schema::hasColumn($tableName, 'period_start_date')) {
                // commission_period_settings has no commercial_id column — insert after id.
                $afterColumn = $tableName === 'commission_period_settings' ? 'id' : 'commercial_id';

                Schema::table($tableName, function (Blueprint $table) use ($afterColumn) {
                    $table->date('period_start_date')->after($afterColumn)
                        ->comment('Inclusive start of the commission period (typically a Monday)');
                    $table->date('period_end_date')->after('period_start_date')
                        ->comment('Inclusive end of the commission period (typically a Saturday)');
                });
            }
        }

        // Rebuild unique constraints using the new date columns.
        // Guard each with a try/catch to tolerate partially-applied migration states.
        $uniqueConstraints = [
            ['table' => 'commissions',              'name' => 'unique_commercial_commission_period', 'columns' => ['commercial_id', 'period_start_date', 'period_end_date']],
            ['table' => 'commission_period_settings', 'name' => 'unique_commission_period',            'columns' => ['period_start_date', 'period_end_date']],
            ['table' => 'commercial_objective_tiers', 'name' => 'unique_commercial_objective_tier',    'columns' => ['commercial_id', 'period_start_date', 'period_end_date', 'tier_level']],
        ];

        foreach ($uniqueConstraints as $constraint) {
            try {
                Schema::table($constraint['table'], function (Blueprint $table) use ($constraint) {
                    $table->unique($constraint['columns'], $constraint['name']);
                });
            } catch (\Illuminate\Database\QueryException $queryException) {
                // Duplicate key name — constraint was already created in a previous partial run.
                if (! str_contains($queryException->getMessage(), 'Duplicate key name')) {
                    throw $queryException;
                }
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'commissions',
            'commission_period_settings',
            'commercial_objective_tiers',
            'commercial_penalties',
        ];

        // Drop unique constraints that reference the date columns before removing the columns.
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('unique_commercial_commission_period');
        });
        Schema::table('commission_period_settings', function (Blueprint $table) {
            $table->dropUnique('unique_commission_period');
        });
        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->dropUnique('unique_commercial_objective_tier');
        });

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['period_start_date', 'period_end_date']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedSmallInteger('period_year')->after('commercial_id');
                $table->unsignedTinyInteger('period_month')->after('period_year');
            });
        }
    }
};
