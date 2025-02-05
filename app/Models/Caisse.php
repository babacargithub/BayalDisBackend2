<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caisse extends Model
{
    const TRANSACTION_TYPE_WITHDRAW = 'WITHDRAW';
    const TRANSACTION_TYPE_DEPOSIT = 'DEPOSIT';

    protected $fillable = [
        'name',
        'balance',
        'closed'
    ];

    protected $casts = [
        'closed' => 'boolean',
        'balance' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(CaisseTransaction::class);
    }
} 