<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\DeliverOrderRequest;
use App\Http\Requests\UpdateOrderItemsRequest;
use App\Models\Order;
use App\Services\OrderService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiOrderController extends Controller
{

    //
    public function __construct(readonly OrderService $orderService)
    {
    }

    public function getOrders(): JsonResponse
    {
        $orders = $this->orderService->getWaitingOrders();

        return response()->json(['data' => $orders]);
    }

    /**
     * @throws Throwable
     */
    public function createOrder(CreateOrderRequest $request): JsonResponse
    {
        $commercial = $request->user()->commercial;
        if (! $commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        $order = $this->orderService->createOrder($request->validated(), $commercial);

        return response()->json([
            'message' => 'Order created successfully',
            'data' => $order->load(['customer', 'product']),
        ], 201);
    }

    /**
     * @throws Throwable
     */
    public function cancelOrder(CancelOrderRequest $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_WAITING) {
            return response()->json(['message' => 'Seules les commandes en attente peuvent etre annulées'], 422);
        }

        $cancelledOrder = $this->orderService->cancelOrder($order, $request->validated('comment'));

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data' => $cancelledOrder->load(['customer', 'product']),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function deliverOrder(DeliverOrderRequest $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_WAITING) {
            return response()->json(['message' => 'Seules les commandes en attente peuvent etre livrées !'], 422);
        }

        $commercial = $request->user()->commercial;
        if (! $commercial) {
            return response()->json(['message' => 'Commercial not found'], 404);
        }

        $deliveredOrder = $this->orderService->deliverOrder($order, $request->validated(), $commercial);

        return response()->json([
            'message' => 'Order delivered successfully and invoice created',
            'data' => ['order' => $deliveredOrder->load(['customer', 'product'])],
        ]);
    }

    /**
     * @throws Throwable
     */
    public function updateOrderItems(UpdateOrderItemsRequest $request, Order $order): JsonResponse
    {
        try {
            $updatedOrder = $this->orderService->updateOrderItems($order, $request->validated('items'));

            return response()->json([
                'message' => 'Order items updated successfully',
                'data' => $updatedOrder,
            ]);
        } catch (Exception $orderItemUpdateException) {
            Log::error('Error updating order items: '.$orderItemUpdateException->getMessage());

            return response()->json([
                'message' => 'Error updating order items',
                'error' => $orderItemUpdateException->getMessage(),
            ], 500);
        }
    }

}
