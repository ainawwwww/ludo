<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $table = 'subscriptions';

    protected $fillable = [
        'user_id',
        'tier',
        'status',
        'started_at',
        'current_period_end',
        'cancelled_at',
        'auto_renew',
        'last_daily_reward_at',
    ];

    protected $casts = [
        'tier' => SubscriptionTier::class,
        'status' => SubscriptionStatus::class,
        'started_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew' => 'boolean',
        'last_daily_reward_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isActiveVip(): bool
    {
        return in_array($this->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::CANCELLED], true)
            && $this->current_period_end->isFuture();
    }

    public function canClaimDailyReward(): bool
    {
        if (!$this->isActiveVip()) {
            return false;
        }

        if ($this->last_daily_reward_at === null) {
            return true;
        }

        return $this->last_daily_reward_at->isBefore(now()->startOfDay());
    }
}
