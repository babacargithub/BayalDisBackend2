<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id',
        'product_id',
        'quantity',
        'unit_price',
        'transportation_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'transportation_cost' => 'integer',
    ];

    /**
     * Per-unit transportation cost: the line's allocated share divided by quantity.
     * This is what gets stored on the StockEntry when the invoice is put in stock.
     */
    public function getTransportationCostPerUnitAttribute(): int
    {
        if ($this->quantity <= 0) {
            return 0;
        }

        return (int) round($this->transportation_cost / $this->quantity);
    }

    protected $appends = ['total_price'];

    public function getTotalPriceAttribute()
    {
        return $this->quantity * $this->unit_price;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function stockEntry(): HasOne
    {
        return $this->hasOne(StockEntry::class);
    }
}
