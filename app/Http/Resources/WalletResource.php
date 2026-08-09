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
            'coins_balance' => $this->coins_balance,
            'diamonds_balance' => $this->diamonds_balance,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
