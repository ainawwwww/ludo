<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserInventory;
use App\Models\StoreItem;

class BadgeService
{
    /**
     * Level badge thresholds mapping level ranges to badge tiers.
     */
    private const LEVEL_BADGES = [
        ['min' => 1,  'max' => 9,   'name' => 'Bronze',   'icon' => '/images/badges/level_bronze.png'],
        ['min' => 10, 'max' => 24,  'name' => 'Silver',   'icon' => '/images/badges/level_silver.png'],
        ['min' => 25, 'max' => 49,  'name' => 'Gold',     'icon' => '/images/badges/level_gold.png'],
        ['min' => 50, 'max' => 999, 'name' => 'Platinum', 'icon' => '/images/badges/level_platinum.png'],
    ];

    /**
     * Get the level badge for a given user level.
     *
     * @param int $level
     * @return array{name: string, icon: string, level: int}
     */
    public function getLevelBadge(int $level): array
    {
        foreach (self::LEVEL_BADGES as $badge) {
            if ($level >= $badge['min'] && $level <= $badge['max']) {
                return [
                    'name' => $badge['name'],
                    'icon' => $badge['icon'],
                    'level' => $level,
                ];
            }
        }

        // Fallback for levels beyond defined thresholds
        return [
            'name' => 'Platinum',
            'icon' => '/images/badges/level_platinum.png',
            'level' => $level,
        ];
    }

    /**
     * Get the user's favorite (equipped) dice skin name from their inventory.
     *
     * @param User $user
     * @return string|null
     */
    public function getFavoriteDice(User $user): ?string
    {
        $equippedDice = $user->inventory()
            ->wherePivot('is_equipped', true)
            ->where('type', \App\Enums\StoreItemType::DICE_SKIN->value)
            ->first();

        return $equippedDice?->name;
    }
}
