<?php

namespace App\Services;

use App\Models\Game;
use App\Models\LeagueDivisionMember;
use App\Models\LeagueSeason;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeagueService
{
    /**
     * Award/deduct league points for a completed game in a race-condition safe transaction.
     *
     * Updates both:
     * 1. users.league_points (lifetime tier-qualifying score)
     * 2. league_division_members.points_in_division (current active season ranking)
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

        DB::transaction(function () use ($game) {
            $winAmount = (int) config('ludo.league_points_win', 25);
            $lossAmount = (int) config('ludo.league_points_loss', 10);

            $activeSeason = LeagueSeason::getActiveSeason();

            // 1. Process Winner
            $winner = User::lockForUpdate()->find($game->winner_id);
            if ($winner) {
                $winner->increment('league_points', $winAmount);

                if ($activeSeason) {
                    $winnerMember = LeagueDivisionMember::whereHas('division', function ($q) use ($activeSeason) {
                        $q->where('league_season_id', $activeSeason->id);
                    })->where('user_id', $winner->id)
                    ->lockForUpdate()
                    ->first();

                    if ($winnerMember) {
                        $winnerMember->increment('points_in_division', $winAmount);
                    } else {
                        Log::warning("⚠️ [LEAGUE] Winner user_id {$winner->id} has no active division membership in season #{$activeSeason->season_number}");
                    }
                }
            }

            // 2. Process Losers
            $roomPlayers = RoomPlayer::where('room_id', $game->room_id)
                ->where('user_id', '!=', $game->winner_id)
                ->get();

            foreach ($roomPlayers as $rp) {
                $loser = User::lockForUpdate()->find($rp->user_id);
                if ($loser) {
                    $currentPoints = (int) ($loser->league_points ?? 0);
                    $newPoints = max(0, $currentPoints - $lossAmount);
                    $loser->update(['league_points' => $newPoints]);

                    if ($activeSeason) {
                        $loserMember = LeagueDivisionMember::whereHas('division', function ($q) use ($activeSeason) {
                            $q->where('league_season_id', $activeSeason->id);
                        })->where('user_id', $loser->id)
                        ->lockForUpdate()
                        ->first();

                        if ($loserMember) {
                            $currentDivPts = (int) ($loserMember->points_in_division ?? 0);
                            $newDivPts = max(0, $currentDivPts - $lossAmount);
                            $loserMember->update(['points_in_division' => $newDivPts]);
                        } else {
                            Log::warning("⚠️ [LEAGUE] Loser user_id {$loser->id} has no active division membership in season #{$activeSeason->season_number}");
                        }
                    }
                }
            }
        });
    }
}
