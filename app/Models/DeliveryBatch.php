<?php namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'delivery_date',
        'livreur_id',
    ];

    protected $casts = [
        'delivery_date' => 'date',
    ];

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_batch_id');
    }
} 