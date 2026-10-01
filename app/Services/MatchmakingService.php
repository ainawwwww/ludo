<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Events\MatchFound;
use App\Events\TurnChanged;
use App\Jobs\ProcessTurnTimeout;
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

    private function getColorForMatchSeat(int $seatPosition, int $maxPlayers): string
    {
        if ($maxPlayers === 2) {
            return $seatPosition === 1 ? 'red' : 'yellow';
        }
        return self::COLOR_MAP[$seatPosition] ?? 'red';
    }

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
                        'quick_match_id' => $result['room_id'],
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
     * Check if user has an active in-progress match/room.
     *
     * @param User $user
     * @return array|null
     */
    public function activeMatch(User $user): ?array
    {
        $roomPlayer = RoomPlayer::where('user_id', $user->id)
            ->whereHas('room', function ($query) {
                $query->whereIn('status', [RoomStatus::WAITING->value, RoomStatus::PLAYING->value]);
            })
            ->latest('id')
            ->first();

        if (!$roomPlayer) {
            return null;
        }

        $room = $roomPlayer->room;
        if (!$room) {
            return null;
        }

        $game = Game::where('room_id', $room->id)
            ->where('status', GameStatus::IN_PROGRESS->value)
            ->latest('id')
            ->first();

        if (!$game && $room->status !== RoomStatus::WAITING->value) {
            return null;
        }

        // Get all players in this room
        $players = [];
        $roomPlayers = RoomPlayer::with('user')->where('room_id', $room->id)->orderBy('seat_position')->get();
        foreach ($roomPlayers as $rp) {
            $u = $rp->user;
            $players[] = [
                'user_id' => $rp->user_id,
                'username' => $u ? $u->username : 'Player',
                'avatar_url' => $u ? $u->avatar_url : null,
                'seat_position' => $rp->seat_position,
                'color' => $rp->color instanceof PlayerColor ? $rp->color->value : (string) $rp->color,
            ];
        }

        return [
            'status' => 'matched',
            'quick_match_id' => $room->id,
            'room_id' => $room->id,
            'game_id' => $game ? $game->id : null,
            'players' => $players,
        ];
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
            // Check if user is already in an active room/game
            $activeMatch = $this->activeMatch($user);
            if ($activeMatch) {
                return [
                    'status' => 'already_matched',
                    'message' => 'User is already matched into an active game',
                    'data' => $activeMatch,
                ];
            }

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
                $color = $this->getColorForMatchSeat($seatPosition, $maxPlayers);

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

            // Create game and initialize Redis state via shared GameInitializerService
            $gameInitializer = app(\App\Services\GameInitializerService::class);
            [$game, $gameState] = $gameInitializer->initializeGame($room, $playerData);

            // Broadcast MatchFound to each matched player's private channel
            foreach ($matchedUsers as $mu) {
                broadcast(new MatchFound(
                    $mu['user_id'],
                    $room->id,
                    $game->id,
                    $players
                ));
            }

            // Broadcast initial TurnChanged to room channel
            $initialTurnSeat = $gameState['current_turn_seat'] ?? 0;
            $initialUserId = $gameState['current_turn_user_id'] ?? $matchedUsers[0]['user_id'];
            broadcast(new TurnChanged(
                $room->id,
                $initialTurnSeat,
                $initialUserId,
                false
            ));

            // Dispatch turn timeout job: 15s default for quick match, or turn_seconds + 2 if explicitly set
            $delay = isset($gameState['turn_seconds']) && $gameState['turn_seconds'] !== null
                ? ((int) $gameState['turn_seconds'] + 2)
                : 15;
            ProcessTurnTimeout::dispatch($room->id, $initialTurnSeat, $gameState['last_action_at'])
                ->delay(now()->addSeconds($delay));

            return [
                'status' => 'matched',
                'quick_match_id' => $room->id,
                'room_id' => $room->id,
                'game_id' => $game->id,
                'players' => $players,
            ];
        });
    }

    /**
     * Join 2v2 Team Matchmaking as a Solo player (SINGLE path).
     */
    public function joinTeamSolo(User $user, int $entryFee): array
    {
        $queueKey = "matchmaking:team_queue:{$entryFee}";
        $userQueueKey = $this->getUserQueueKey($user->id);

        $existingQueue = Cache::get($userQueueKey);
        if ($existingQueue) {
            $queue = Cache::get($queueKey, []);
            $position = $this->findTeamEntryPosition($queue, $user->id);
            return [
                'status' => 'waiting',
                'queue_position' => $position !== false ? $position + 1 : 1,
                'message' => 'Already in matchmaking queue',
            ];
        }

        $lock = Cache::lock("matchmaking:lock:{$queueKey}", 10);

        try {
            $lock->block(5);
            $queue = Cache::get($queueKey, []);

            if ($this->findTeamEntryPosition($queue, $user->id) !== false) {
                $position = $this->findTeamEntryPosition($queue, $user->id);
                return [
                    'status' => 'waiting',
                    'queue_position' => $position + 1,
                    'message' => 'Already in matchmaking queue',
                ];
            }

            $queue[] = [
                'type' => 'solo',
                'user_ids' => [$user->id],
                'joined_at' => now()->toIso8601String(),
            ];

            Cache::put($queueKey, $queue, 86400);
            Cache::put($userQueueKey, $queueKey, 86400);

            return $this->processTeamQueue($queueKey, $entryFee, $user->id);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Enqueue a ready 2-player team lobby into the unified 2v2 matchmaking queue (CREATE / JOIN path).
     */
    public function enqueueTeamPair(Room $teamRoom): array
    {
        $players = $teamRoom->players()->orderBy('seat_position')->get();
        if ($players->count() < 2) {
            return ['status' => 'error', 'message' => 'Not enough players in team room'];
        }

        $userIds = $players->pluck('user_id')->toArray();
        $entryFee = (int) $teamRoom->entry_fee;
        $queueKey = "matchmaking:team_queue:{$entryFee}";

        $lock = Cache::lock("matchmaking:lock:{$queueKey}", 10);

        try {
            $lock->block(5);
            $queue = Cache::get($queueKey, []);

            // Check if already in queue
            foreach ($queue as $entry) {
                if (isset($entry['team_room_id']) && (int)$entry['team_room_id'] === (int)$teamRoom->id) {
                    return ['status' => 'waiting', 'message' => 'Team room already queued'];
                }
            }

            $queue[] = [
                'type' => 'pair',
                'team_room_id' => $teamRoom->id,
                'user_ids' => $userIds,
                'joined_at' => now()->toIso8601String(),
            ];

            Cache::put($queueKey, $queue, 86400);
            foreach ($userIds as $uid) {
                Cache::put($this->getUserQueueKey($uid), $queueKey, 86400);
            }

            return $this->processTeamQueue($queueKey, $entryFee, $userIds[0]);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Process unified 2v2 queue in strict FIFO order.
     */
    private function processTeamQueue(string $queueKey, int $entryFee, int $requestingUserId): array
    {
        $queue = Cache::get($queueKey, []);

        $matchSelection = $this->selectTeamMatchFromQueue($queue);
        if (!$matchSelection) {
            $pos = $this->findTeamEntryPosition($queue, $requestingUserId);
            return [
                'status' => 'waiting',
                'queue_position' => $pos !== false ? $pos + 1 : 1,
            ];
        }

        // Remove selected entries from queue
        $selectedIndices = $matchSelection['indices'];
        $newQueue = [];
        foreach ($queue as $idx => $entry) {
            if (!in_array($idx, $selectedIndices, true)) {
                $newQueue[] = $entry;
            }
        }
        Cache::put($queueKey, $newQueue, 86400);

        // Clear queue markers for matched users
        $team1UserIds = $matchSelection['team1_users'];
        $team2UserIds = $matchSelection['team2_users'];
        $allMatchedUserIds = array_merge($team1UserIds, $team2UserIds);

        foreach ($allMatchedUserIds as $uid) {
            Cache::forget($this->getUserQueueKey($uid));
        }

        // Create 2v2 match
        $result = $this->createTeamMatch($team1UserIds, $team2UserIds, $entryFee, $queueKey);

        if ($result['status'] === 'matched') {
            return $result;
        }

        $pos = $this->findTeamEntryPosition(Cache::get($queueKey, []), $requestingUserId);
        return [
            'status' => 'waiting',
            'queue_position' => $pos !== false ? $pos + 1 : 1,
            'message' => 'Match cancelled due to player balance change.',
        ];
    }

    /**
     * FIFO Selection Algorithm for 2v2 Team Matchmaking.
     */
    private function selectTeamMatchFromQueue(array $queue): ?array
    {
        if (empty($queue)) {
            return null;
        }

        // Gather all solo entries and pair entries with their queue indices
        $soloIndices = [];
        $pairIndices = [];

        foreach ($queue as $idx => $entry) {
            if ($entry['type'] === 'solo') {
                $soloIndices[] = $idx;
            } elseif ($entry['type'] === 'pair') {
                $pairIndices[] = $idx;
            }
        }

        $firstEntry = $queue[0];

        // Case 1: First FIFO entry is a PAIR
        if ($firstEntry['type'] === 'pair') {
            $pair1Idx = 0;

            // Option A: Match with 2nd Pair
            if (count($pairIndices) >= 2) {
                $pair2Idx = $pairIndices[1];
                return [
                    'indices' => [$pair1Idx, $pair2Idx],
                    'team1_users' => $queue[$pair1Idx]['user_ids'],
                    'team2_users' => $queue[$pair2Idx]['user_ids'],
                ];
            }

            // Option B: Match with 2 Solo entries
            if (count($soloIndices) >= 2) {
                $s1 = $soloIndices[0];
                $s2 = $soloIndices[1];
                return [
                    'indices' => [$pair1Idx, $s1, $s2],
                    'team1_users' => $queue[$pair1Idx]['user_ids'],
                    'team2_users' => [$queue[$s1]['user_ids'][0], $queue[$s2]['user_ids'][0]],
                ];
            }

            return null;
        }

        // Case 2: First FIFO entry is a SOLO player
        if ($firstEntry['type'] === 'solo') {
            // Option A: 4 Solo players
            if (count($soloIndices) >= 4) {
                $s1 = $soloIndices[0];
                $s2 = $soloIndices[1];
                $s3 = $soloIndices[2];
                $s4 = $soloIndices[3];
                return [
                    'indices' => [$s1, $s2, $s3, $s4],
                    'team1_users' => [$queue[$s1]['user_ids'][0], $queue[$s2]['user_ids'][0]],
                    'team2_users' => [$queue[$s3]['user_ids'][0], $queue[$s4]['user_ids'][0]],
                ];
            }

            // Option B: 2 Solos (Team 1) + 1 Pair (Team 2)
            if (count($soloIndices) >= 2 && count($pairIndices) >= 1) {
                $s1 = $soloIndices[0];
                $s2 = $soloIndices[1];
                $p1 = $pairIndices[0];
                return [
                    'indices' => [$s1, $s2, $p1],
                    'team1_users' => [$queue[$s1]['user_ids'][0], $queue[$s2]['user_ids'][0]],
                    'team2_users' => $queue[$p1]['user_ids'],
                ];
            }

            return null;
        }

        return null;
    }

    /**
     * Create 2v2 Team Match (4 players total, 2 teams).
     */
    private function createTeamMatch(array $team1UserIds, array $team2UserIds, int $entryFee, string $queueKey): array
    {
        $allUserIds = array_merge($team1UserIds, $team2UserIds);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($team1UserIds, $team2UserIds, $allUserIds, $entryFee) {
            // Balance check for all 4 players if entryFee > 0
            if ($entryFee > 0) {
                foreach ($allUserIds as $uid) {
                    $wallet = \App\Models\Wallet::where('user_id', $uid)->lockForUpdate()->first();
                    if (!$wallet || $wallet->coins_balance < $entryFee) {
                        return ['status' => 'cancelled', 'message' => "Insufficient balance for user {$uid}"];
                    }
                }
            }

            // Create 2v2 Room
            $room = Room::create([
                'room_code' => strtoupper(Str::random(6)),
                'type' => RoomType::TEAM->value,
                'max_players' => 4,
                'entry_fee' => $entryFee,
                'status' => RoomStatus::PLAYING->value,
                'created_by' => $team1UserIds[0],
                'created_at' => now(),
            ]);

            // Deduct entry fee atomically from all 4 players
            if ($entryFee > 0) {
                foreach ($allUserIds as $uid) {
                    \App\Models\Wallet::where('user_id', $uid)->decrement('coins_balance', $entryFee);
                    \App\Models\Transaction::create([
                        'user_id' => $uid,
                        'type' => \App\Enums\TransactionType::ENTRY_FEE,
                        'currency_type' => 'coins',
                        'amount' => -$entryFee,
                        'reference_id' => (string) $room->id,
                        'created_at' => now(),
                    ]);
                }
            }

            // Seat assignment using TeamAssignment:
            // Team 1: Seat 1 (Red), Seat 3 (Yellow)
            // Team 2: Seat 2 (Green), Seat 4 (Blue)
            $seatAssignments = [
                1 => ['user_id' => $team1UserIds[0], 'color' => \App\Support\TeamAssignment::colorForSeat(1)],
                2 => ['user_id' => $team2UserIds[0], 'color' => \App\Support\TeamAssignment::colorForSeat(2)],
                3 => ['user_id' => $team1UserIds[1], 'color' => \App\Support\TeamAssignment::colorForSeat(3)],
                4 => ['user_id' => $team2UserIds[1], 'color' => \App\Support\TeamAssignment::colorForSeat(4)],
            ];

            $players = [];
            $playerData = [];

            foreach ($seatAssignments as $seat => $info) {
                $user = User::find($info['user_id']);

                RoomPlayer::create([
                    'room_id' => $room->id,
                    'user_id' => $user->id,
                    'seat_position' => $seat,
                    'color' => $info['color'],
                    'is_ready' => true,
                    'joined_at' => now(),
                ]);

                $players[] = [
                    'user_id' => $user->id,
                    'username' => $user->username ?? 'Player',
                    'avatar_url' => $user->avatar_url,
                    'seat_position' => $seat,
                    'color' => $info['color'],
                    'team_id' => \App\Support\TeamAssignment::teamForSeat($seat),
                ];

                $playerData[] = [
                    'seat_position' => $seat - 1, // 0-indexed for engine
                    'user_id' => $user->id,
                    'username' => $user->username ?? 'Player',
                    'color' => $info['color'],
                ];
            }

            // Initialize Redis state via GameInitializerService
            $gameInitializer = app(\App\Services\GameInitializerService::class);
            [$game, $gameState] = $gameInitializer->initializeGame($room, $playerData);

            // Broadcast MatchFound and TeamMatchFound to all 4 players
            $team1List = array_values(array_filter($players, fn($p) => $p['team_id'] === 1));
            $team2List = array_values(array_filter($players, fn($p) => $p['team_id'] === 2));

            foreach ($players as $pInfo) {
                $uid = $pInfo['user_id'];
                broadcast(new MatchFound(
                    $uid,
                    $room->id,
                    $game->id,
                    $players
                ));

                broadcast(new \App\Events\TeamMatchFound(
                    $uid,
                    $room->id,
                    $game->id,
                    $team1List,
                    $team2List,
                    $pInfo['seat_position'],
                    $pInfo['color'],
                    $entryFee
                ));
            }

            // Dispatch turn timeout job
            ProcessTurnTimeout::dispatch($room->id, 0, $gameState['last_action_at'])
                ->delay(now()->addSeconds(15));

            return [
                'status' => 'matched',
                'quick_match_id' => $room->id,
                'room_id' => $room->id,
                'game_id' => $game->id,
                'players' => $players,
            ];
        });
    }

    private function findTeamEntryPosition(array $queue, int $userId): int|false
    {
        foreach ($queue as $idx => $entry) {
            if (in_array($userId, $entry['user_ids'], true)) {
                return $idx;
            }
        }
        return false;
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

