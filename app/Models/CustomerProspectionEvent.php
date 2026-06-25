<?php

namespace App\Models;

use App\Enums\ProspectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProspectionEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'commercial_id',
        'status',
        'notes',
        'scheduled_revisit_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProspectionStatus::class,
            'scheduled_revisit_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function commercial(): BelongsTo
    {
        return $this->belongsTo(Commercial::class);
    }

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (CustomerProspectionEvent $event): void {
            $customer = Customer::find($event->customer_id);

            if ($customer === null) {
                return;
            }

            $customer->current_prospect_status = $event->status->value;

            if ($event->status === ProspectionStatus::Acquired) {
                $customer->is_prospect = false;
            }

            $customer->save();
        });
    }
}
