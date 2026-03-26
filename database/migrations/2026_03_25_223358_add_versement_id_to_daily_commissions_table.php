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
        Schema::table('daily_commissions', function (Blueprint $table): void {
            // Column may already exist if a previous partial migration run added it.
            if (! Schema::hasColumn('daily_commissions', 'versement_id')) {
                $table->foreignId('versement_id')
                    ->nullable()
                    ->after('mandatory_threshold_reached')
                    ->constrained('commercial_versements')
                    ->nullOnDelete();
            } else {
                // Column exists without FK — add the constraint and index only.
                $table->foreign('versement_id', 'dc_versement_fk')
                    ->references('id')
                    ->on('commercial_versements')
                    ->nullOnDelete();
            }

            // Add index if not already present (safe across MySQL and SQLite).
            try {
                $table->index('versement_id', 'daily_commissions_versement_id_index');
            } catch (\Throwable) {
                // Index already exists — nothing to do.
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_commissions', function (Blueprint $table): void {
            $table->dropForeign(['versement_id']);
            $table->dropIndex(['versement_id']);
            $table->dropColumn('versement_id');
        });
    }
};
