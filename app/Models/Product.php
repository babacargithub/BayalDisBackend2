<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use JetBrains\PhpStorm\ArrayShape;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'cost_price',
        'parent_id',
        'base_quantity'
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
        'base_quantity' => 'integer',
    ];

    protected $appends = ['stock_available', 'stock_value', 'is_base_product'];

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

    public function parent()
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants()
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    public function getIsBaseProductAttribute()
    {
        return is_null($this->parent_id);
    }

    #[ArrayShape(['parent_quantity' => "int", 'remaining_variant_quantity' => "int"])]
    public  function convertQuantityToParentQuantity($quantity): array
    {
        // Get the ratio between parent and variant quantities
        if ($this->is_base_product) {
            return [
                'parent_quantity' => 0,
                'remaining_variant_quantity' => $quantity
            ];
        }
        $parent = $this->parent;
        $ratio = $parent->base_quantity / $this->base_quantity;

        // Calculate how many complete parent units are needed
        $parentUnits = ceil($quantity / $ratio);
        
        // Calculate remaining variant units after conversion
        $remainingVariantUnits = ($parentUnits * $ratio) - $quantity;
        
        return [
            'parent_quantity' => intval($parentUnits),
            'remaining_variant_quantity' => intval($remainingVariantUnits)
        ];
    }
    public function getTotalSoldAttribute()
    {
        return $this->ventes()->sum('quantity');
    }
}
