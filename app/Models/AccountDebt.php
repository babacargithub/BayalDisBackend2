<?php

namespace App\Models;

use App\Enums\AccountDebtStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountDebt extends Model
{
    use HasFactory;

    protected $fillable = [
        'debtor_account_id',
        'creditor_account_id',
        'original_amount',
        'remaining_amount',
        'status',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'integer',
            'remaining_amount' => 'integer',
            'status' => AccountDebtStatus::class,
        ];
    }

    /** The account that owes money (borrowed). */
    public function debtorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debtor_account_id');
    }

    /** The account that is owed money (lent its balance). */
    public function creditorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'creditor_account_id');
    }

    public function isFullyRepaid(): bool
    {
        return $this->status === AccountDebtStatus::FullyRepaid;
    }

    public function isOutstanding(): bool
    {
        return $this->status === AccountDebtStatus::Pending
            || $this->status === AccountDebtStatus::PartiallyRepaid;
    }
}
