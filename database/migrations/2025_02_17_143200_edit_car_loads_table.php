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
        Schema::table('car_loads', function (Blueprint $table) {
          
            $table->renameColumn('reference', 'name');
            $table->renameColumn('departure_date', 'load_date');
            $table->renameColumn('notes', 'comment');
            $table->foreignId('previous_car_load_id')->nullable()->constrained('car_loads')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            $table->renameColumn('name', 'reference');
            $table->renameColumn('load_date', 'departure_date');
            $table->renameColumn('comment', 'notes');
            $table->dropColumn('previous_car_load_id');
        });
    }
}; 