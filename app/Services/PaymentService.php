<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;

class PaymentService
{
    public function getPaymentsByDate(Carbon $date)
    {
        return Payment::with(['salesInvoice.customer'])
            ->whereDate('created_at', $date)
            ->get()
            ->map(function ($payment) {
                $invoice = $payment->salesInvoice;

                return [
                    'id' => $payment->id,
                    'invoice_id' => $payment->sales_invoice_id,
                    'invoice_created_at' => $invoice->created_at->toDateString(),
                    'customer' => [
                        'name' => $invoice->customer->name,
                        'address' => $invoice->customer->address,
                        'phone_number' => $invoice->customer->phone_number,
                    ],
                    'invoice_date' => $invoice->created_at,
                    'invoice_total' => $invoice->total_amount,
                    'payment_amount' => $payment->amount,
                    'amount_paid' => $invoice->total_payments,
                    'amount_remaining' => $invoice->total_remaining,
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                ];
            });
    }

    public function getPaymentStatistics(?Carbon $referenceDate = null)
    {
        $today = $referenceDate ?? Carbon::today();

        return [
            'today_total' => Payment::whereDate('created_at', $today)->sum('amount'),
            'today_count' => Payment::whereDate('created_at', $today)->count(),
            'week_total' => Payment::whereBetween('created_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount'),
            'month_total' => Payment::whereMonth('created_at', $today->month)->whereYear('created_at', $today->year)->sum('amount'),
        ];
    }
}
