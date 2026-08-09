<?php

namespace App\Models;

use App\Enums\PlayerColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomPlayer extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'room_players';

    protected $fillable = [
        'room_id',
        'user_id',
        'seat_position',
        'color',
        'is_ready',
        'score',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'seat_position' => 'integer',
            'color' => PlayerColor::class,
            'is_ready' => 'boolean',
            'score' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
