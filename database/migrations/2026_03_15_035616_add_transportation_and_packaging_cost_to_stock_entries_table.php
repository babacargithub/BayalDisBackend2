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
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->unsignedInteger('transportation_cost')->default(0)->after('unit_price');
            $table->unsignedInteger('packaging_cost')->default(0)->after('transportation_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropColumn(['transportation_cost', 'packaging_cost']);
        });
    }
};
