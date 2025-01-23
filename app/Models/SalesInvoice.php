<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    protected $appends = ['total'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    public function getTotalAttribute(): int
    {
        return (int) $this->items->sum(function ($item) {
            return $item->price * $item->quantity;
        });
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
} 