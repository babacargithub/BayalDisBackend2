<?php namespace App\Http\Controllers;

use App\Models\DeliveryBatch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Livreur;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class DeliveryBatchController extends Controller
{
    public function index()
    {
        $batches = DeliveryBatch::with([
            'livreur',
            'orders',
            'orders.customer',
            'orders.product'
        ])
            ->latest()
            ->get();

        return inertia('DeliveryBatches/Index', [
            'batches' => $batches,
            'livreurs' => Livreur::all(),
            'customers' => Customer::all(),
            'products' => Product::all(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'delivery_date' => ['nullable', 'date'],
            'livreur_id' => ['nullable', 'exists:users,id'],
        ]);

        $batch = DeliveryBatch::create($validated);

        return redirect()->back()->with('success', 'Lot de livraison créé avec succès');
    }

    public function update(Request $request, DeliveryBatch $deliveryBatch)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'delivery_date' => ['nullable', 'date'],
            'livreur_id' => ['nullable', 'exists:users,id'],
        ]);

        $deliveryBatch->update($validated);

        return redirect()->back()->with('success', 'Lot de livraison mis à jour avec succès');
    }

    public function destroy(DeliveryBatch $deliveryBatch)
    {
        $deliveryBatch->delete();

        return redirect()->back()->with('success', 'Lot de livraison supprimé avec succès');
    }

    public function addOrders(Request $request, DeliveryBatch $deliveryBatch)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array'],
            'order_ids.*' => ['required', 'exists:orders,id'],
        ]);

        Order::whereIn('id', $validated['order_ids'])
            ->update(['delivery_batch_id' => $deliveryBatch->id]);

        return redirect()->back()->with('success', 'Commandes ajoutées au lot avec succès');
    }

    public function removeOrder(DeliveryBatch $deliveryBatch, Order $order)
    {
        if ($order->delivery_batch_id !== $deliveryBatch->id) {
            return redirect()->back()->with('error', 'Cette commande n\'appartient pas à ce lot');
        }

        $order->update(['delivery_batch_id' => null]);

        return redirect()->back()->with('success', 'Commande retirée du lot avec succès');
    }

    public function assignLivreur(Request $request, DeliveryBatch $deliveryBatch)
    {
        $validated = $request->validate([
            'livreur_id' => ['required', 'exists:users,id'],
        ]);

        $deliveryBatch->update(['livreur_id' => $validated['livreur_id']]);

        return redirect()->back()->with('success', 'Livreur assigné avec succès');
    }

    public function getAvailableOrders()
    {
        $orders = Order::whereNull('delivery_batch_id')
            ->where('status', Order::STATUS_WAITING)
            ->with(['customer', 'product'])
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function exportPdf(DeliveryBatch $deliveryBatch)
    {
        try {
            $batch = $deliveryBatch->load(['orders.customer', 'orders.product', 'livreur']);

            // Calculate totals
            $statusTotals = [
                'delivered' => $batch->orders->where('status', 'DELIVERED')->count(),
                'pending' => $batch->orders->where('status', 'WAITING')->count(),
                'cancelled' => $batch->orders->where('status', 'CANCELLED')->count(),
            ];

            $productTotals = $batch->orders->groupBy('product.name')
                ->map(function ($orders) {
                    return [
                        'quantity' => $orders->sum('quantity'),
                    ];
                })->toArray();

            $pdf = Pdf::loadView('pdf.delivery-batch', [
                'batch' => $batch,
                'statusTotals' => $statusTotals,
                'productTotals' => $productTotals,
            ]);

            return $pdf->download("lot-{$batch->name}-" . now()->format('Y-m-d') . '.pdf');
        } catch (\Exception $e) {
            Log::error('Error generating PDF for delivery batch: ' . $e->getMessage());
            return response()->json(['error' => 'Erreur lors de la génération du PDF'], 500);
        }
    }
} 