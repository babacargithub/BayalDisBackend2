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
        Schema::create('car_load_fuel_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_load_id')->constrained()->onDelete('restrict');
            $table->unsignedInteger('amount');
            $table->decimal('liters', 6, 2)->nullable();
            $table->date('filled_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_load_fuel_entries');
    }
};
