<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beat_stops', function (Blueprint $table) {
            $table->unsignedInteger('display_position')->nullable()->after('visit_date');
        });
    }

    public function down(): void
    {
        Schema::table('beat_stops', function (Blueprint $table) {
            $table->dropColumn('display_position');
        });
    }
};
