<?php

namespace App\Services;

use App\Models\Commercial;
use App\Models\Order;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderService
{
    /** @return Collection<int, Order> */
    public function getWaitingOrders(): Collection
    {
        return Order::with(['customer', 'product'])
            ->where('status', Order::STATUS_WAITING)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /** @throws Throwable */
    public function createOrder(array $validatedData, Commercial $commercial): Order
    {
        return DB::transaction(function () use ($validatedData, $commercial) {
            return $commercial->orders()->create([
                'customer_id' => $validatedData['customer_id'],
                'product_id' => $validatedData['product_id'],
                'quantity' => $validatedData['quantity'],
                'should_be_delivered_at' => $validatedData['should_be_delivered_at'],
                'comment' => $validatedData['comment'] ?? null,
                'status' => Order::STATUS_WAITING,
            ]);
        });
    }

    /** @throws Throwable */
    public function cancelOrder(Order $order, string $cancellationReason): Order
    {
        return DB::transaction(function () use ($order, $cancellationReason) {
            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'comment' => $cancellationReason,
            ]);

            return $order;
        });
    }

    /** @throws Throwable */
    public function deliverOrder(Order $order, array $validatedData, Commercial $commercial): Order
    {
        return DB::transaction(function () use ($order, $validatedData, $commercial) {
            $salesInvoice = SalesInvoice::create([
                'customer_id' => $order->customer_id,
                'paid' => $validatedData['paid'],
                'should_be_paid_at' => $validatedData['should_be_paid_at'] ?? null,
                'comment' => 'Facture de livraison',
            ]);

            $orderItemsToCreate = [];
            foreach ($order->items as $item) {
                $orderItemsToCreate[] = [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'commercial_id' => $commercial->id,
                    'type' => 'INVOICE_ITEM',
                    'paid' => $validatedData['paid'],
                    'payment_method' => $validatedData['payment_method'] ?? null,
                    'should_be_paid_at' => $validatedData['should_be_paid_at'] ?? null,
                    'paid_at' => $validatedData['paid'] ? now() : null,
                ];
            }
            $salesInvoice->items()->createMany($orderItemsToCreate);

            $order->update([
                'status' => Order::STATUS_DELIVERED,
                'sales_invoice_id' => $salesInvoice->id,
            ]);

            if ($validatedData['paid']) {
                $salesInvoice->payments()->create([
                    'amount' => $salesInvoice->total,
                    'payment_method' => $validatedData['payment_method'],
                    'comment' => 'Paiement à la livraison',
                ]);
            }

            return $order;
        });
    }

    /** @throws Throwable */
    public function updateOrderItems(Order $order, array $items): Order
    {
        return DB::transaction(function () use ($order, $items) {
            $order->items()->delete();

            foreach ($items as $item) {
                $order->items()->updateOrCreate(
                    ['product_id' => $item['product_id']],
                    ['quantity' => $item['quantity'], 'price' => $item['price']]
                );
            }

            return $order->load(['items.product', 'customer']);
        });
    }
}
