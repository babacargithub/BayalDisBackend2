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
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'comment' => 'nullable|string'
        ]);

        DB::transaction(function () use ($order, $validated, $request) {
            // Create payment with user_id
            $payment = $order->payments()->create([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'comment' => $validated['comment'],
                'user_id' => $request->user()->id
            ]);

            // Check if order is fully paid
            $totalPaid = $order->payments()->sum('amount');
            $orderTotal = $order->items()->sum(DB::raw('price * quantity'));

            if ($totalPaid >= $orderTotal) {
                $order->update(['paid' => true]);
                $order->items()->update([
                    'paid' => true,
                    'paid_at' => now()
                ]);
            }
        });

        return redirect()->back()->with('success', 'Paiement enregistré avec succès');
    }

    public function destroy(Payment $payment)
    {
        try {
            DB::transaction(function () use ($payment) {
                $order = $payment->order;
                $payment->delete();

                // Recalculate if order is still fully paid
                $totalPaid = $order->payments()->sum('amount');
                $orderTotal = $order->items()->sum(DB::raw('price * quantity'));

                if ($totalPaid < $orderTotal) {
                    $order->update(['paid' => false]);
                    $order->items()->update([
                        'paid' => false,
                        'paid_at' => null
                    ]);
                }
            });

            return redirect()->back()->with('success', 'Paiement supprimé avec succès');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Erreur lors de la suppression du paiement');
        }
    }

    public function index(Order $order)
    {
        return Inertia::render('Orders/Payments', [
            'order' => $order->load('payments', 'customer'),
        ]);
    }
} 