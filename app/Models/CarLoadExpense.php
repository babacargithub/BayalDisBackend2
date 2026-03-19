<?php

namespace App\Models;

use App\Enums\CarLoadExpenseType;
use Database\Factories\CarLoadExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadExpense extends Model
{
    /** @use HasFactory<CarLoadExpenseFactory> */
    use HasFactory;

    protected $fillable = [
        'car_load_id',
        'label',
        'amount',
        'type',
    ];

    protected static function booted(): void
    {
        // When an expense is created or deleted, re-save the parent CarLoad
        // so its saving hook fires and fixed_daily_cost is kept in sync.
        $recalculateParentCarLoad = function (CarLoadExpense $expense): void {
            $expense->carLoad?->save();
        };

        static::saved($recalculateParentCarLoad);
        static::deleted($recalculateParentCarLoad);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'type' => CarLoadExpenseType::class,
        ];
    }

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }
}
