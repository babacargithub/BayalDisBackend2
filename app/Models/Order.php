<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    const STATUS_WAITING = 'WAITING';
    const STATUS_DELIVERED = 'DELIVERED';
    const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'customer_id',
        'quantity',
        'should_be_delivered_at',
        'product_id',
        'commercial_id',
        'livreur_id',
        'status',
        'comment',
    ];

    protected $casts = [
        'should_be_delivered_at' => 'datetime',
        'quantity' => 'integer',
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

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }
}
