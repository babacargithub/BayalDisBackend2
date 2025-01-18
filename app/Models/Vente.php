<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vente extends Model
{
    use HasFactory;

    const PAYMENT_METHOD_CASH = 'CASH';
    const PAYMENT_METHOD_WAVE = 'WAVE';
    const PAYMENT_METHOD_OM = 'OM';

    protected $fillable = [
        'product_id',
        'customer_id',
        'commercial_id',
        'quantity',
        'price',
        'paid',
        'should_be_paid_at',
        'paid_at',
        'payment_method',
    ];

    protected $casts = [
        'paid' => 'boolean',
        'quantity' => 'integer',
        'price' => 'integer',
        'should_be_paid_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }
} 