<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'purchase_invoice_item_id',
        'quantity',
        'quantity_left',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'quantity_left' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseInvoiceItem()
    {
        return $this->belongsTo(PurchaseInvoiceItem::class);
    }
}
