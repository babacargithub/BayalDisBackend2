<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill NULL loaded_at values using the parent car load's load_date,
     * then enforce NOT NULL so FIFO ordering is always deterministic.
     *
     * Without this fix, MySQL sorts NULLs first in ASC order, causing items
     * with no date to be consumed before properly dated items — breaking FIFO.
     */
    public function up(): void
    {
        // Backfill: set loaded_at to the car load's load_date for any rows where it is NULL.
        // Subquery syntax is compatible with both MySQL and SQLite (used in tests).
        DB::statement('
            UPDATE car_load_items
            SET loaded_at = (
                SELECT load_date FROM car_loads WHERE car_loads.id = car_load_items.car_load_id
            )
            WHERE loaded_at IS NULL
        ');

        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dateTime('loaded_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            $table->dateTime('loaded_at')->nullable()->change();
        });
    }
};
