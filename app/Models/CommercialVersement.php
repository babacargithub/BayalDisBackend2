<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommercialVersement extends Model
{
    protected $fillable = [
        'commercial_id',
        'main_caisse_id',
        'versement_date',
        'amount_versed',
        'commission_credited',
        'merchandise_credited',
        'caisse_withdraw_transaction_id',
        'caisse_deposit_transaction_id',
        'collected_account_debit_transaction_id',
        'commission_account_transaction_id',
        'merchandise_account_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'versement_date' => 'date',
            'amount_versed' => 'integer',
            'commission_credited' => 'integer',
            'merchandise_credited' => 'integer',
        ];
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function mainCaisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class, 'main_caisse_id');
    }

    public function caisseWithdrawTransaction(): BelongsTo
    {
        return $this->belongsTo(CaisseTransaction::class, 'caisse_withdraw_transaction_id');
    }

    public function caisseDepositTransaction(): BelongsTo
    {
        return $this->belongsTo(CaisseTransaction::class, 'caisse_deposit_transaction_id');
    }

    public function collectedAccountDebitTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class, 'collected_account_debit_transaction_id');
    }

    public function commissionAccountTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class, 'commission_account_transaction_id');
    }

    public function merchandiseAccountTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class, 'merchandise_account_transaction_id');
    }

    /**
     * Daily commissions that were included in this versement's commission payout.
     */
    public function dailyCommissions(): HasMany
    {
        return $this->hasMany(DailyCommission::class, 'versement_id');
    }
}
