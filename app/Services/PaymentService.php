<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentService
{
    public function getTodayPayments()
    {
        return Payment::with(['salesInvoice.customer'])
            ->whereDate('created_at', Carbon::today())
            ->get()
            ->map(function ($payment) {
                $invoice = $payment->salesInvoice;
                return [
                    'id' => $payment->id,
                    'customer' => [
                        'name' => $invoice->customer->name,
                        'address' => $invoice->customer->address,
                        'phone_number' => $invoice->customer->phone_number,
                    ],
                    'invoice_date' => $invoice->created_at,
                    'invoice_total' => $invoice->total,
                    'amount_paid' => $invoice->payments->sum('amount'),
                    'amount_remaining' => $invoice->total - $invoice->payments->sum('amount'),
                    'payment_method' => $payment->payment_method,
                    'created_at' => $payment->created_at,
                ];
            });
    }

    public function getPaymentStatistics()
    {
        $today = Carbon::today();
        
        return [
            'today_total' => Payment::whereDate('created_at', $today)->sum('amount'),
            'today_count' => Payment::whereDate('created_at', $today)->count(),
            'week_total' => Payment::whereBetween('created_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount'),
            'month_total' => Payment::whereMonth('created_at', $today->month)->whereYear('created_at', $today->year)->sum('amount'),
        ];
    }
} 