<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyCostDistribution extends Model
{
    protected $fillable = [
        'distribution_date',
        'total_amount_distributed',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'total_amount_distributed' => 'integer',
        ];
    }

    /**
     * All account credit transactions created by this distribution.
     * (reference_type = 'DAILY_DISTRIBUTION', reference_id = this->id)
     */
    public function accountTransactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class, 'reference_id')
            ->where('reference_type', 'DAILY_DISTRIBUTION');
    }
}
