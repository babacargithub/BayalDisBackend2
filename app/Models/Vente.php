<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class Vente extends Model
{
    use HasFactory;

    const PAYMENT_METHOD_CASH = 'Cash';
    const PAYMENT_METHOD_WAVE = 'Wave';
    const PAYMENT_METHOD_OM = 'Om';
    const TYPE_INVOICE = "INVOICE_ITEM";
    const TYPE_SINGLE = "SINGLE";

    protected $fillable = [
        'customer_id',
        'product_id',
        'commercial_id',
        'quantity',
        'price',
        'profit',
        'paid',
        'payment_method',
        'should_be_paid_at',
        'paid_at',
        'order_id',
        'sales_invoice_id',
        'type', // 'SINGLE' or 'INVOICE_ITEM'
    ];

    protected $casts = [
        'paid' => 'boolean',
        'quantity' => 'integer',
        'price' => 'integer',
        'profit' => 'integer',
        "should_be_paid_at" => 'datetime',
        "created_at" => 'datetime',
        "updated_at" => 'datetime',
        'paid_at' => 'datetime',
        'order_id' => 'integer',
    ];

//    protected $with = ['product', 'order'];

    protected $appends = [];

    public function getSubtotalAttribute(): int
    {
        return (int) ($this->price * $this->quantity);
    }

    public function getCustomerNameAttribute(): string
    {
        return $this->getCustomer()?->name ?? 'N/A';
    }

    // Direct customer relationship
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Get the actual customer (either from invoice or direct relationship)
    public function getCustomer(): ?Customer
    {
        if ($this->isInvoiceItem()) {
            return $this->salesInvoice?->customer;
        }
        return Customer::whereId($this->customer_id)->first();
    }

    // Boot method to add validation
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($vente) {
            if ($vente->type === 'SINGLE' && !$vente->customer_id) {
                throw new \Exception('Customer ID is required for single ventes');
            }

            // Calculate profit when saving
            //$vente->calculateProfit();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    // Helper methods
    public function isInvoiceItem(): bool
    {
        return $this->type === 'INVOICE_ITEM';
    }

    public function isSingleVente(): bool
    {
        return $this->type === 'SINGLE';
    }

    // Scope to get only single ventes
    public function scopeSingle($query)
    {
        return $query->where('type', 'SINGLE');
    }

    // Scope to get only invoice items
    public function scopeInvoiceItems($query)
    {
        return $query->where('type', 'INVOICE_ITEM');
    }

    // Override the paid attribute getter
    public function getPaidAttribute($value)
    {
        if ($this->sales_invoice_id) {
            return $this->salesInvoice->paid;
        }
        return $value;
    }

    // Override the should_be_paid_at attribute getter
    public function getShouldBePaidAtAttribute($value): bool|Carbon|null
    {
        if ($this->sales_invoice_id) {
            $value = $this->salesInvoice->should_be_paid_at;
        }
        // cast to datetime
        return $value!= null ? $this->asDateTime($value) : null;
    }
    public function getCustomerAttribute(): ?Customer
    {
        if ($this->isInvoiceItem()) {
            return $this->salesInvoice?->customer;
        }
        return Customer::whereId($this->customer_id)->first();

    }

    /**
     * @param $vente
     * @return void
     */
    function calculateProfit(): void
    {
        if ($this->product) {
            // Get the historical cost price from StockEntry records
            $historicalCostPrice = $this->getCostPriceFromStockEntry();

            // Calculate profit using the historical cost price
            $this->profit = ($this->price - $historicalCostPrice) * $this->quantity;
        }
    }

    /**
     * Get the historical cost price for a Vente based on FIFO principle
     *
     * @param $vente
     * @return float
     */
    private function getHistoricalCostPrice($vente): float
    {
        // If the Vente is new and doesn't have a creation date yet, use the current date
        $venteDate = $vente->created_at ?? now();

        // Get StockEntry records that existed before or at the time of the Vente
        // Ordered by creation date (FIFO principle)
        $stockEntries = \App\Models\StockEntry::where('product_id', $vente->product_id)
            ->where('created_at', '<=', $venteDate)
            ->orderBy('created_at', 'asc')
            ->get();

        // If no historical stock entries found, fall back to the product's current cost price
        if ($stockEntries->isEmpty()) {
            return $vente->product->cost_price;
        }

        // For simplicity, we'll use the weighted average cost price of all available stock entries
        // This is a reasonable approximation when we don't have exact information about which
        // specific stock entries were used for this sale
        $totalQuantity = $stockEntries->sum('quantity');
        $totalValue = $stockEntries->sum(function ($entry) {
            return $entry->quantity * $entry->unit_price;
        });

        // Calculate weighted average cost price
        return $totalQuantity > 0 ? $totalValue / $totalQuantity : $vente->product->cost_price;
    }
    public function getCostPriceFromStockEntry()
    {
        $costPrice = 0;
        $costStockEntry = StockEntry::where('product_id', $this->product_id)
            ->where("quantity", ">", 0)
            ->orderBy("created_at", "asc")
            ->first();
        if ($costStockEntry != null) {
            $costPrice = $this->product->cost_price;
        }
        return $costPrice;

    }
}
