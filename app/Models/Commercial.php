<?php namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Commercial extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number',
        'gender',
        'secret_code',
        'user_id',
    ];

    protected $hidden = [
        'secret_code',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function verifySecretCode(string $secretCode): bool
    {
        return Hash::check($secretCode, $this->secret_code);
    }

    public static function authenticate(string $phoneNumber, string $secretCode): ?Commercial
    {
        $commercial = self::where('phone_number', $phoneNumber)->first();

        if (!$commercial || !$commercial->verifySecretCode($secretCode)) {
            return null;
        }

        return $commercial;
    }
} 