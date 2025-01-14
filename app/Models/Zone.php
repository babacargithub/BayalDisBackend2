<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'gps_coordinates',
        'ville',
        'quartiers'
    ];

    /**
     * Get the lignes for the zone.
     */
    public function lignes()
    {
        return $this->hasMany(Ligne::class);
    }
}
