<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'quantity_left',
        'purchase_invoice_item_id',
        'unit_price',
        'transportation_cost',
        'packaging_cost',
        'warehouse_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_left' => 'integer',
            'unit_price' => 'integer',
            'transportation_cost' => 'integer',
            'packaging_cost' => 'integer',
        ];
    }

    /**
     * Total direct unit cost: purchase price + transport allocation + packaging.
     * This is the full cost basis used for profit calculation.
     */
    public function getTotalUnitCostAttribute(): int
    {
        return $this->unit_price + $this->transportation_cost + $this->packaging_cost;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
