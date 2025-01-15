<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ligne extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'zone_id',
        'livreur_id'
    ];

    /**
     * Get the zone that owns the ligne.
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Get the livreur that owns the ligne.
     */
    public function livreur()
    {
        return $this->belongsTo(Livreur::class);
    }

    /**
     * Get the customers for the ligne.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
