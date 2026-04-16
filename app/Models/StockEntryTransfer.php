<?php

namespace App\Models;

use App\Enums\StockEntryTransferType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_entry_id
 * @property int|null $car_load_item_id
 * @property int $quantity
 * @property StockEntryTransferType $transfer_type
 * @property Carbon $transferred_at
 * @property string|null $notes
 */
class StockEntryTransfer extends Model
{
    protected $fillable = [
        'stock_entry_id',
        'car_load_item_id',
        'quantity',
        'transfer_type',
        'transferred_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'transfer_type' => StockEntryTransferType::class,
            'transferred_at' => 'datetime',
        ];
    }

    public function stockEntry(): BelongsTo
    {
        return $this->belongsTo(StockEntry::class);
    }

    public function carLoadItem(): BelongsTo
    {
        return $this->belongsTo(CarLoadItem::class);
    }
}
