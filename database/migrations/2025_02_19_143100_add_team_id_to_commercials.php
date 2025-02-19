<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercials', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('commercials', function (Blueprint $table) {
            // check if the column exists
            if (Schema::hasColumn('commercials', 'team_id')) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            }
        });
    }
}; 