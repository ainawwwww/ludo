<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaderboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalWins = (int) ($this->total_wins ?? 0);
        $totalGames = (int) ($this->total_games ?? 0);
        $winRate = $totalGames > 0 ? round(($totalWins / $totalGames) * 100, 2) : 0.00;

        return [
            'rank' => $this->rank ?? null,
            'user_id' => $this->id,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'country' => $this->country,
            'total_wins' => $totalWins,
            'total_games' => $totalGames,
            'win_rate' => $winRate,
        ];
    }
}
