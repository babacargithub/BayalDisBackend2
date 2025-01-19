<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vente extends Model
{
    use HasFactory;

    const PAYMENT_METHOD_CASH = 'CASH';
    const PAYMENT_METHOD_WAVE = 'WAVE';
    const PAYMENT_METHOD_OM = 'OM';

    protected $fillable = [
        'customer_id',
        'product_id',
        'commercial_id',
        'quantity',
        'price',
        'paid',
        'payment_method',
        'should_be_paid_at',
        'paid_at',
        'order_id',
    ];

    protected $casts = [
        'paid' => 'boolean',
        'quantity' => 'integer',
        'price' => 'integer',
        'should_be_paid_at' => 'datetime',
        'paid_at' => 'datetime',
        'order_id' => 'integer',
    ];

    protected $with = ['customer', 'product', 'order'];

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
} 