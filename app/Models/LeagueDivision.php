<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeagueDivision extends Model
{
    use HasFactory;

    protected $table = 'league_divisions';

    protected $fillable = [
        'league_season_id',
        'league_tier_id',
        'division_number',
        'max_players',
    ];

    protected function casts(): array
    {
        return [
            'league_season_id' => 'integer',
            'league_tier_id' => 'integer',
            'division_number' => 'integer',
            'max_players' => 'integer',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class, 'league_season_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LeagueTier::class, 'league_tier_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LeagueDivisionMember::class, 'league_division_id');
    }
}
