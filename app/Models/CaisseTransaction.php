<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaisseTransaction extends Model
{
    protected $fillable = [
        'caisse_id',
        'amount',
        'label',
        'transaction_type'
    ];

    protected $casts = [
        'amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CaisseTransaction $transaction) {
            // Ensure amount is always positive and set transaction type based on input
            if ($transaction->transaction_type == null) {
                if ($transaction->amount < 0) {
                    $transaction->amount = abs($transaction->amount);
                    $transaction->transaction_type = Caisse::TRANSACTION_TYPE_WITHDRAW;
                } else {
                    $transaction->transaction_type = Caisse::TRANSACTION_TYPE_DEPOSIT;
                }
            }
        });
    }

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function getEffectiveAmountAttribute(): int
    {
        return $this->transaction_type === Caisse::TRANSACTION_TYPE_WITHDRAW 
            ? -$this->amount 
            : $this->amount;
    }
} 