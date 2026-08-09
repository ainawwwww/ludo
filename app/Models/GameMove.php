<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameMove extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'game_moves';

    protected $fillable = [
        'game_id',
        'user_id',
        'move_number',
        'token_id',
        'from_pos',
        'to_pos',
        'dice_value',
        'is_kill',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'move_number' => 'integer',
            'token_id' => 'integer',
            'from_pos' => 'integer',
            'to_pos' => 'integer',
            'dice_value' => 'integer',
            'is_kill' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
