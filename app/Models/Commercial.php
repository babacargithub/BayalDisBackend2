<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commercial extends Model
{
    protected $fillable = ['name', 'phone_number', 'gender'];

    public function clients(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }
} 