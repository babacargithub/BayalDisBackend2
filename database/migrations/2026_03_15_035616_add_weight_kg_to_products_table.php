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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_kg', 8, 3)->nullable()->after('cost_price');
            $table->decimal('volume_m3', 8, 3)->nullable()->after('weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'weight_kg')) {
                $table->dropColumn('weight_kg');
            }
            if (Schema::hasColumn('products', 'volume_m3')) {
                $table->dropColumn('volume_m3');
            }
        });
    }
};
