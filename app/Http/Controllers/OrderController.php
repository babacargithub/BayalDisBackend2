<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Commercial;
use App\Models\Livreur;
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
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
                'should_be_delivered_at' => 'required|date',
                'commercial_id' => 'nullable|exists:commercials,id',
                'livreur_id' => 'nullable|exists:livreurs,id',
                'delivery_batch_id' => 'nullable|exists:delivery_batches,id',
                'status' => 'in:' . implode(',', [
                    Order::STATUS_WAITING,
                    Order::STATUS_DELIVERED,
                    Order::STATUS_CANCELLED,
                ]),
                'comment' => 'nullable|string',
            ]);

            Order::create($validated);

            Log::info('Order created successfully');
            return redirect()->back()->with('success', 'Commande créée avec succès');
        } catch (\Exception $e) {
            Log::error('Error creating order: ' . $e->getMessage());
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la création de la commande']);
        }
    }

    public function update(Request $request, Order $order)
    {
        try {
            // If only status is being updated
            if ($request->has('status') && count($request->all()) === 1) {
                $validated = $request->validate([
                    'status' => 'required|in:' . implode(',', [
                        Order::STATUS_WAITING,
                        Order::STATUS_DELIVERED,
                        Order::STATUS_CANCELLED,
                    ]),
                ]);
            } else {
                $validated = $request->validate([
                    'customer_id' => 'required|exists:customers,id',
                    'product_id' => 'required|exists:products,id',
                    'quantity' => 'required|integer|min:1',
                    'should_be_delivered_at' => 'required|date',
                    'commercial_id' => 'nullable|exists:commercials,id',
                    'livreur_id' => 'nullable|exists:livreurs,id',
                    'status' => 'in:' . implode(',', [
                        Order::STATUS_WAITING,
                        Order::STATUS_DELIVERED,
                        Order::STATUS_CANCELLED,
                    ]),
                    'comment' => 'nullable|string',
                ]);
            }

            $order->update($validated);

            Log::info('Order updated successfully', [
                'order_id' => $order->id,
                'updated_fields' => array_keys($validated)
            ]);
            
            return redirect()->back()->with('success', 'Commande mise à jour avec succès');
        } catch (\Exception $e) {
            Log::error('Error updating order: ' . $e->getMessage(), ['order_id' => $order->id]);
            return redirect()->back()->withErrors(['error' => 'Erreur lors de la mise à jour de la commande']);
        }
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
}
