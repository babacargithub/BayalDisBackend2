<?php

namespace App\Models;

use App\Enums\StockEntryTransferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function transfers(): HasMany
    {
        return $this->hasMany(StockEntryTransfer::class);
    }

    /**
     * Compute quantity_left from the transfer ledger.
     *
     * Formula: initial quantity − total Out transfers + total In transfers.
     *
     * Mirrors Caisse::updateBalanceFromLedger() — the quantity column is the
     * "opening stock" and each transfer is a debit (Out) or credit (In).
     */
    public function computeQuantityLeftFromTransfers(): int
    {
        $totalOut = $this->transfers()
            ->where('transfer_type', StockEntryTransferType::Out->value)
            ->sum('quantity');

        $totalIn = $this->transfers()
            ->where('transfer_type', StockEntryTransferType::In->value)
            ->sum('quantity');

        return $this->quantity - $totalOut + $totalIn;
    }

    /**
     * Recompute quantity_left from the transfer ledger and persist it.
     * Call this after every StockEntryTransfer is created or deleted.
     */
    public function updateQuantityLeftFromTransfers(): self
    {
        $this->quantity_left = $this->computeQuantityLeftFromTransfers();
        $this->save();

        return $this;
    }
}
