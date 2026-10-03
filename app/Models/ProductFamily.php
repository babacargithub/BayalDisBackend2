<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A display/grouping label for related products (e.g. "Tasse à jeter" in category JETABLES).
 * It carries no pricing, stock or commission logic.
 */
class ProductFamily extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'product_category_id',
    ];

    protected function casts(): array
    {
        return [
            'product_category_id' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_family_id');
    }
}
