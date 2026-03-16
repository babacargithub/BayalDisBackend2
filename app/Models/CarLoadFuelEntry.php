<?php

namespace App\Models;

use Database\Factories\CarLoadFuelEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarLoadFuelEntry extends Model
{
    /** @use HasFactory<CarLoadFuelEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'car_load_id',
        'amount',
        'liters',
        'filled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'liters' => 'decimal:2',
            'filled_at' => 'date',
        ];
    }

    public function carLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class);
    }
}
