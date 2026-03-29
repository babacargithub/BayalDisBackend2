<?php

namespace App\Models;

use App\Services\SalesInvoiceStatsService;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Vente extends Model
{

    const PAYMENT_METHOD_CASH = 'CASH';

    const PAYMENT_METHOD_WAVE = 'WAVE';

    const PAYMENT_METHOD_OM = 'OM';

    const TYPE_INVOICE = 'INVOICE_ITEM';

    const TYPE_SINGLE = 'SINGLE';

    protected $fillable = [
        'customer_id',
        'product_id',
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
        'should_be_paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Vente $vente) {

            if ($vente->type === 'SINGLE' && ! $vente->customer_id) {
                throw new Exception('Customer ID is required for single ventes');
            }
        });

        /**
         * After any invoice item is saved, recalculate the parent invoice's stored
         * totals so total_amount, total_estimated_profit, status, and paid stay in sync.
         */
        static::saved(function (Vente $vente) {
            if ($vente->sales_invoice_id !== null) {
                SalesInvoice::find($vente->sales_invoice_id)?->recalculateStoredTotals();
            }
        });

        /**
         * After an invoice item is deleted, recalculate the parent invoice's stored
         * totals so the balance reflects the removed item.
         */
        static::deleted(function (Vente $vente) {
            if ($vente->sales_invoice_id !== null) {
                SalesInvoice::find($vente->sales_invoice_id)?->recalculateStoredTotals();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
        return $value != null ? $this->asDateTime($value) : null;
    }

    public function getCustomerAttribute(): ?Customer
    {
        if ($this->isInvoiceItem()) {
            return $this->salesInvoice?->customer;
        }

        return Customer::whereId($this->customer_id)->first();

    }

    public function calculateProfit(): void
    {
        if ($this->product) {
            $this->profit = app(SalesInvoiceStatsService::class)->calculateProfitForVente($this);
        }
    }
}
