<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\TournamentLevelReached;
use App\Events\TournamentMatchFound;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\Tournament;
use App\Models\TournamentLevel;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GameEngine\RedisGameStateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TournamentService
{
    private const COLOR_MAP = [
        1 => 'red',
        2 => 'green',
        3 => 'yellow',
        4 => 'blue',
    ];

    /**
     * Join a tournament, deduct entry fee, and queue for level 1 match.
     */
    public function join(User $user, int $tournamentId): array
    {
        $tournament = Tournament::where('id', $tournamentId)
            ->where('status', 'active')
            ->first();

        if (!$tournament) {
            return [
                'status' => 'error',
                'code' => 404,
                'message' => 'Tournament not found or inactive',
            ];
        }

        $existingParticipant = TournamentParticipant::where('tournament_id', $tournamentId)
            ->where('user_id', $user->id)
            ->first();

        if ($existingParticipant && $existingParticipant->status === 'active') {
            return [
                'status' => 'error',
                'code' => 422,
                'message' => 'User already has an active participation in this tournament',
            ];
        }

        // DB Transaction for fee deduction & participant creation/reset
        try {
            DB::transaction(function () use ($user, $tournament, $existingParticipant) {
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
                $field = $tournament->currency_type === 'diamonds' ? 'diamonds_balance' : 'coins_balance';

                if (!$wallet || $wallet->{$field} < $tournament->entry_fee) {
                    throw new \Exception('Insufficient balance', 400);
                }

                // Deduct entry fee
                $wallet->decrement($field, $tournament->entry_fee);

                // Create transaction
                Transaction::create([
                    'user_id' => $user->id,
                    'type' => TransactionType::ENTRY_FEE,
                    'currency_type' => $tournament->currency_type,
                    'amount' => -$tournament->entry_fee,
                    'reference_id' => (string) $tournament->id,
                    'created_at' => now(),
                ]);

                // Increment tournament prize pool
                $tournament->increment('prize_pool', $tournament->entry_fee);

                // Create or reset participant
                if ($existingParticipant) {
                    // Re-join reset logic: reset current_level back to 1 and status to active,
                    // but DO NOT reset highest_level_reached (lifetime record).
                    $existingParticipant->update([
                        'current_level' => 1,
                        'status' => 'active',
                        'joined_at' => now(),
                    ]);
                } else {
                    TournamentParticipant::create([
                        'tournament_id' => $tournament->id,
                        'user_id' => $user->id,
                        'current_level' => 1,
                        'highest_level_reached' => 1,
                        'status' => 'active',
                        'joined_at' => now(),
                    ]);
                }
            });
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'code' => $e->getCode() === 400 ? 400 : 500,
                'message' => $e->getMessage(),
            ];
        }

        return $this->queueAndMatch($user, $tournament, 1);
    }

    /**
     * Continue tournament for an active participant at their current level.
     */
    public function continueMatch(User $user, int $tournamentId): array
    {
        $tournament = Tournament::where('id', $tournamentId)
            ->where('status', 'active')
            ->first();

        if (!$tournament) {
            return [
                'status' => 'error',
                'code' => 404,
                'message' => 'Tournament not found or inactive',
            ];
        }

        $participant = TournamentParticipant::where('tournament_id', $tournamentId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (!$participant) {
            return [
                'status' => 'error',
                'code' => 404,
                'message' => 'No active participation found for this user in this tournament',
            ];
        }

        return $this->queueAndMatch($user, $tournament, $participant->current_level);
    }

    /**
     * Leave the tournament matchmaking queue.
     */
    public function leaveQueue(User $user, int $tournamentId): array
    {
        $userQueueKey = $this->getUserQueueKey($user->id);
        $queueKey = Cache::get($userQueueKey);

        if ($queueKey) {
            $lock = Cache::lock("tournament:lock:{$queueKey}", 10);
            try {
                $lock->block(5);
                $queue = Cache::get($queueKey, []);
                $queue = array_values(array_filter($queue, fn($entry) => $entry['user_id'] !== $user->id));
                Cache::put($queueKey, $queue, 86400);
                Cache::forget($userQueueKey);
            } finally {
                optional($lock)->release();
            }
        }

        return [
            'status' => 'success',
            'message' => 'Left tournament matchmaking queue',
        ];
    }

    /**
     * Process game completion and advance tournament progression if this room was a tournament match.
     */
    public function processMatchResult(int $roomId, int $winnerId): void
    {
        // 1. Confirm room_id is linked to an in_progress tournament_matches record BEFORE applying any progression logic
        $tMatch = TournamentMatch::where('room_id', $roomId)
            ->where('status', 'in_progress')
            ->first();

        if (!$tMatch) {
            // Normal (non-tournament) game win — do nothing
            return;
        }

        DB::transaction(function () use ($tMatch, $winnerId) {
            // Mark match completed
            $tMatch->update([
                'status' => 'completed',
                'winner_id' => $winnerId,
            ]);

            $tournament = Tournament::find($tMatch->tournament_id);
            if (!$tournament) {
                return;
            }

            $loserId = ($tMatch->player1_id === $winnerId) ? $tMatch->player2_id : $tMatch->player1_id;

            // Winner Progression
            $winnerP = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('user_id', $winnerId)
                ->first();

            if ($winnerP && $winnerP->status === 'active') {
                $nextLevel = min($winnerP->current_level + 1, $tournament->max_level);
                $isFinalLevel = ($nextLevel >= $tournament->max_level);
                $newHighest = max($winnerP->highest_level_reached, $nextLevel);

                $winnerP->update([
                    'current_level' => $nextLevel,
                    'highest_level_reached' => $newHighest,
                    'status' => $isFinalLevel ? 'completed' : 'active',
                ]);

                // Check reward for reaching $nextLevel
                $levelReward = TournamentLevel::where('tournament_id', $tournament->id)
                    ->where('level', $nextLevel)
                    ->first();

                $rewardCoins = $levelReward->reward_coins ?? 0;
                $rewardDiamonds = $levelReward->reward_diamonds ?? 0;

                if ($rewardCoins > 0 || $rewardDiamonds > 0) {
                    $wallet = Wallet::where('user_id', $winnerId)->lockForUpdate()->first();
                    if ($wallet) {
                        if ($rewardCoins > 0) {
                            $wallet->increment('coins_balance', $rewardCoins);
                            Transaction::create([
                                'user_id' => $winnerId,
                                'type' => TransactionType::WIN,
                                'currency_type' => 'coins',
                                'amount' => $rewardCoins,
                                'reference_id' => (string) $tMatch->id,
                                'created_at' => now(),
                            ]);
                        }
                        if ($rewardDiamonds > 0) {
                            $wallet->increment('diamonds_balance', $rewardDiamonds);
                            Transaction::create([
                                'user_id' => $winnerId,
                                'type' => TransactionType::WIN,
                                'currency_type' => 'diamonds',
                                'amount' => $rewardDiamonds,
                                'reference_id' => (string) $tMatch->id,
                                'created_at' => now(),
                            ]);
                        }
                    }
                }

                broadcast(new TournamentLevelReached(
                    $winnerId,
                    $tournament->id,
                    $nextLevel,
                    [
                        'reward_coins' => $rewardCoins,
                        'reward_diamonds' => $rewardDiamonds,
                    ],
                    $isFinalLevel
                ));
            }

            // Loser Progression
            $loserP = TournamentParticipant::where('tournament_id', $tournament->id)
                ->where('user_id', $loserId)
                ->first();

            if ($loserP && $loserP->status === 'active') {
                $lossAction = config('tournament.loss_action', 'stay');
                if ($lossAction === 'drop') {
                    $newLevel = max(1, $loserP->current_level - 1);
                    $loserP->update(['current_level' => $newLevel]);
                }
                // If lossAction === 'stay', current_level remains unchanged.
            }
        });
    }

    /**
     * Queue user for a tournament level and match if 2 players are queued.
     */
    private function queueAndMatch(User $user, Tournament $tournament, int $level): array
    {
        $queueKey = $this->getQueueKey($tournament->id, $level);
        $userQueueKey = $this->getUserQueueKey($user->id);

        $existingQueue = Cache::get($userQueueKey);
        if ($existingQueue === $queueKey) {
            $queue = Cache::get($queueKey, []);
            $pos = $this->findUserPosition($queue, $user->id);
            return [
                'status' => 'waiting',
                'queue_position' => $pos !== false ? $pos + 1 : 1,
            ];
        }

        $lock = Cache::lock("tournament:lock:{$queueKey}", 10);

        try {
            $lock->block(5);

            $queue = Cache::get($queueKey, []);

            if ($this->findUserPosition($queue, $user->id) !== false) {
                $pos = $this->findUserPosition($queue, $user->id);
                return [
                    'status' => 'waiting',
                    'queue_position' => $pos + 1,
                ];
            }

            $queue[] = [
                'user_id' => $user->id,
                'joined_at' => now()->toIso8601String(),
            ];

            // 2-player match for tournament level
            if (count($queue) >= 2) {
                $matchedUsers = array_splice($queue, 0, 2);

                Cache::put($queueKey, $queue, 86400);

                foreach ($matchedUsers as $mu) {
                    Cache::forget($this->getUserQueueKey($mu['user_id']));
                }

                $createResult = DB::transaction(function () use ($matchedUsers, $tournament, $level) {
                    $creatorId = $matchedUsers[0]['user_id'];

                    $room = Room::create([
                        'room_code' => strtoupper(Str::random(6)),
                        'type' => RoomType::PUBLIC->value,
                        'max_players' => 2,
                        'entry_fee' => 0, // Fee was paid when joining tournament
                        'status' => RoomStatus::PLAYING->value,
                        'created_by' => $creatorId,
                        'created_at' => now(),
                    ]);

                    $players = [];
                    $playerData = [];
                    $seatPosition = 1;

                    foreach ($matchedUsers as $mu) {
                        $pUser = User::find($mu['user_id']);
                        $color = self::COLOR_MAP[$seatPosition] ?? PlayerColor::RED->value;

                        RoomPlayer::create([
                            'room_id' => $room->id,
                            'user_id' => $pUser->id,
                            'seat_position' => $seatPosition,
                            'color' => $color,
                            'is_ready' => true,
                            'joined_at' => now(),
                        ]);

                        $players[] = [
                            'user_id' => $pUser->id,
                            'username' => $pUser->username,
                            'avatar_url' => $pUser->avatar_url,
                            'seat_position' => $seatPosition,
                            'color' => $color,
                        ];

                        $playerData[] = [
                            'seat_position' => $seatPosition - 1,
                            'user_id' => $pUser->id,
                            'username' => $pUser->username ?? 'Player',
                            'color' => $color,
                        ];

                        $seatPosition++;
                    }

                    $game = Game::create([
                        'room_id' => $room->id,
                        'status' => GameStatus::IN_PROGRESS->value,
                        'started_at' => now(),
                        'created_at' => now(),
                    ]);

                    $stateStore = app(RedisGameStateStore::class);
                    $stateStore->initializeState($room->id, $game->id, $playerData);

                    $tMatch = TournamentMatch::create([
                        'tournament_id' => $tournament->id,
                        'level' => $level,
                        'room_id' => $room->id,
                        'player1_id' => $matchedUsers[0]['user_id'],
                        'player2_id' => $matchedUsers[1]['user_id'],
                        'status' => 'in_progress',
                    ]);

                    foreach ($matchedUsers as $mu) {
                        broadcast(new TournamentMatchFound(
                            $mu['user_id'],
                            $tournament->id,
                            $level,
                            $room->id,
                            $game->id,
                            $players
                        ));
                    }

                    return [
                        'status' => 'matched',
                        'tournament_id' => $tournament->id,
                        'level' => $level,
                        'room_id' => $room->id,
                        'game_id' => $game->id,
                        'players' => $players,
                    ];
                });

                return $createResult;
            }

            Cache::put($queueKey, $queue, 86400);
            Cache::put($userQueueKey, $queueKey, 86400);

            $pos = $this->findUserPosition($queue, $user->id);

            return [
                'status' => 'waiting',
                'queue_position' => $pos !== false ? $pos + 1 : count($queue),
            ];
        } finally {
            optional($lock)->release();
        }
    }

    private function getQueueKey(int $tournamentId, int $level): string
    {
        return "tournament:queue:{$tournamentId}:{$level}";
    }

    private function getUserQueueKey(int $userId): string
    {
        return "tournament:user:{$userId}";
    }

    private function findUserPosition(array $queue, int $userId): int|false
    {
        foreach ($queue as $index => $entry) {
            if ($entry['user_id'] === $userId) {
                return $index;
            }
        }
        return false;
    }
}
