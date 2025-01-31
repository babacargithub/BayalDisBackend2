<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'comment',
        'notes'
    ];
    protected $appends = ["total_amount", "paid_amount"];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'integer',
        'paid_amount' => 'integer'
    ];

    public function getTotalAmountAttribute()
    {
        return $this->items->sum('total_price');
    }

    public function getPaidAmountAttribute()
    {
        return $this->items->sum('total_price');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }
}
