<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('car_load_inventory_items', function (Blueprint $table) {
            $table->foreignId('car_load_inventory_id')
                ->constrained()
                ->cascadeOnDelete();
            if (Schema::hasColumn('car_load_inventory_items', 'car_load_id')) {
                // drop column and the foreign key
                $table->dropForeign(['car_load_id']);
                $table->dropColumn('car_load_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_load_inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('car_load_inventory_id');
            if (!Schema::hasColumn('car_load_inventory_items', 'car_load_id')) {
                $table->foreignId('car_load_id')
                    ->constrained()
                    ->cascadeOnDelete();
            }
        });
    }
}; 