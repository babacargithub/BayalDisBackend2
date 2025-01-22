<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Commercial;
use App\Models\Livreur;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::with(['customer', 'product', 'commercial', 'livreur'])
            ->latest()
            ->get();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'customers' => Customer::all(['id', 'name']),
            'products' => Product::all(['id', 'name']),
            'commercials' => Commercial::all(['id', 'name']),
            'livreurs' => Livreur::all(['id', 'name']),
            'statuses' => [
                ['value' => Order::STATUS_WAITING, 'text' => 'En attente'],
                ['value' => Order::STATUS_DELIVERED, 'text' => 'Livrée'],
                ['value' => Order::STATUS_CANCELLED, 'text' => 'Annulée'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer',
            'delivery_batch_id' => 'nullable|exists:delivery_batches,id',
            'should_be_delivered_at' => 'nullable|date',
            'status' => 'required|in:WAITING,DELIVERED,CANCELLED',
        ]);

        $order = Order::create([
            'customer_id' => $validated['customer_id'],
            'commercial_id' => $request->user()->commercial?->id ?? null ,
            'delivery_batch_id' => $validated['delivery_batch_id'],
            'should_be_delivered_at' => $validated['should_be_delivered_at'],
            'status' => $validated['status'],
        ]);

        foreach ($validated['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $order->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] ?? $product->price,
            ]);
        }

        return redirect()->back()->with('success', 'Commande créée avec succès.');
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|required|exists:customers,id',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer',
            'delivery_batch_id' => 'nullable|exists:delivery_batches,id',
            'should_be_delivered_at' => 'nullable|date',
            'status' => 'sometimes|required|in:WAITING,DELIVERED,CANCELLED',
        ]);

        $order->update([
            'customer_id' => $validated['customer_id'] ?? $order->customer_id,
            'delivery_batch_id' => $validated['delivery_batch_id'],
            'should_be_delivered_at' => $validated['should_be_delivered_at'],
            'status' => $validated['status'] ?? $order->status,
        ]);

        if (isset($validated['items'])) {
            // Delete existing items
            $order->items()->delete();
            
            // Create new items
            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                ]);
            }
        }

        return redirect()->back()->with('success', 'Commande mise à jour avec succès.');
    }

    public function destroy(Order $order)
    {
        try {
            $order->delete();

            Log::info('Order deleted successfully', ['order_id' => $order->id]);
            return redirect()->back()->with('success', 'Commande supprimée avec succès');
        } catch (\Exception $e) {
            Log::error('Error deleting order: ' . $e->getMessage(), ['order_id' => $order->id]);
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la suppression de la commande']);
        }
    }

    public function addItem(Request $request, Order $order)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        $item = $order->items()->create([
            'product_id' => $validated['product_id'],
            'quantity' => $validated['quantity'],
            'price' => $product->price,
        ]);

        $order->load(['items.product', 'customer']);

        return redirect()->back()
            ->with([
                'success' => 'Article ajouté avec succès',
                'order' => $order
            ]);
    }

    public function removeItem(Order $order, OrderItem $item)
    {
        if ($item->order_id !== $order->id) {
            return redirect()->back()
                ->with('error', 'Cet article n\'appartient pas à cette commande');
        }

        $item->delete();
        $order->load(['items.product', 'customer']);

        return redirect()->back()
            ->with([
                'success' => 'Article supprimé avec succès',
                'order' => $order
            ]);
    }
}
