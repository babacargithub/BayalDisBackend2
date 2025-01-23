<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vente extends Model
{
    use HasFactory;

    const PAYMENT_METHOD_CASH = 'CASH';
    const PAYMENT_METHOD_WAVE = 'WAVE';
    const PAYMENT_METHOD_OM = 'OM';

    protected $fillable = [
        'customer_id',
        'product_id',
        'commercial_id',
        'quantity',
        'price',
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
        'should_be_paid_at' => 'datetime',
        'paid_at' => 'datetime',
        'order_id' => 'integer',
    ];

    protected $with = ['product', 'order'];

    protected $appends = ['subtotal', 'customer_name'];

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
        return $this->customer;
    }

    // Boot method to add validation
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($vente) {
            if ($vente->type === 'SINGLE' && !$vente->customer_id) {
                throw new \Exception('Customer ID is required for single ventes');
            }
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
    public function getShouldBePaidAtAttribute($value)
    {
        if ($this->sales_invoice_id) {
            return $this->salesInvoice->should_be_paid_at;
        }
        return $value;
    }
} 