<?php

namespace App\Models;

use App\Enums\CarLoadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CarLoad extends Model
{
    protected $fillable = [
        'name',
        'load_date',
        'return_date',
        'team_id',
        'status',
        'comment',
        'returned',
        'previous_car_load_id',
    ];

    protected $casts = [
        'load_date' => 'datetime',
        'return_date' => 'datetime',
        'returned' => 'boolean',
        'status' => CarLoadStatus::class,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadItem::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(CarLoadInventory::class);
    }

    public function getStockValueAttribute(): int
    {
        // Terminated car loads have transferred all remaining stock to the next car load.
        // Their quantity_left values are already zeroed, but we short-circuit here to make
        // the intent explicit and avoid unnecessary queries.
        if ($this->status === CarLoadStatus::TerminatedAndTransferred) {
            return 0;
        }

        // Use a fresh query to avoid stale cached relations when items are modified
        $totalValue = 0;
        foreach ($this->items()->with('product')->get() as $item) {
            $totalValue += $item->quantity_left * $item->product->cost_price;
        }

        return $totalValue;
    }
}
