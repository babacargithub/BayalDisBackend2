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
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('account_type');

            // Populated only for vehicle-type accounts (VEHICLE_DEPRECIATION etc.)
            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete();

            // Populated only for commercial-type accounts (COMMERCIAL_COMMISSION, COMMERCIAL_COLLECTED)
            $table->foreignId('commercial_id')
                ->nullable()
                ->constrained('commercials')
                ->nullOnDelete();

            // Cached balance. Never set directly — always via increment/decrement
            // triggered by a corresponding AccountTransaction.
            $table->integer('balance')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('account_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
