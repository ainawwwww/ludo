<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentLevel extends Model
{
    use HasFactory;

    protected $table = 'tournament_levels';

    protected $fillable = [
        'tournament_id',
        'level',
        'reward_coins',
        'reward_diamonds',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'reward_coins' => 'integer',
            'reward_diamonds' => 'integer',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }
}
