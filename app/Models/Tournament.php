<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournament extends Model
{
    use HasFactory;

    protected $table = 'tournaments';

    protected $fillable = [
        'name',
        'mode',
        'entry_fee',
        'currency_type',
        'prize_pool',
        'max_level',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'entry_fee' => 'integer',
            'prize_pool' => 'integer',
            'max_level' => 'integer',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(TournamentLevel::class, 'tournament_id')->orderBy('level');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class, 'tournament_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class, 'tournament_id');
    }
}
