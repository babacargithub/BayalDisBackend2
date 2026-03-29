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

    /**
     * Recompute the caisse balance from its full transaction ledger and persist it.
     *
     * Formula: SUM(amount WHERE type=DEPOSIT) − SUM(amount WHERE type=WITHDRAW)
     *
     * Mirrors Account::updateBalanceFromLedger(). Call this after any CaisseTransaction
     * is created or deleted so the cached balance column stays authoritative.
     */
    public function updateBalanceFromLedger(): self
    {
        $deposits = $this->transactions()
            ->where('transaction_type', self::TRANSACTION_TYPE_DEPOSIT)
            ->sum('amount');

        $withdrawals = $this->transactions()
            ->where('transaction_type', self::TRANSACTION_TYPE_WITHDRAW)
            ->sum('amount');

        $this->balance = $deposits - $withdrawals;
        $this->save();

        return $this;
    }
}
