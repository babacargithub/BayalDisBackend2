<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JetBrains\PhpStorm\ArrayShape;

class BeatStop extends Model
{
    const STATUS_PLANNED = 'planned';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'customer_id',
        'beat_id',
        'visit_date',
        'visit_planned_at',
        'visited_at',
        'status',
        'notes',
        'resulted_in_sale',
        'gps_coordinates',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'visit_planned_at' => 'datetime',
        'visited_at' => 'datetime',
        'resulted_in_sale' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function beat(): BelongsTo
    {
        return $this->belongsTo(Beat::class);
    }

    /**
     * @param array $data
     * @return void
     */

    public function complete( #[ArrayShape([
        'notes' => 'string|null',
        'resulted_in_sale' => 'bool|null',
        'gps_coordinates' => 'string|null'
    ])] 
    array $data): void
    {
        $this->update([
            'visited_at' => now(),
            'status' => self::STATUS_COMPLETED,
            'notes' => $data['notes'] ?? null,
            'resulted_in_sale' => $data['resulted_in_sale'] ?? false,
            'gps_coordinates' => $data['gps_coordinates'] ?? null,
        ]);
    }

    public function cancel(string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'notes' => $notes,
        ]);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }
} 