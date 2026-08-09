<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'device_id',
        'username',
        'email',
        'phone',
        'password',
        'avatar_url',
        'country',
        'country_code',
        'google_id',
        'auth_provider',
        'level',
        'xp',
        'is_active',
        'is_guest',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'level' => 'integer',
            'xp' => 'integer',
            'is_active' => 'boolean',
            'is_guest' => 'boolean',
        ];
    }

    // Accessors for coins and diamonds from associated wallet
    public function getCoinsAttribute(): int
    {
        if ($this->relationLoaded('wallet') && $this->wallet !== null) {
            return (int) $this->wallet->coins_balance;
        }
        $wallet = Wallet::where('user_id', $this->id)->first();
        return $wallet ? (int) $wallet->coins_balance : 0;
    }

    public function getDiamondsAttribute(): int
    {
        if ($this->relationLoaded('wallet') && $this->wallet !== null) {
            return (int) $this->wallet->diamonds_balance;
        }
        $wallet = Wallet::where('user_id', $this->id)->first();
        return $wallet ? (int) $wallet->diamonds_balance : 0;
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_id');
    }

    public function inventory(): BelongsToMany
    {
        return $this->belongsToMany(StoreItem::class, 'user_inventory', 'user_id', 'item_id')
                    ->withPivot('is_equipped', 'purchased_at');
    }

    public function friends(): HasMany
    {
        return $this->hasMany(Friend::class, 'user_id');
    }
}
