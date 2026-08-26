<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeagueSeason extends Model
{
    use HasFactory;

    protected $table = 'league_seasons';

    protected $fillable = [
        'season_number',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'season_number' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(LeagueDivision::class, 'league_season_id');
    }

    public static function getActiveSeason(): ?self
    {
        return static::where('status', 'active')
            ->orderByDesc('id')
            ->first();
    }
}
