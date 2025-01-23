<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:' . $order->remaining_amount,
            'payment_method' => 'required|string|in:CASH,WAVE,OM',
            'comment' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $payment = $order->addPayment(
                $validated['amount'],
                $validated['payment_method'],
                $validated['comment']
            );

            // Refresh the order to get updated payment status
            $order->load('payments');
            $order->refresh();

            DB::commit();

            return back()->with('success', 'Paiement enregistré avec succès');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Erreur lors de l\'enregistrement du paiement: ' . $e->getMessage()]);
        }
    }

    public function index(Order $order)
    {
        return Inertia::render('Orders/Payments', [
            'order' => $order->load('payments', 'customer'),
        ]);
    }
} 