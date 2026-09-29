<?php

namespace App\Enums;

enum SubscriptionTier: string
{
    case KNIGHT = 'knight';
    case BARON = 'baron';

    public function price(): float
    {
        return match ($this) {
            self::KNIGHT => 4.99,
            self::BARON => 14.99,
        };
    }

    public function dailyCoins(): int
    {
        return match ($this) {
            self::KNIGHT => 200,
            self::BARON => 500,
        };
    }

    public function dailyDiamonds(): int
    {
        return match ($this) {
            self::KNIGHT => 5,
            self::BARON => 15,
        };
    }
}
