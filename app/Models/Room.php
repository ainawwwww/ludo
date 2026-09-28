<?php

namespace App\Models;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Room extends Model
{
    use HasFactory;

    public $timestamps = true;
    protected $table = 'rooms';

    protected $fillable = [
        'room_code',
        'title',
        'category',
        'tags',
        'country_code',
        'cover_image',
        'member_count',
        'is_live',
        'type',
        'max_players',
        'turn_seconds',
        'entry_fee',
        'status',
        'state_version',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'member_count' => 'integer',
            'is_live' => 'boolean',
            'type' => RoomType::class,
            'status' => RoomStatus::class,
            'state_version' => 'integer',
            'max_players' => 'integer',
            'turn_seconds' => 'integer',
            'entry_fee' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class, 'room_id');
    }

    public function game(): HasOne
    {
        return $this->hasOne(Game::class, 'room_id');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'room_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(RoomVisit::class, 'room_id');
    }
}
