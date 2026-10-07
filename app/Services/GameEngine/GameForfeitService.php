<?php

namespace App\Services\GameEngine;

use App\Enums\GameStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\GameEnded;
use App\Events\PlayerForfeited;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Game;
use App\Models\Room;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LeagueService;
use App\Services\TournamentService;
use App\Support\TeamAssignment;
use Illuminate\Support\Facades\Log;

class GameForfeitService
{
    public function __construct(
        protected RedisGameStateStore $stateStore,
        protected TurnManager $turnManager,
        protected LeagueService $leagueService,
        protected TournamentService $tournamentService
    ) {}

    /**
     * Forfeit a player from an active game by their seat position.
     *
     * @param int $roomId
     * @param int $leaverSeat
     * @param string $reason 'manual' or 'timeout'
     * @return array
     */
    public function forfeitSeat(int $roomId, int $leaverSeat, string $reason = 'manual'): array
    {
        $state = $this->stateStore->getState($roomId);
        if (!$state || $state['status'] !== 'in_progress') {
            return [
                'success' => false,
                'is_game_over' => false,
                'message' => 'No active in-progress match found',
            ];
        }

        if (!isset($state['players'][$leaverSeat])) {
            return [
                'success' => false,
                'is_game_over' => false,
                'message' => 'Player not found at seat ' . $leaverSeat,
            ];
        }

        $leaverPlayer = $state['players'][$leaverSeat];
        $leaverUserId = (int) $leaverPlayer['user_id'];
        $leaverUsername = $leaverPlayer['username'] ?? "Player {$leaverSeat}";

        $room = Room::find($roomId);
        $isPrivateRoom = $room ? $room->isPrivateOrVip() : false;

        $entryFee = (int) ($room?->entry_fee ?? 200);
        $maxPlayers = (int) ($room?->max_players ?? 2);
        if ($isPrivateRoom) {
            $rawPot = $entryFee > 0 ? ($entryFee * $maxPlayers) : 0;
            $cutPct = (float) config('private_room.platform_cut_percentage', 0);
            $platformCut = (int) floor($rawPot * ($cutPct / 100.0));
            $totalPrize = max(0, $rawPot - $platformCut);
        } else {
            $totalPrize = max(400, $entryFee * $maxPlayers);
        }

        $roomTypeVal = $room ? ($room->type instanceof RoomType ? $room->type->value : (string) $room->type) : 'public';
        $isTeamRoom = ($roomTypeVal === RoomType::TEAM->value || $roomTypeVal === 'team');

        if ($isTeamRoom) {
            // Team Mode Forfeit: Entire leaver team forfeits, opposing team wins and splits pot 50/50
            $leaverColor = strtolower($leaverPlayer['color']);
            $leaverTeam = TeamAssignment::teamForColor($leaverColor);
            $opposingTeam = ($leaverTeam === 1) ? 2 : 1;

            $winningUserIds = [];
            $winnerUsername = 'Opposing Team';
            foreach ($state['players'] as $p) {
                $pColor = strtolower($p['color']);
                if (TeamAssignment::teamForColor($pColor) === $opposingTeam) {
                    $winningUserIds[] = (int) $p['user_id'];
                    $winnerUsername = $p['username'];
                }
            }
            $winningUserIds = array_unique($winningUserIds);
            $primaryWinnerId = reset($winningUserIds) ?: null;

            $state['status'] = 'completed';
            $state['winner_id'] = $primaryWinnerId;
            $this->stateStore->saveState($roomId, $state);

            $game = Game::find($state['game_id']);
            if ($game) {
                $game->update([
                    'winner_id' => $primaryWinnerId,
                    'status' => GameStatus::COMPLETED->value,
                    'ended_at' => now(),
                ]);
            }

            if ($room) {
                $room->update(['status' => RoomStatus::FINISHED->value]);
                $room->increment('state_version');
            }

            $rawPot = $entryFee * 4;
            $cutPct = (float) config('private_room.platform_cut_percentage', 0);
            $platformCut = (int) floor($rawPot * ($cutPct / 100.0));
            $totalPrize = max(0, $rawPot - $platformCut);
            $sharePerTeammate = (int) floor($totalPrize / 2);

            if ($sharePerTeammate > 0) {
                foreach ($winningUserIds as $winnerUserId) {
                    Wallet::where('user_id', $winnerUserId)->increment('coins_balance', $sharePerTeammate);
                    Transaction::create([
                        'user_id' => $winnerUserId,
                        'type' => TransactionType::WIN,
                        'currency_type' => 'coins',
                        'amount' => $sharePerTeammate,
                        'reference_id' => (string) $roomId,
                        'created_at' => now(),
                    ]);
                }
            }

            broadcast(new GameEnded($roomId, $state['game_id'], $primaryWinnerId ?? 0, $winnerUsername, $totalPrize, true));
            broadcast(new PlayerForfeited($roomId, $leaverUserId, $leaverUsername, true, $primaryWinnerId, $winnerUsername, $totalPrize, true, $reason));

            return [
                'success' => true,
                'is_game_over' => true,
                'winner_id' => $primaryWinnerId,
                'winner_username' => $winnerUsername,
                'prize_coins' => $totalPrize,
                'game_state' => $state,
                'reason' => $reason,
            ];
        }

        // Standard 1v1 or 4-player FFA: Remove leaver from active seats
        $activeSeats = array_values(array_diff($state['active_seats'], [$leaverSeat]));
        $state['active_seats'] = $activeSeats;
        if (isset($state['players'][$leaverSeat])) {
            $state['players'][$leaverSeat]['is_connected'] = false;
        }

        // Check if only 1 (or 0) active player remains -> GAME OVER, Remaining Player WINS Full Pot!
        if (count($activeSeats) <= 1) {
            $winnerSeat = !empty($activeSeats) ? $activeSeats[0] : null;
            $winnerPlayer = $winnerSeat !== null ? ($state['players'][$winnerSeat] ?? null) : null;
            $winnerId = $winnerPlayer['user_id'] ?? null;
            $winnerUsername = $winnerPlayer['username'] ?? 'Winner';

            $state['status'] = 'completed';
            $state['winner_id'] = $winnerId;
            $this->stateStore->saveState($roomId, $state);

            $game = Game::find($state['game_id']);
            if ($game) {
                $game->update([
                    'winner_id' => $winnerId,
                    'status' => GameStatus::COMPLETED->value,
                    'ended_at' => now(),
                ]);

                if ($winnerId) {
                    $this->leagueService->awardLeaguePoints($game);
                    $this->tournamentService->processMatchResult($roomId, $winnerId);
                }
            }

            if ($winnerId) {
                if ($isPrivateRoom) {
                    if ($totalPrize > 0) {
                        Wallet::where('user_id', $winnerId)->increment('coins_balance', $totalPrize);
                        Transaction::create([
                            'user_id' => $winnerId,
                            'type' => TransactionType::WIN,
                            'currency_type' => 'coins',
                            'amount' => $totalPrize,
                            'reference_id' => (string) $roomId,
                            'created_at' => now(),
                        ]);
                    }
                    if ($room) {
                        $room->update(['status' => RoomStatus::FINISHED->value]);
                        $room->increment('state_version');
                    }
                } else {
                    Wallet::where('user_id', $winnerId)->increment('coins_balance', $totalPrize);
                    Transaction::create([
                        'user_id' => $winnerId,
                        'type' => TransactionType::REWARD,
                        'currency_type' => 'coins',
                        'amount' => $totalPrize,
                        'reference_id' => (string) $roomId,
                        'created_at' => now(),
                    ]);
                    if ($room) {
                        $room->update(['status' => RoomStatus::FINISHED->value]);
                    }
                }
            }

            broadcast(new GameEnded($roomId, $state['game_id'], $winnerId ?? 0, $winnerUsername, $totalPrize, $isPrivateRoom));
            broadcast(new PlayerForfeited($roomId, $leaverUserId, $leaverUsername, true, $winnerId, $winnerUsername, $totalPrize, $isPrivateRoom, $reason));

            return [
                'success' => true,
                'is_game_over' => true,
                'winner_id' => $winnerId,
                'winner_username' => $winnerUsername,
                'prize_coins' => $totalPrize,
                'game_state' => $state,
                'reason' => $reason,
            ];
        }

        // More than 1 active player remains (4-player match continues with remaining players)
        // If it was the leaver's turn, advance turn to the next active player
        if ($state['current_turn_seat'] === $leaverSeat) {
            $nextSeat = $this->turnManager->getNextTurn($leaverSeat, $state['active_seats'], false);
            $state['current_turn_seat'] = $nextSeat;
            $state['current_turn_user_id'] = $state['players'][$nextSeat]['user_id'];
            $state['can_roll'] = true;
            $state['must_move'] = false;
            $state['dice_value'] = null;
            $state['last_action_at'] = now()->toIso8601String();

            broadcast(new TurnChanged($roomId, $nextSeat, $state['current_turn_user_id'], false, $isPrivateRoom));

            $delay = isset($state['turn_seconds']) && $state['turn_seconds'] !== null
                ? ((int) $state['turn_seconds'] + 2)
                : 17;
            ProcessTurnTimeout::dispatch($roomId, $nextSeat, $state['last_action_at'])->delay(now()->addSeconds($delay));
        }

        $this->stateStore->saveState($roomId, $state);

        broadcast(new PlayerForfeited($roomId, $leaverUserId, $leaverUsername, false, null, null, $totalPrize, $isPrivateRoom, $reason));

        return [
            'success' => true,
            'is_game_over' => false,
            'winner_id' => null,
            'winner_username' => null,
            'prize_coins' => $totalPrize,
            'game_state' => $state,
            'reason' => $reason,
        ];
    }
}
