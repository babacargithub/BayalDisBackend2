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
        Schema::create('stock_entry_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_entry_id')->constrained('stock_entries')->restrictOnDelete();
            $table->foreignId('car_load_item_id')->nullable()->constrained('car_load_items')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('transfer_type'); // out | in
            $table->datetime('transferred_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_entry_transfers');
    }
};
