<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Caisse extends Model
{
    protected $fillable = [
        'name',
        'balance',
        'closed'
    ];

    protected $casts = [
        'closed' => 'boolean',
        'balance' => 'integer',
    ];
} 