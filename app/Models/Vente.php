<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vente extends Model
{
    protected $fillable = [
        'product_id',
        'customer_id',
        'commercial_id',
        'quantity',
        'price',
        'paid',
        'should_be_paid_at'
    ];

    protected $casts = [
        'paid' => 'boolean',
        'should_be_paid_at' => 'datetime',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }
} 