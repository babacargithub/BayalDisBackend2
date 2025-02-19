<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('car_loads', function (Blueprint $table) {
            if (!Schema::hasColumn('car_loads', 'returned')) {
                $table->boolean('returned')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_loads', function (Blueprint $table) {
            if (Schema::hasColumn('car_loads', 'returned')) {
                $table->dropColumn('returned');
            }
        });
    }
}; 