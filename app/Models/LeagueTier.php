<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeagueTier extends Model
{
    use HasFactory;

    protected $table = 'league_tiers';

    protected $fillable = [
        'name',
        'tier_order',
        'min_points',
        'max_points',
        'icon_url',
    ];

    protected function casts(): array
    {
        return [
            'tier_order' => 'integer',
            'min_points' => 'integer',
            'max_points' => 'integer',
        ];
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(LeagueDivision::class, 'league_tier_id');
    }

    /**
     * Get the league tier for the given point total.
     */
    public static function getTierForPoints(int $points): ?self
    {
        return static::where('min_points', '<=', $points)
            ->where(function ($query) use ($points) {
                $query->whereNull('max_points')
                      ->orWhere('max_points', '>=', $points);
            })
            ->orderByDesc('tier_order')
            ->first();
    }

    /**
     * Get the next tier above the current tier_order.
     */
    public static function getNextTier(int $currentTierOrder): ?self
    {
        return static::where('tier_order', '>', $currentTierOrder)
            ->orderBy('tier_order')
            ->first();
    }

    /**
     * Get the previous tier below the current tier_order.
     */
    public static function getPreviousTier(int $currentTierOrder): ?self
    {
        return static::where('tier_order', '<', $currentTierOrder)
            ->orderByDesc('tier_order')
            ->first();
    }
}
