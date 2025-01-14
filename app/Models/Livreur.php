<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Livreur extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number'
    ];

    /**
     * Get the lignes for the livreur.
     */
    public function lignes()
    {
        return $this->hasMany(Ligne::class);
    }
}
