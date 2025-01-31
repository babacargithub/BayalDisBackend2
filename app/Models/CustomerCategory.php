<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_category_id');
    }
}
