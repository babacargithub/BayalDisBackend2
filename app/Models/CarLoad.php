<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CarLoad extends Model
{
    protected $fillable = [
        'name',
        'load_date',
        'return_date',
        'team_id',
        'status', // LOADING, ACTIVE, UNLOADED
        'comment',
        "returned",
        'previous_car_load_id'
    ];

    protected $casts = [
        'load_date' => 'datetime',
        'return_date' => 'datetime',
        'returned' => 'boolean'
    ];
    protected $appends = ['stock_value'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CarLoadItem::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(CarLoadInventory::class);
    }

    public function previousCarLoad(): BelongsTo
    {
        return $this->belongsTo(CarLoad::class, 'previous_car_load_id');
    }

    public function nextCarLoad(): HasMany
    {
        return $this->hasMany(CarLoad::class, 'previous_car_load_id');
    }
    public function getTotalQuantityLoadedOfProduct(Product $product){
        return $this->items()->where('product_id', $product->id)->sum('quantity_loaded');
    }
    public function getTotalQuantityLeftOfProduct(Product $product){
        return $this->items()->where('product_id', $product->id)->sum('quantity_left');
    }
    public function increaseStockOfProduct(Product $product, int $quantity): void
    {
        // use the items to increase the stock using the FIFO method
        try {
            $item = $this->items()->where('product_id', $product->id)->orderBy('loaded_at', 'desc')->firstOrFail();
            $item->quantity_left += $quantity;
            $item->save();
        } catch (ModelNotFoundException $e) {
            throw new \Exception("Ce produit n'est pas dans ce chargement du véhicule :".$this->name);
        }

    }

    /**
     * @throws \Exception
     */
    public function decreaseStockOfProduct(Product $product, int $quantity): void
    {
        if ($this->getTotalQuantityLeftOfProduct($product) < $quantity) {
            throw new \Exception('Stock insuffisant pour le produit ' . $product->name . ' dans le véhicule '
                . $this->name.'. Qté restante : '.$this->getTotalQuantityLeftOfProduct($product));
        }
        // use the items to decrease the stock using the FIFO method, if the qunatity is not enough, loop through the
        // items until the quantity is enough, if list is exhausted, throw an exception
        $items = $this->items()->where('product_id', $product->id)->orderBy('loaded_at', 'asc')->get();
        $quantityLeftToProcess = $quantity;
        foreach ($items as $item) {
            if ($item->quantity_left >= $quantityLeftToProcess) {
                $item->quantity_left -= $quantityLeftToProcess;
                $item->save();
                $quantityLeftToProcess= 0;
                break;
            } else {
                $quantityLeftToProcess -= $item->quantity_left;
                $item->quantity_left = 0;
                $item->save();
            }
        }
        if ($quantityLeftToProcess > 0) {
            throw new \Exception('Not enough stock');

        }
    }

    public function getStockValueAttribute(): int
    {
    
        $items = $this->items()->get();
        $totalValue = 0;
        foreach ($items as $item) {
            $totalValue += $item->quantity_left * $item->product->cost_price;
        }
        return $totalValue;
    }

    public static function findCarLoadItemForProductAndCommercial(Product $product, Commercial $commercial)
    {
        $carload = CarLoad::where("returned", false)
        ->where("team_id", $commercial->team_id)
        ->where("return_date",">", now()->toDateString())
        ->first();
    if ($carload == null){ 
        return null;
      }
        $carLoadItem = $carload->items()
            ->where("product_id", $product->id)
            ->latest()
            ->first();
        return $carLoadItem;
    }
    
} 