<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\MatchFound;
use App\Models\Game;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\GameEngine\RedisGameStateStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MatchmakingService
{
    /**
     * Color assignment map (seat position => color).
     */
    private const COLOR_MAP = [
        1 => 'red',
        2 => 'green',
        3 => 'yellow',
        4 => 'blue',
    ];

    /**
     * Join the matchmaking queue for a given max_players and entry_fee.
     *
     * Uses Cache::lock() for atomic pop operations to prevent race conditions.
     *
     * @param User $user
     * @param int  $maxPlayers
     * @param int  $entryFee
     * @return array{status: string, ...}
     */
    public function join(User $user, int $maxPlayers, int $entryFee): array
    {
        $queueKey = $this->getQueueKey($maxPlayers, $entryFee);
        $userQueueKey = $this->getUserQueueKey($user->id);

        // Check if user is already in a queue
        $existingQueue = Cache::get($userQueueKey);
        if ($existingQueue) {
            // Already queued - return current position
            $queue = Cache::get($queueKey, []);
            $position = $this->findUserPosition($queue, $user->id);
            return [
                'status' => 'waiting',
                'queue_position' => $position !== false ? $position + 1 : 1,
                'message' => 'Already in matchmaking queue',
            ];
        }

        // Use a lock to ensure atomic queue operations
        $lock = Cache::lock("matchmaking:lock:{$queueKey}", 10);

        try {
            $lock->block(5); // Wait up to 5 seconds to acquire lock

            // Get current queue
            $queue = Cache::get($queueKey, []);

            // Verify user not already in queue (double-check inside lock)
            if ($this->findUserPosition($queue, $user->id) !== false) {
                $position = $this->findUserPosition($queue, $user->id);
                return [
                    'status' => 'waiting',
                    'queue_position' => $position + 1,
                    'message' => 'Already in matchmaking queue',
                ];
            }

            // Add user to queue
            $queue[] = [
                'user_id' => $user->id,
                'joined_at' => now()->toIso8601String(),
            ];

            // Check if we have enough players for a match
            if (count($queue) >= $maxPlayers) {
                // Pop exactly max_players users from the front
                $matchedUsers = array_splice($queue, 0, $maxPlayers);

                // Save remaining queue
                Cache::put($queueKey, $queue, 86400);

                // Clear user queue markers for all matched users
                foreach ($matchedUsers as $mu) {
                    Cache::forget($this->getUserQueueKey($mu['user_id']));
                }

                // Create the match (with wallet deduction & DB transaction)
                $result = $this->createMatch($matchedUsers, $maxPlayers, $entryFee, $queueKey);

                if ($result['status'] === 'matched') {
                    return [
                        'status' => 'matched',
                        'room_id' => $result['room_id'],
                        'game_id' => $result['game_id'],
                        'players' => $result['players'],
                    ];
                } else {
                    // Match could not be created due to insufficient balance of a user
                    $position = $this->findUserPosition(Cache::get($queueKey, []), $user->id);
                    return [
                        'status' => 'waiting',
                        'queue_position' => $position !== false ? $position + 1 : 1,
                        'message' => 'Match cancelled due to player balance change. Re-queued.',
                    ];
                }
            }

            // Not enough players yet - save queue and mark user
            Cache::put($queueKey, $queue, 86400);
            Cache::put($userQueueKey, $queueKey, 86400);

            $position = $this->findUserPosition($queue, $user->id);

            return [
                'status' => 'waiting',
                'queue_position' => $position !== false ? $position + 1 : count($queue),
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Remove a user from whatever matchmaking queue they're in.
     *
     * @param User $user
     * @return array
     */
    public function leave(User $user): array
    {
        $userQueueKey = $this->getUserQueueKey($user->id);
        $queueKey = Cache::get($userQueueKey);

        if (!$queueKey) {
            return [
                'status' => 'success',
                'message' => 'Not currently in any matchmaking queue',
            ];
        }

        $lock = Cache::lock("matchmaking:lock:{$queueKey}", 10);

        try {
            $lock->block(5);

            $queue = Cache::get($queueKey, []);
            $queue = array_values(array_filter($queue, fn($entry) => $entry['user_id'] !== $user->id));
            Cache::put($queueKey, $queue, 86400);
            Cache::forget($userQueueKey);

            return [
                'status' => 'success',
                'message' => 'Left matchmaking queue',
            ];
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Get the matchmaking status for a user.
     *
     * @param User $user
     * @return array
     */
    public function status(User $user): array
    {
        $userQueueKey = $this->getUserQueueKey($user->id);
        $queueKey = Cache::get($userQueueKey);

        if (!$queueKey) {
            return [
                'status' => 'idle',
                'message' => 'Not in any matchmaking queue',
            ];
        }

        $queue = Cache::get($queueKey, []);
        $position = $this->findUserPosition($queue, $user->id);

        if ($position === false) {
            // User was matched and removed from queue but marker wasn't cleaned up
            Cache::forget($userQueueKey);
            return [
                'status' => 'idle',
                'message' => 'Not in any matchmaking queue',
            ];
        }

        // Parse the queue key to extract info
        $parts = explode(':', $queueKey);
        $maxPlayers = $parts[2] ?? '?';
        $entryFee = $parts[3] ?? '?';

        return [
            'status' => 'queued',
            'queue_position' => $position + 1,
            'queue_size' => count($queue),
            'max_players' => (int) $maxPlayers,
            'entry_fee' => (int) $entryFee,
        ];
    }

    /**
     * Create a Room + Game for the matched users, deduct entry fees, and broadcast MatchFound.
     */
    private function createMatch(array $matchedUsers, int $maxPlayers, int $entryFee, string $queueKey = ''): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($matchedUsers, $maxPlayers, $entryFee, $queueKey) {
            // Verify wallet balances for all matched users if entry_fee > 0
            if ($entryFee > 0) {
                $validUsers = [];
                $invalidUsers = [];

                foreach ($matchedUsers as $mu) {
                    $wallet = \App\Models\Wallet::where('user_id', $mu['user_id'])->lockForUpdate()->first();
                    if ($wallet && $wallet->coins_balance >= $entryFee) {
                        $validUsers[] = $mu;
                    } else {
                        $invalidUsers[] = $mu;
                    }
                }

                // If any matched user has insufficient balance, cancel this match
                if (count($invalidUsers) > 0) {
                    // Invalid users are removed (queue marker deleted)
                    foreach ($invalidUsers as $iu) {
                        \Illuminate\Support\Facades\Cache::forget($this->getUserQueueKey($iu['user_id']));
                    }

                    // Re-queue valid users at the front of the queue
                    if ($queueKey && count($validUsers) > 0) {
                        $currentQueue = \Illuminate\Support\Facades\Cache::get($queueKey, []);
                        $reQueued = array_merge($validUsers, $currentQueue);
                        \Illuminate\Support\Facades\Cache::put($queueKey, $reQueued, 86400);

                        foreach ($validUsers as $vu) {
                            \Illuminate\Support\Facades\Cache::put($this->getUserQueueKey($vu['user_id']), $queueKey, 86400);
                        }
                    }

                    return ['status' => 'cancelled', 'message' => 'Insufficient balance for a matched user'];
                }
            }

            // Create room
            $creatorId = $matchedUsers[0]['user_id'];
            $room = Room::create([
                'room_code' => strtoupper(Str::random(6)),
                'type' => RoomType::PUBLIC->value,
                'max_players' => $maxPlayers,
                'entry_fee' => $entryFee,
                'status' => RoomStatus::PLAYING->value,
                'created_by' => $creatorId,
                'created_at' => now(),
            ]);

            // Deduct entry fee and record transaction for each matched user
            if ($entryFee > 0) {
                foreach ($matchedUsers as $mu) {
                    \App\Models\Wallet::where('user_id', $mu['user_id'])
                        ->decrement('coins_balance', $entryFee);

                    \App\Models\Transaction::create([
                        'user_id' => $mu['user_id'],
                        'type' => \App\Enums\TransactionType::ENTRY_FEE,
                        'currency_type' => 'coins',
                        'amount' => -$entryFee,
                        'reference_id' => (string) $room->id,
                        'created_at' => now(),
                    ]);
                }
            }

            // Assign seats and colors
            $players = [];
            $playerData = [];
            $seatPosition = 1;
            foreach ($matchedUsers as $mu) {
                $user = User::find($mu['user_id']);
                $color = self::COLOR_MAP[$seatPosition] ?? PlayerColor::RED->value;

                RoomPlayer::create([
                    'room_id' => $room->id,
                    'user_id' => $user->id,
                    'seat_position' => $seatPosition,
                    'color' => $color,
                    'is_ready' => true,
                    'joined_at' => now(),
                ]);

                $players[] = [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'avatar_url' => $user->avatar_url,
                    'seat_position' => $seatPosition,
                    'color' => $color,
                ];

                $playerData[] = [
                    'seat_position' => $seatPosition - 1, // 0-indexed for game engine
                    'user_id' => $user->id,
                    'username' => $user->username ?? 'Player',
                    'color' => $color,
                ];

                $seatPosition++;
            }

            // Create game
            $game = Game::create([
                'room_id' => $room->id,
                'status' => GameStatus::IN_PROGRESS->value,
                'started_at' => now(),
                'created_at' => now(),
            ]);

            // Initialize Redis game state
            $stateStore = app(RedisGameStateStore::class);
            $stateStore->initializeState($room->id, $game->id, $playerData);

            // Broadcast MatchFound to each matched player's private channel
            foreach ($matchedUsers as $mu) {
                broadcast(new MatchFound(
                    $mu['user_id'],
                    $room->id,
                    $game->id,
                    $players
                ));
            }

            return [
                'status' => 'matched',
                'room_id' => $room->id,
                'game_id' => $game->id,
                'players' => $players,
            ];
        });
    }

    /**
     * Get the Redis queue key for a given max_players/entry_fee combination.
     */
    private function getQueueKey(int $maxPlayers, int $entryFee): string
    {
        return "matchmaking:queue:{$maxPlayers}:{$entryFee}";
    }

    /**
     * Get the per-user Redis key tracking which queue they're in.
     */
    private function getUserQueueKey(int $userId): string
    {
        return "matchmaking:user:{$userId}";
    }

    /**
     * Find a user's position in the queue. Returns false if not found.
     */
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
