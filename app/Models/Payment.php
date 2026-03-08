<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'sales_invoice_id',
        'amount',
        'profit',
        'payment_method',
        'comment',
        'user_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'profit' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [];

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Auto-populate profit when a payment is created.
         * Computes the portion of the invoice's total profit realized by this payment amount.
         * This fires regardless of which code path creates the payment, ensuring
         * payments.profit is always correct and never left at 0.
         */
        static::creating(function (Payment $payment) {
            if ($payment->sales_invoice_id !== null) {
                $invoice = SalesInvoice::with('items')->find($payment->sales_invoice_id);

                if ($invoice !== null) {
                    $payment->profit = $invoice->computeRealizedProfitForPaymentAmount($payment->amount);
                }
            }
        });
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTotalProfitAttribute(): int
    {
        $percentageOfProfit = $this->salesInvoice->getPercentageOfProfit();

        return (int) $this->amount * $percentageOfProfit;
    }
}
