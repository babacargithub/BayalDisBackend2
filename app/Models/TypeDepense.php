<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeDepense extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }
} 