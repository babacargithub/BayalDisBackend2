<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\OrderItem;

return new class extends Migration
{
    public function up()
    {
        // First, get all orders with the old structure
        $orders = DB::table('orders')
            ->whereNotNull('product_id')
            ->whereNotNull('quantity')
            ->get();
        // Create order_items for each order
        foreach ($orders as $order) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'price' => DB::table('products')->where('id', $order->product_id)->value('price'),
                'created_at' => $order->created_at,
                'updated_at' => $order->updated_at,
            ]);
        }
        // After successful migration, remove the old columns
        Schema::table('orders', function (Blueprint $table) {
            // check if sqllite
            // make product id nullable
            $table->foreignId('product_id')->nullable()->change();
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['product_id']);
            }
            $table->dropColumn(['product_id', 'quantity']);
        });
    }

    public function down()
    {
        // Add back the old columns
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained();
            $table->integer('quantity')->nullable();
        });

        // Get all order items
        $orderItems = DB::table('order_items')->get();

        // For each order item, update the corresponding order
        // Note: This will only work for orders that had a single item
        foreach ($orderItems as $item) {
            DB::table('orders')
                ->where('id', $item->order_id)
                ->update([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                ]);
        }
    }
}; 