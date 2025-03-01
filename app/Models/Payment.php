<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'sales_invoice_id',
        'amount',
        'payment_method',
        'comment',
        'user_id',
        'sales_invoice_id'
    ];

    protected $casts = [
        'amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
    protected $appends = [];

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function getTotalProfitAttribute()
    {
        $percentageOfProfit = $this->salesInvoice->getPercentageOfProfit();
        return (int)$this->amount * $percentageOfProfit;

    }
} 