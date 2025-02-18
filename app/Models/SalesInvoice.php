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
        "comment",
    ];

    protected $casts = [
        'paid' => 'boolean',
        'should_be_paid_at' => 'datetime',
    ];

    protected $appends = ['total', 'total_remaining'];

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
        return $this->total - $this->payments->sum('amount');
    }

    public function getTotalPaidAttribute() : int
    {
        return $this->payments->sum('amount');
    }
} 