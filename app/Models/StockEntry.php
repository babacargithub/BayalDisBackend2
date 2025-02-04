<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockEntry extends Model
{
    use HasFactory;
    protected $fillable = ['product_id', 'quantity', 'quantity_left',"purchase_invoice_item_id",
    "unit_price",];
}
