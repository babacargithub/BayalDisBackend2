<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            // First drop the foreign key
            // check if the column exists
            if (Schema::hasColumn('car_loads', 'commercial_id')) {
                $table->dropForeign(['commercial_id']);
                $table->dropColumn('commercial_id');
            }
            // check if the column does not exist
            if (!Schema::hasColumn('car_loads', 'team_id')) {
                // Add new team_id column
                $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            // check if the column exists
            if (Schema::hasColumn('car_loads', 'team_id')) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            }
            // check if the column does not exist
            if (!Schema::hasColumn('car_loads', 'commercial_id')) {
                // Restore commercial_id
                $table->foreignId('commercial_id')->nullable()->constrained('commercials')->onDelete('set null');
            }
        });
    }
}; 