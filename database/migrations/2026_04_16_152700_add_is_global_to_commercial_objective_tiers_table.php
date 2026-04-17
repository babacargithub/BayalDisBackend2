<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            // Must drop FK before dropping the unique index that the FK depends on.
            // Use column array syntax for SQLite compatibility (named FK drop not supported in SQLite).
            $table->dropForeign(['commercial_work_period_id']);
            $table->dropUnique('unique_commercial_objective_tier');

            // Make commercial_work_period_id nullable — null means this is a global tier.
            $table->unsignedBigInteger('commercial_work_period_id')->nullable()->change();

            // Re-add FK on the now-nullable column.
            $table->foreign('commercial_work_period_id')
                ->references('id')
                ->on('commercial_work_periods')
                ->cascadeOnDelete();

            // Global flag — true for tiers that apply to all commercials by default.
            $table->boolean('is_global')->default(false)->after('commercial_work_period_id');

            // Restore unique index: (commercial_work_period_id, tier_level).
            // Global tier uniqueness (null work_period_id + tier_level) is enforced at application level
            // because MySQL treats NULL values as distinct in UNIQUE indexes.
            $table->unique(['commercial_work_period_id', 'tier_level'], 'unique_commercial_objective_tier');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_objective_tiers', function (Blueprint $table) {
            $table->dropForeign(['commercial_work_period_id']);
            $table->dropUnique('unique_commercial_objective_tier');
            $table->dropColumn('is_global');

            $table->unsignedBigInteger('commercial_work_period_id')->nullable(false)->change();

            $table->foreign('commercial_work_period_id')
                ->references('id')
                ->on('commercial_work_periods')
                ->cascadeOnDelete();

            $table->unique(['commercial_work_period_id', 'tier_level'], 'unique_commercial_objective_tier');
        });
    }
};
