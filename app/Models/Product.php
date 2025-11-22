<?php

namespace App\Models;

use App\Services\CarLoadService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use JetBrains\PhpStorm\ArrayShape;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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

    protected $appends = ['is_base_product'];

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class, 'product_id');
    }

    public function getStockAvailableAttribute()
    {
        // TODO check later why this not returning the correct value
        return $this->stockEntries()->sum('quantity_left');
    }

    public function getStockValueAttribute()
    {
        return $this->stockEntries()
            ->selectRaw('SUM(quantity_left * unit_price) as value')
            ->value('value') ?? 0;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants(): \Illuminate\Database\Eloquent\Builder|HasMany|Product
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    public function getIsBaseProductAttribute(): bool
    {
        return is_null($this->parent_id);
    }

    #[ArrayShape(['parent_quantity' => "int", 'remaining_variant_quantity' => "int","decimal_parent_quantity" => "float"])]
    public  function convertQuantityToParentQuantity($quantity): array
    {
        // Get the ratio between parent and variant quantities
        if ($this->is_base_product) {
            return [
                'parent_quantity' => 0,
                'remaining_variant_quantity' => 0
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
            "decimal_parent_quantity" => $quantity / $ratio,
            'remaining_variant_quantity' => intval($remainingVariantUnits)
        ];
    }
    public function getTotalSoldAttribute()
    {
        return $this->ventes()->sum('quantity');
    }

    /**
     * @throws Exception
     */
    public function decrementStock(int $quantity, bool $updateMainStock = false, ?Commercial $commercial): self
    {

        if ($commercial && !$updateMainStock) {
            self::decreaseStockForProductInCarLoad($this, $quantity, $commercial);
            return  $this;
        }
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

    /**
     * @throws Exception
     */
    public function incrementStock(int $quantity, bool $updateMainStock = false): self
    {

        // increment stock using FIFO method
        if ($updateMainStock) {
            $stockEntry = $this->stockEntries()->orderBy('created_at', 'asc')->latest()->firstOrFail();
            $stockEntry->quantity_left += $quantity;
            $stockEntry->save();
            $stockEntry->refresh();
        } else {
            // find current car load 
            $commercial = auth()->user()->commercial;
            if ($commercial) {
                self::increaseQuantityLeftOfProductInCarLoad($this, $quantity);
                return  $this;
            }
            
        }
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
    /**
     * @throws \Exception
     */
    public static  function decreaseStockForProductInCarLoad(Product $product, int $quantity, Commercial $commercial)
    {
        // check if user is commercial
        $carLoadService = app(CarLoadService::class);
            $carLoad = $carLoadService->getCurrentCarLoadForTeam($commercial->team);
            if ($carLoad == null) {
                throw new UnprocessableEntityHttpException('Pour pourvoir faire une vente, il faut un chargement de véhicule attribué à votre équipe !',
                );
            }
            if ($carLoad->getTotalQuantityLeftOfProduct($product) < $quantity) {
                throw new UnprocessableEntityHttpException('Stock insuffisant pour le produit ' . $product->name . " dans le véhicule " . $carLoad->name.'. Qté restante : '.$carLoad->getTotalQuantityLeftOfProduct($product),
                );
            }
            $carLoad->decreaseStockOfProduct($product, $quantity);


    } public static  function increaseQuantityLeftOfProductInCarLoad(Product $product, int $quantity, Commercial $commercial)
        {
        // check if user is commercial
        $carLoadService = app(CarLoadService::class);

         if ($commercial) {
            $carLoad = $carLoadService->getCurrentCarLoadForTeam($commercial->team);

            if ($carLoad == null) {
                throw new UnprocessableEntityHttpException('Pour pourvoir faire une vente, il faut un chargement de véhicule attribué à votre équipe !',
                );
            }
            $carLoadItem = $carLoad->items()->where('product_id', $product->id)->firstOrFail();
            $carLoadItem->quantity_left = $quantity;
            $carLoadItem->save();
        }

    }
    #[ArrayShape(['cartons' => "integer",'paquets'=>'integer','first_variant_name'=>'string'])]
    public function getFormattedDisplayOfCartonAndParquets(float $quantity): array
    {

        $result = [
            'cartons' => intval($quantity),
            'paquets' => 0,
            'first_variant_name'=>''
        ];
        /** @var Product $firstVariant */
        $firstVariant = $this->variants()->first();
        if ($firstVariant) {
            $number = $quantity;
            $decimal = $number - floor($number);
            $result['paquets'] = (int)number_format(($decimal * ($this->base_quantity / $firstVariant->base_quantity))
                ,0);
            $result['first_variant_name'] = $firstVariant->name;
        }

        return $result;

    }


}
