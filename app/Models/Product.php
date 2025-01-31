<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'cost_price',
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
    ];

    protected $appends = ['stock_available', 'stock_value'];

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }

    public function getStockAvailableAttribute()
    {
        return $this->stockEntries()->sum('quantity_left');
    }

    public function getStockValueAttribute()
    {
        return $this->stockEntries()
            ->selectRaw('SUM(quantity_left * unit_price) as value')
            ->value('value') ?? 0;
    }
} 