<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replace visit_date with day_of_week on beats table
        Schema::table('beats', function (Blueprint $table) {
            $table->string('day_of_week')->nullable()->after('name');
            $table->dropColumn('visit_date');
        });

        // Add visit_date to beat_stops to track which day's occurrence a stop belongs to
        // NULL = template stop (defines the recurring customer list)
        // DATE value = occurrence stop (generated for a specific date)
        Schema::table('beat_stops', function (Blueprint $table) {
            $table->date('visit_date')->nullable()->after('beat_id');
            $table->index(['beat_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::table('beats', function (Blueprint $table) {
            $table->date('visit_date')->nullable()->after('name');
            $table->dropColumn('day_of_week');
        });

        Schema::table('beat_stops', function (Blueprint $table) {
            $table->dropIndex(['beat_id', 'visit_date']);
            $table->dropColumn('visit_date');
        });
    }
};
