<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;

class Commercial extends Model
{
    protected $fillable = [
        'name',
        'phone_number',
        'gender',
        'salary',
        'secret_code',
        'user_id',
    ];

    protected $hidden = [
        'secret_code',
    ];

    protected function casts(): array
    {
        return [
            'salary' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'user_id', 'user_id');
    }

    public function categoryCommissionRates(): HasMany
    {
        return $this->hasMany(CommercialCategoryCommissionRate::class);
    }

    public function productCommissionRates(): HasMany
    {
        return $this->hasMany(CommercialProductCommissionRate::class);
    }

    public function workPeriods(): HasMany
    {
        return $this->hasMany(CommercialWorkPeriod::class);
    }

    public function commissions(): HasManyThrough
    {
        return $this->hasManyThrough(DailyCommission::class, CommercialWorkPeriod::class);
    }

    public function objectiveTiers(): HasManyThrough
    {
        return $this->hasManyThrough(CommercialObjectiveTier::class, CommercialWorkPeriod::class);
    }

    public function penalties(): HasManyThrough
    {
        return $this->hasManyThrough(CommercialPenalty::class, CommercialWorkPeriod::class);
    }

    public function newCustomerCommissionSetting(): HasOne
    {
        return $this->hasOne(CommercialNewCustomerCommissionSetting::class);
    }

    public function verifySecretCode(string $secretCode): bool
    {
        return Hash::check($secretCode, $this->secret_code);
    }

    public static function authenticate(string $phoneNumber, string $secretCode): ?Commercial
    {
        $commercial = self::where('phone_number', $phoneNumber)->first();

        if (! $commercial || ! $commercial->verifySecretCode($secretCode)) {
            return null;
        }

        return $commercial;
    }
}
