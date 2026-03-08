<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $total
 * @property int $total_remaining
 * @property int $total_paid
 */
class SalesInvoice extends Model
{
    protected $fillable = [
        'customer_id',
        'paid',
        'should_be_paid_at',
        'comment',
        'commercial_id',
        'car_load_id',
    ];

    protected $casts = [
        'paid' => 'boolean',
        'should_be_paid_at' => 'datetime',
    ];

    protected $appends = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Vente::class)->where('type', 'INVOICE_ITEM');
    }

    public function getTotalAttribute(): int
    {
        return (int) $this->items->sum('subtotal');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Boot method to handle cascading updates
    protected static function boot()
    {
        parent::boot();

        // When paid status changes, update all related ventes
        static::updated(function ($invoice) {
            if ($invoice->isDirty('paid')) {
                $invoice->items()->update(['paid' => $invoice->paid]);
            }
            if ($invoice->isDirty('should_be_paid_at')) {
                $invoice->items()->update(['should_be_paid_at' => $invoice->should_be_paid_at]);
            }
        });
    }

    public function getTotalRemainingAttribute()
    {
        return intval($this->items()
            ->selectRaw('SUM(quantity * price) as total')
            ->value('total')) - $this->payments->sum('amount');
    }

    public function getTotalPaidAttribute(): int
    {
        return $this->payments->sum('amount');
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }

    public function getTotalProfitAttribute(): int
    {
        return (int) $this->items()->selectRaw('SUM(profit) as total')->value('total');

    }

    public function getPercentageOfProfit(): float|int
    {
        $profit = $this->getTotalProfitAttribute();
        if ($profit == 0) {
            return 1;
        }

        return $profit / $this->total;

    }

    public function getTotalProfitPaidAttribute(): int
    {
        $percentageOfProfit = $this->getPercentageOfProfit();

        return $this->total_paid * $percentageOfProfit;

    }

    /**
     * Compute the portion of this invoice's profit that is realized by a given payment amount.
     *
     * Formula: profit_margin_ratio × payment_amount
     * where profit_margin_ratio = total_profit / invoice_total
     *
     * Returns 0 if the invoice total is 0 (no items yet) to prevent division by zero.
     * This is the single source of truth for realized-profit-per-payment computation.
     */
    public function computeRealizedProfitForPaymentAmount(int $paymentAmount): int
    {
        $invoiceTotal = $this->getTotalAttribute();

        if ($invoiceTotal === 0) {
            return 0;
        }

        $invoiceTotalProfit = $this->getTotalProfitAttribute();

        return (int) round($invoiceTotalProfit / $invoiceTotal * $paymentAmount);
    }
}
