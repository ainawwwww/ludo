<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tier' => $this->tier instanceof \App\Enums\SubscriptionTier ? $this->tier->value : $this->tier,
            'status' => $this->status instanceof \App\Enums\SubscriptionStatus ? $this->status->value : $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'auto_renew' => (bool) $this->auto_renew,
            'can_claim_daily_reward' => $this->canClaimDailyReward(),
            'daily_rewards' => [
                'coins' => $this->tier instanceof \App\Enums\SubscriptionTier ? $this->tier->dailyCoins() : 0,
                'diamonds' => $this->tier instanceof \App\Enums\SubscriptionTier ? $this->tier->dailyDiamonds() : 0,
            ],
        ];
    }
}
