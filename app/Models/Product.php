<?php

namespace App\Models;

use App\Services\ProductService;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'base_quantity',
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

    public function stockEntries(): HasMany
    {
        return $this->hasMany(StockEntry::class, 'product_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants(): \Illuminate\Database\Eloquent\Builder|HasMany|Product
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    /** @noinspection PhpUnused */
    public function getIsBaseProductAttribute(): bool
    {
        return is_null($this->parent_id);
    }

    /** @noinspection PhpUnused */
    public function getStockAvailableAttribute(): int
    {
        return app(ProductService::class)->getProductAvailableStockInWarehouse($this);
    }

    /** @noinspection PhpUnused */
    public function getStockValueAttribute(): int
    {
        return app(ProductService::class)->getProductWarehouseStockValue($this);
    }

    public function getTotalSoldAttribute(): int
    {
        return app(ProductService::class)->getTotalQuantitySold($this);
    }

    /**
     * @return array{parent_quantity: int, remaining_variant_quantity: int, decimal_parent_quantity: float}
     */
    #[ArrayShape(['parent_quantity' => 'int', 'remaining_variant_quantity' => 'int', 'decimal_parent_quantity' => 'float'])]
    public function convertQuantityToParentQuantity(int|float $quantity): array
    {
        return app(ProductService::class)->convertVariantQuantityToParentQuantity($this, $quantity);
    }

    /**
     * @throws Exception
     */
    public function decrementStock(int $quantity, bool $updateMainStock, ?CarLoad $carLoad): self
    {
        app(ProductService::class)->decrementStock($this, $quantity, $updateMainStock, $carLoad);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function incrementStock(int $quantity, bool $updateMainStock = false): self
    {
        app(ProductService::class)->increaseStock($this, $quantity, $updateMainStock);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function getStockEntry(): StockEntry
    {
        return app(ProductService::class)->getOldestNonEmptyStockEntryInWarehouse($this);
    }

    /**
     * @return array{cartons: int, paquets: int, first_variant_name: string}
     */
    #[ArrayShape(['cartons' => 'integer', 'paquets' => 'integer', 'first_variant_name' => 'string'])]
    public function getFormattedDisplayOfCartonAndParquets(float $quantity): array
    {
        return app(ProductService::class)->getFormattedDisplayOfCartonAndPaquets($this, $quantity);
    }

    public function clearFormattedDisplayCache(): void
    {
        app(ProductService::class)->clearFirstVariantCache($this);
    }

    protected static function booted(): void
    {
        static::updated(function (Product $product) {
            $product->clearFormattedDisplayCache();
        });

        static::deleted(function (Product $product) {
            $product->clearFormattedDisplayCache();
        });
    }
}
