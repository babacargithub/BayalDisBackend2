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
        'product_category_id',
        'description',
        'price',
        'credit_price',
        'cost_price',
        'packaging_cost',
        'weight_kg',
        'volume_m3',
        'parent_id',
        'base_quantity',
    ];

    protected $casts = [
        'price' => 'integer',
        'credit_price' => 'integer',
        'cost_price' => 'integer',
        'packaging_cost' => 'integer',
        'weight_kg' => 'decimal:3',
        'volume_m3' => 'decimal:3',
        'base_quantity' => 'integer',
        'product_category_id' => 'integer',
    ];

    protected $appends = ['is_base_product'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function productCommissionRates(): HasMany
    {
        return $this->hasMany(CommercialProductCommissionRate::class);
    }

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

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
