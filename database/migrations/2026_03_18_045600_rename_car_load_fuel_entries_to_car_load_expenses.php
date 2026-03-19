<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('car_load_fuel_entries', 'car_load_expenses');

        Schema::table('car_load_expenses', function (Blueprint $table) {
            $table->string('label')->nullable()->after('car_load_id');
            $table->string('type')->default('FUEL')->after('label');
            $table->dropColumn(['liters', 'filled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('car_load_expenses', function (Blueprint $table) {
            $table->dropColumn(['label', 'type']);
            $table->decimal('liters', 6, 2)->nullable()->after('amount');
            $table->date('filled_at')->after('liters');
        });

        Schema::rename('car_load_expenses', 'car_load_fuel_entries');
    }
};
