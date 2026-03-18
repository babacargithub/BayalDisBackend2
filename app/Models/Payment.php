<?php

namespace App\Models;

use App\Jobs\RecalculateDailyCommissionJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $amount Payment amount in XOF.
 * @property int $profit Realized profit allocated to this payment (proportional share of invoice profit).
 * @property int $commercial_commission Commission owed to the commercial for this payment (proportional share of invoice commission).
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'sales_invoice_id',
        'amount',
        'profit',
        'commercial_commission',
        'payment_method',
        'comment',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'profit' => 'integer',
            'commercial_commission' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected $appends = [];

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Auto-populate profit when a payment is created.
         * Uses the invoice's stored total_amount and total_estimated_profit columns,
         * so no extra DB query is needed to load items.
         */
        static::creating(function (Payment $payment) {
            if ($payment->sales_invoice_id !== null) {
                $invoice = SalesInvoice::find($payment->sales_invoice_id);

                if ($invoice !== null) {
                    $payment->profit = $invoice->computeRealizedProfitForPaymentAmount($payment->amount);
                    $payment->commercial_commission = $invoice->computeCommercialCommissionForPaymentAmount($payment->amount);
                }
            }
        });

        /**
         * After any payment is persisted, recalculate the invoice's stored totals
         * so total_payments, total_realized_profit, status, and paid stay in sync.
         * Also dispatch a background job to recalculate the commercial's daily commission.
         */
        static::saved(function (Payment $payment) {
            if ($payment->sales_invoice_id !== null) {
                SalesInvoice::find($payment->sales_invoice_id)?->recalculateStoredTotals();
            }

            RecalculateDailyCommissionJob::dispatch(
                userId: $payment->user_id,
                workDay: $payment->created_at->toDateString(),
                salesInvoiceId: $payment->sales_invoice_id,
            );
        });

        /**
         * After a payment is deleted, recalculate the invoice's stored totals
         * so the balance and status are updated immediately.
         * Also dispatch a background job to recalculate the commercial's daily commission.
         */
        static::deleted(function (Payment $payment) {
            if ($payment->sales_invoice_id !== null) {
                SalesInvoice::find($payment->sales_invoice_id)?->recalculateStoredTotals();
            }

            RecalculateDailyCommissionJob::dispatch(
                userId: $payment->user_id,
                workDay: $payment->created_at->toDateString(),
                salesInvoiceId: $payment->sales_invoice_id,
            );
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

    /**
     * The profit portion of this payment, computed from the invoice's profit margin.
     * Uses stored columns on the invoice — no extra DB query for items.
     */
    public function getTotalProfitAttribute(): int
    {
        $invoice = $this->salesInvoice;

        if ($invoice === null || $invoice->total_amount === 0) {
            return 0;
        }

        return (int) round($invoice->total_estimated_profit / $invoice->total_amount * $this->amount);
    }
}
