<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'amount',
        'payment_method',
        'comment'
    ];

    protected $casts = [
        'amount' => 'integer'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
} 