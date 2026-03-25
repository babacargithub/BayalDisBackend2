<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Application-wide pricing policy configuration.
 *
 * Fields:
 * - name: human readable label
 * - active: whether this policy applies
 * - surcharge_percent: integer percent to increase prices by (e.g., 10 for +10%)
 * - grace_days: number of days allowed before surcharge applies
 * - apply_to_deferred_only: if true, only apply when should_be_paid_at is in the future beyond grace_days
 * - apply_credit_price: if true, unpaid invoices use each product's credit_price instead of the normal price
 */
class PricingPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'active',
        'surcharge_percent',
        'grace_days',
        'apply_to_deferred_only',
        'apply_credit_price',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'surcharge_percent' => 'integer',
            'grace_days' => 'integer',
            'apply_to_deferred_only' => 'boolean',
            'apply_credit_price' => 'boolean',
        ];
    }
}
