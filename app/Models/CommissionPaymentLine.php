<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPaymentLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'commission_id',
        'payment_id',
        'product_id',
        'rate_applied',
        'payment_amount_allocated',
        'commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'rate_applied' => 'decimal:4',
            'payment_amount_allocated' => 'integer',
            'commission_amount' => 'integer',
        ];
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
