<?php

namespace App\Services\Events;

use App\Models\User;

class RewardMultiplierService
{
    /**
     * Calculate final reward amount based on user VIP status and active bonuses.
     */
    public function getMultiplier(User $user): float
    {
        $multiplier = 1.0;

        // VIP 2x multiplier perk
        if ($user->is_vip) {
            $multiplier *= 2.0;
        }

        return $multiplier;
    }

    /**
     * Calculate multiplied reward.
     */
    public function calculateReward(User $user, int $baseAmount): int
    {
        $multiplier = $this->getMultiplier($user);
        return (int) round($baseAmount * $multiplier);
    }
}
