<?php

namespace App\Services;

use App\Models\Game;
use App\Models\RoomPlayer;
use App\Models\User;

class LeagueService
{
    /**
     * Award/deduct league points for a completed game.
     *
     * - Winner gains config('ludo.league_points_win', 25)
     * - Losers lose config('ludo.league_points_loss', 10), clamped at 0 min
     *
     * @param Game $game
     * @return void
     */
    public function awardLeaguePoints(Game $game): void
    {
        if (!$game->winner_id || !$game->room_id) {
            return;
        }

        $winAmount = (int) config('ludo.league_points_win', 25);
        $lossAmount = (int) config('ludo.league_points_loss', 10);

        // Award winner
        $winner = User::find($game->winner_id);
        if ($winner) {
            $winner->increment('league_points', $winAmount);
        }

        // Penalty for losers (other players in the room)
        $roomPlayers = RoomPlayer::where('room_id', $game->room_id)
            ->where('user_id', '!=', $game->winner_id)
            ->get();

        foreach ($roomPlayers as $rp) {
            $loser = User::find($rp->user_id);
            if ($loser) {
                $currentPoints = (int) ($loser->league_points ?? 0);
                $newPoints = max(0, $currentPoints - $lossAmount);
                $loser->update(['league_points' => $newPoints]);
            }
        }
    }
}
