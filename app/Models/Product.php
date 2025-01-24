<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'price',
        'cost_price',
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
    ];

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }
} 