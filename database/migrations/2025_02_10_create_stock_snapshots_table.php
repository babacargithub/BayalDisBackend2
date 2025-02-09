<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('snapshot_date');
            $table->string('reason'); // DAILY, MANUAL, INVENTORY
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('stock_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('stock_snapshots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity');
            $table->integer('cost_price');
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->timestamp('inventory_date');
            $table->string('status'); // DRAFT, IN_PROGRESS, COMPLETED
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('expected_quantity');
            $table->integer('actual_quantity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('car_loads', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->foreignId('commercial_id')->constrained('commercials');
            $table->timestamp('departure_date');
            $table->timestamp('return_date')->nullable();
            $table->string('status'); // LOADING, DEPARTED, RETURNED
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('car_load_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_load_id')->constrained('car_loads')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity_loaded');
            $table->integer('quantity_returned')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('car_load_items');
        Schema::dropIfExists('car_loads');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('stock_snapshot_items');
        Schema::dropIfExists('stock_snapshots');
    }
}; 