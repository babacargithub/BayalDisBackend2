<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stores per-commercial bonus amounts for creating new customers.
 *
 * One row per commercial (unique on commercial_id).
 * If no row exists for a commercial, both bonuses default to 0.
 *
 *  - confirmed_customer_bonus : XOF bonus per new confirmed customer (is_prospect = false)
 *  - prospect_customer_bonus  : XOF bonus per new prospect customer (is_prospect = true)
 */
class CommercialNewCustomerCommissionSetting extends Model
{
    protected $fillable = [
        'commercial_id',
        'confirmed_customer_bonus',
        'prospect_customer_bonus',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_customer_bonus' => 'integer',
            'prospect_customer_bonus' => 'integer',
        ];
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }
}
