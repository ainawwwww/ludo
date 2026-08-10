<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentParticipant extends Model
{
    use HasFactory;

    protected $table = 'tournament_participants';

    protected $fillable = [
        'tournament_id',
        'user_id',
        'current_level',
        'highest_level_reached',
        'status',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'current_level' => 'integer',
            'highest_level_reached' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
