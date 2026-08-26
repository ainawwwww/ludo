<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeagueDivisionMember extends Model
{
    use HasFactory;

    protected $table = 'league_division_members';

    protected $fillable = [
        'league_division_id',
        'user_id',
        'points_in_division',
        'final_rank',
        'result',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'league_division_id' => 'integer',
            'user_id' => 'integer',
            'points_in_division' => 'integer',
            'final_rank' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(LeagueDivision::class, 'league_division_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
