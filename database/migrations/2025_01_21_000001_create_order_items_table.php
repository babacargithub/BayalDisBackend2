<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity');
            $table->integer('price');
            $table->timestamps();
        });

        // Remove product_id and quantity from orders table
        Schema::table('orders', function (Blueprint $table) {
            // $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'quantity']);
        });
    }

    public function down()
    {
        // Add back product_id and quantity to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained();
            $table->integer('quantity')->default(1);
        });

        Schema::dropIfExists('order_items');
    }
}; 