<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDailyTask extends Model
{
    use HasFactory;

    protected $table = 'user_daily_tasks';

    protected $fillable = [
        'user_id',
        'task_key',
        'title',
        'reward_type',
        'reward_amount',
        'current_progress',
        'total_progress',
        'is_claimed',
        'task_date',
    ];

    protected function casts(): array
    {
        return [
            'reward_amount' => 'integer',
            'current_progress' => 'integer',
            'total_progress' => 'integer',
            'is_claimed' => 'boolean',
            'task_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->current_progress >= $this->total_progress;
    }
}
