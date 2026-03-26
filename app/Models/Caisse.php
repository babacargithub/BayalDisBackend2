<?php

namespace App\Models;

use App\Enums\CaisseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caisse extends Model
{
    const TRANSACTION_TYPE_WITHDRAW = 'WITHDRAW';

    const TRANSACTION_TYPE_DEPOSIT = 'DEPOSIT';

    protected $fillable = [
        'name',
        'caisse_type',
        'commercial_id',
        'balance',
        'closed',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'caisse_type' => CaisseType::class,
            'balance' => 'integer',
            'closed' => 'boolean',
            'locked_until' => 'date',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CaisseTransaction::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function isCommercialCaisse(): bool
    {
        return $this->caisse_type === CaisseType::Commercial;
    }

    public function isMainCaisse(): bool
    {
        return $this->caisse_type === CaisseType::Main;
    }

    /**
     * Returns true when the caisse has been locked for today via "Clôturer Journée".
     * A locked caisse rejects new payments until tomorrow.
     */
    public function isLockedForToday(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isToday();
    }
}
