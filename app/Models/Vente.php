<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vente extends Model
{
    protected $fillable = [
        'customer_id',
        'product_id',
        'quantity',
        'price',
        'total',
        'paid',
        'paid_at',
        'payment_method',
        'should_be_paid_at',
    ];

    protected $casts = [
        'paid' => 'boolean',
        'paid_at' => 'datetime',
        'should_be_paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Product::class);

    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function commercial()
    {
        return $this->belongsTo(Commercial::class);
    }
} 