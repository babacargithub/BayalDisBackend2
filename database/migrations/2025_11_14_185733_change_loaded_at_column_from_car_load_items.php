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
        Schema::table('car_load_items', function (Blueprint $table) {
            //
            $table->dateTime('loaded_at')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_load_items', function (Blueprint $table) {
            //
            if (Schema::hasColumn('car_load_items', 'loaded_at')) {
                Schema::table('car_load_items', function (Blueprint $table) {
                    $table->date('loaded_at')->change();
                });
            }
        });
    }
};
