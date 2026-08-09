<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    use HasFactory;

    protected $table = 'leagues';

    protected $fillable = [
        'name',
        'min_points',
        'max_points',
        'icon_url',
        'tier_order',
    ];

    protected function casts(): array
    {
        return [
            'min_points' => 'integer',
            'max_points' => 'integer',
            'tier_order' => 'integer',
        ];
    }

    /**
     * Get the league tier for the given point total.
     */
    public static function getLeagueForPoints(int $points): ?self
    {
        return static::where('min_points', '<=', $points)
            ->where('max_points', '>=', $points)
            ->first();
    }

    /**
     * Get the next league tier above the given tier_order. Returns null if already at the top.
     */
    public static function getNextLeague(int $currentTierOrder): ?self
    {
        return static::where('tier_order', '>', $currentTierOrder)
            ->orderBy('tier_order')
            ->first();
    }
}
