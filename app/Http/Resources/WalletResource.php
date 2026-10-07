<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'coins' => (int) ($this->coins_balance ?? 0),
            'diamonds' => (int) ($this->diamonds_balance ?? 0),
            'coins_balance' => (int) ($this->coins_balance ?? 0),
            'diamonds_balance' => (int) ($this->diamonds_balance ?? 0),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
