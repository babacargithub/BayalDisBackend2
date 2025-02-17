<?php

namespace App\Models;

use Exception;
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

    public function decrementStock(int $quantity): self
    {
        $totalAvailableStock = $this->stockEntries()->sum('quantity_left');

        if ($totalAvailableStock < $quantity) {
            throw new Exception("Stock insuffisant pour ".$this->name." . Stock disponible: {$totalAvailableStock}, Quantité demandée: {$quantity}");
        }
        // decrement stock using FIFO method
        $stockEntries = $this->stockEntries()->orderBy('created_at', 'asc')->get();
        $remainingQuantity = $quantity;

        foreach ($stockEntries as $stockEntry) {
            if ($stockEntry->quantity_left >= $remainingQuantity) {
                $stockEntry->decrement('quantity_left', $remainingQuantity);
                break;
            }

            $remainingQuantity -= $stockEntry->quantity_left;
            $stockEntry->quantity_left = 0;
            $stockEntry->save();
        }
        return $this;

    }
    public function incrementStock(int $quantity): self
    {
        // increment stock using FIFO method
        $stockEntries = $this->stockEntries()->orderBy('created_at', 'asc')->get();
        $remainingQuantity = $quantity;

        foreach ($stockEntries as $stockEntry) {
            if ($stockEntry->quantity_left >= $remainingQuantity) {
                $stockEntry->increment('quantity_left', $remainingQuantity);
                $stockEntry->save();
                break;
            }

            $remainingQuantity -= $stockEntry->quantity_left;
            $stockEntry->save();
        }   

        $this->save();
        return $this;
    }

    /**
     * @throws Exception
     */
    public function getStockEntry() : StockEntry
    {
        $stockEntry = $this->stockEntries()->where('quantity_left', '>', 0)->orderBy('created_at', 'asc')->first();
        if (!$stockEntry) {
            throw new Exception("Aucun stock disponible pour le produit {$this->name}");
        }
        return $stockEntry;
    }
}
