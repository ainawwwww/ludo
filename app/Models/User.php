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
        'metadata',
        'gender',
        'dob',
        'bio',
        'name_change_count',
        'name_change_reset_at',
        'league_points',
        'rank',
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
            'metadata' => 'array',
            'dob' => 'date',
            'name_change_count' => 'integer',
            'name_change_reset_at' => 'datetime',
            'league_points' => 'integer',
            'rank' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('level')) {
                $oldLevel = (int) $user->getOriginal('level');
                $newLevel = (int) $user->level;
                if ($oldLevel < 4 && $newLevel >= 4) {
                    app(\App\Services\League\LeagueSeasonService::class)->enrollUserIfEligible($user);
                }
            }
        });
    }

    /**
     * Add XP to user and update level.
     */
    public function addXp(int $amount): void
    {
        $this->xp = (int) ($this->xp ?? 0) + $amount;
        $newLevel = max(1, (int) floor($this->xp / 100) + 1);
        if ($newLevel !== (int) $this->level) {
            $this->level = $newLevel;
        }
        $this->save();
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

    public function following(): HasMany
    {
        return $this->hasMany(Follow::class, 'user_id');
    }

    public function followers(): HasMany
    {
        return $this->hasMany(Follow::class, 'followed_user_id');
    }

    public function followingUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'user_id', 'followed_user_id');
    }

    public function followerUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followed_user_id', 'user_id');
    }

    public function roomVisits(): HasMany
    {
        return $this->hasMany(RoomVisit::class, 'user_id');
    }

    /**
     * Get all room_players records for this user (used to compute game stats).
     */
    public function roomPlayers(): HasMany
    {
        return $this->hasMany(RoomPlayer::class, 'user_id');
    }

    /**
     * Compute total games played by counting distinct rooms with completed games.
     */
    public function getTotalGamesPlayedAttribute(): int
    {
        return RoomPlayer::where('user_id', $this->id)
            ->whereHas('room', function ($query) {
                $query->whereHas('game', function ($q) {
                    $q->where('status', \App\Enums\GameStatus::COMPLETED->value);
                });
            })
            ->count();
    }

    /**
     * Compute total wins from completed games.
     */
    public function getTotalWinsAttribute(): int
    {
        return Game::where('winner_id', $this->id)
            ->where('status', \App\Enums\GameStatus::COMPLETED->value)
            ->count();
    }

    /**
     * Compute total losses from completed games.
     */
    public function getTotalLossesAttribute(): int
    {
        return $this->total_games_played - $this->total_wins;
    }

    /**
     * Compute win rate as a percentage.
     */
    public function getWinRateAttribute(): float
    {
        $totalGames = $this->total_games_played;
        if ($totalGames === 0) {
            return 0.0;
        }
        return round(($this->total_wins / $totalGames) * 100, 2);
    }
}
