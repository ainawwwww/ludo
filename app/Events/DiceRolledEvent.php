<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiceRolledEvent
{
    use Dispatchable, SerializesModels;

    public int $userId;
    public int $diceValue;

    public function __construct(int $userId, int $diceValue)
    {
        $this->userId = $userId;
        $this->diceValue = $diceValue;
    }
}
