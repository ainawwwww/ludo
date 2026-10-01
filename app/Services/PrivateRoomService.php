<?php

namespace App\Services;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
use App\Events\PrivateRoomUpdated;
use App\Events\TurnChanged;
use App\Exceptions\PrivateRoomException;
use App\Jobs\ProcessTurnTimeout;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PrivateRoomService
{
    public function __construct(
        protected WalletService $walletService,
        protected RoomCodeGenerator $codeGenerator,
        protected GameInitializerService $gameInitializerService,
        protected SubscriptionService $subscriptionService
    ) {}

    protected function getConfigPrefix(RoomType $roomType): string
    {
        return $roomType === RoomType::VIP ? 'vip_room' : 'private_room';
    }

    /**
     * Get user's current active private/vip room (waiting or playing), if any.
     */
    public function getUserActiveRoom(User|int $user, ?RoomType $roomType = null): ?Room
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        $query = Room::whereIn('status', [RoomStatus::WAITING, RoomStatus::PLAYING])
            ->whereHas('players', fn($q) => $q->where('user_id', $userId));

        if ($roomType !== null) {
            $query->where('type', $roomType);
        } else {
            $query->whereIn('type', [RoomType::PRIVATE, RoomType::VIP, RoomType::TEAM]);
        }

        $room = $query->first();

        if ($room && ($room->status === RoomStatus::PLAYING || $room->status->value === 'playing')) {
            $hasActiveGame = $room->games()
                ->where('status', \App\Enums\GameStatus::IN_PROGRESS->value)
                ->exists();
            $hasCompletedGame = $room->games()
                ->where('status', \App\Enums\GameStatus::COMPLETED->value)
                ->exists();

            if ($hasCompletedGame && !$hasActiveGame) {
                $room->status = RoomStatus::FINISHED;
                $room->state_version = ((int) $room->state_version) + 1;
                $room->save();
                return null;
            }
        }

        return $room;
    }

    /**
     * Create a new room (private or vip).
     *
     * @throws PrivateRoomException
     * @throws InvalidArgumentException
     */
    public function create(
        User $user,
        int $maxPlayers,
        int $entryFee,
        ?int $turnSeconds = null,
        ?string $title = null,
        RoomType $roomType = RoomType::PRIVATE
    ): Room {
        if ($roomType === RoomType::VIP) {
            if (!$this->subscriptionService->hasActiveVip($user)) {
                throw PrivateRoomException::vipSubscriptionRequired();
            }
        }
        $configKey = $this->getConfigPrefix($roomType);
        $allowedMaxPlayers = config("{$configKey}.allowed_max_players", [2, 4]);
        if (!in_array($maxPlayers, $allowedMaxPlayers, true)) {
            throw new InvalidArgumentException("Invalid max_players: {$maxPlayers}. Allowed: " . implode(', ', $allowedMaxPlayers));
        }

        $defaultFees = $roomType === RoomType::VIP ? [1000, 5000, 10000, 25000] : [0, 500, 1000, 5000];
        $allowedFees = config("{$configKey}.allowed_entry_fees", $defaultFees);
        if (!in_array($entryFee, $allowedFees, true)) {
            throw new InvalidArgumentException("Invalid entry_fee: {$entryFee}. Allowed: " . implode(', ', $allowedFees));
        }

        $turnSeconds = $turnSeconds ?? config("{$configKey}.default_turn_seconds", 15);
        $allowedTurnSeconds = config("{$configKey}.allowed_turn_seconds", [10, 15, 30]);
        if (!in_array($turnSeconds, $allowedTurnSeconds, true)) {
            throw new InvalidArgumentException("Invalid turn_seconds: {$turnSeconds}. Allowed: " . implode(', ', $allowedTurnSeconds));
        }

        // Rule: A user can be in only ONE active room
        $activeRoom = $this->getUserActiveRoom($user);
        if ($activeRoom !== null) {
            throw PrivateRoomException::alreadyInRoom(
                "You are already in an active room ({$activeRoom->room_code})",
                $activeRoom->id
            );
        }

        // Pre-check host balance
        if ($entryFee > 0 && $this->walletService->getBalance($user, 'coins') < $entryFee) {
            throw PrivateRoomException::insufficientBalance(
                "Insufficient coins to create room with entry fee of {$entryFee}"
            );
        }

        $code = $this->codeGenerator->generateUnique();

        return DB::transaction(function () use ($user, $code, $maxPlayers, $entryFee, $turnSeconds, $title, $roomType) {
            $room = Room::create([
                'room_code' => $code,
                'title' => $title ?? "{$user->username}'s Room",
                'type' => $roomType->value,
                'max_players' => $maxPlayers,
                'entry_fee' => $entryFee,
                'turn_seconds' => $turnSeconds,
                'status' => RoomStatus::WAITING->value,
                'state_version' => 1,
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            // Host takes Seat 1 with Red color and is ready by default
            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => 1,
                'color' => PlayerColor::RED->value,
                'is_ready' => true,
                'joined_at' => now(),
            ]);

            return $room->load(['creator', 'players.user']);
        });
    }

    /**
     * Join an existing room by room code.
     *
     * @throws PrivateRoomException
     */
    public function join(User $user, string $roomCode, RoomType $roomType = RoomType::PRIVATE): RoomPlayer
    {
        $normalizedCode = strtoupper(trim($roomCode));
        if (!$this->codeGenerator->isValid($normalizedCode)) {
            throw PrivateRoomException::notFound('Invalid room code format');
        }

        return DB::transaction(function () use ($user, $normalizedCode, $roomType) {
            // Find and lock room row
            $room = Room::where('room_code', $normalizedCode)
                ->where('type', $roomType)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                $typeName = ucfirst($roomType->value);
                throw PrivateRoomException::notFound("{$typeName} room with code {$normalizedCode} not found");
            }

            if ($room->status !== RoomStatus::WAITING) {
                throw PrivateRoomException::alreadyStarted('Room is not waiting for players');
            }

            // Check if user is already in this specific room
            if ($room->players()->where('user_id', $user->id)->exists()) {
                throw PrivateRoomException::alreadyInRoom('You are already in this room', $room->id);
            }

            // Rule: A user can be in only ONE active room
            $activeRoom = $this->getUserActiveRoom($user);
            if ($activeRoom !== null) {
                throw PrivateRoomException::alreadyInRoom(
                    "You are already in an active room ({$activeRoom->room_code})",
                    $activeRoom->id
                );
            }

            $currentPlayers = $room->players()->lockForUpdate()->get();
            if ($currentPlayers->count() >= $room->max_players) {
                throw PrivateRoomException::full();
            }

            // Pre-check balance
            if ($room->entry_fee > 0 && $this->walletService->getBalance($user, 'coins') < $room->entry_fee) {
                throw PrivateRoomException::insufficientBalance(
                    "Insufficient coins for entry fee of {$room->entry_fee}"
                );
            }

            // Assign seat (1..max_players) and color
            $takenSeats = $currentPlayers->pluck('seat_position')->toArray();
            $assignedSeat = null;
            for ($s = 1; $s <= $room->max_players; $s++) {
                if (!in_array($s, $takenSeats, true)) {
                    $assignedSeat = $s;
                    break;
                }
            }

            if ($assignedSeat === null) {
                throw PrivateRoomException::full();
            }

            // Color scheme based on 2 or 4 player private rules
            $colorMap = $room->max_players === 2
                ? [1 => PlayerColor::RED->value, 2 => PlayerColor::YELLOW->value]
                : [
                    1 => PlayerColor::RED->value,
                    2 => PlayerColor::GREEN->value,
                    3 => PlayerColor::YELLOW->value,
                    4 => PlayerColor::BLUE->value,
                ];

            $takenColors = $currentPlayers->pluck('color')->map(fn($c) => $c->value ?? $c)->toArray();
            $assignedColor = $colorMap[$assignedSeat] ?? PlayerColor::RED->value;
            if (in_array($assignedColor, $takenColors, true)) {
                foreach ($colorMap as $c) {
                    if (!in_array($c, $takenColors, true)) {
                        $assignedColor = $c;
                        break;
                    }
                }
            }

            $player = RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => $assignedSeat,
                'color' => $assignedColor,
                'is_ready' => false,
                'joined_at' => now(),
            ]);

            $room->state_version = ((int) $room->state_version) + 1;
            $room->save();

            $publicSnapshot = $this->publicSnapshot($room);
            PrivateRoomUpdated::dispatch($room->id, 'joined', $user->id, $publicSnapshot, (int) $room->state_version);

            return $player;
        });
    }

    /**
     * Leave a room (private or vip).
     * Host leaving disbands/cancels the room (without deleting player records).
     * Non-host leaving frees their seat.
     * Cannot leave while match is playing.
     *
     * @throws PrivateRoomException
     */
    public function leave(User $user, int|Room $room, RoomType $roomType = RoomType::PRIVATE): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId, $roomType) {
            $room = Room::where('id', $roomId)
                ->where('type', $roomType)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw PrivateRoomException::notFound();
            }

            if ($room->status !== RoomStatus::WAITING) {
                throw PrivateRoomException::alreadyStarted('Cannot leave a match in progress. Use forfeit instead.');
            }

            $player = $room->players()->where('user_id', $user->id)->first();
            if (!$player) {
                return ['action' => 'none', 'room_id' => $roomId];
            }

            // If host leaves, cancel room (preserve player records so snapshots/events can be produced)
            if ($room->created_by === $user->id) {
                $room->status = RoomStatus::CANCELLED;
                $room->state_version = ((int) $room->state_version) + 1;
                $room->save();

                $publicSnapshot = $this->publicSnapshot($room);
                PrivateRoomUpdated::dispatch($room->id, 'cancelled', $user->id, $publicSnapshot, (int) $room->state_version);

                return [
                    'action' => 'room_disbanded',
                    'room_id' => $room->id,
                    'status' => RoomStatus::CANCELLED->value,
                    'version' => (int) $room->state_version,
                ];
            }

            // Non-host leaves -> delete their player row
            $player->delete();
            $room->state_version = ((int) $room->state_version) + 1;
            $room->save();

            $publicSnapshot = $this->publicSnapshot($room);
            PrivateRoomUpdated::dispatch($room->id, 'left', $user->id, $publicSnapshot, (int) $room->state_version);

            return [
                'action' => 'player_left',
                'room_id' => $room->id,
                'user_id' => $user->id,
                'status' => $room->status->value,
                'version' => (int) $room->state_version,
            ];
        });
    }

    /**
     * Toggle or set player ready state.
     *
     * @throws PrivateRoomException
     */
    public function toggleReady(User $user, int|Room $room, ?bool $isReady = null, RoomType $roomType = RoomType::PRIVATE): RoomPlayer
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId, $isReady, $roomType) {
            $room = Room::where('id', $roomId)
                ->where('type', $roomType)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw PrivateRoomException::notFound();
            }

            if ($room->status !== RoomStatus::WAITING) {
                throw PrivateRoomException::alreadyStarted();
            }

            $player = $room->players()->where('user_id', $user->id)->first();
            if (!$player) {
                throw PrivateRoomException::notFound('Player not in this room');
            }

            // Host is always ready
            if ($room->created_by === $user->id) {
                $player->is_ready = true;
                $player->save();
                return $player;
            }

            $player->is_ready = $isReady !== null ? $isReady : !$player->is_ready;
            $player->save();

            $room->state_version = ((int) $room->state_version) + 1;
            $room->save();

            $publicSnapshot = $this->publicSnapshot($room);
            PrivateRoomUpdated::dispatch($room->id, 'ready', $user->id, $publicSnapshot, (int) $room->state_version);

            return $player;
        });
    }

    /**
     * Start match in a room.
     * Host only, full room (all seats filled), all guests ready.
     * Uses deterministic wallet reference IDs; atomic rollback if any player has low balance.
     *
     * @throws PrivateRoomException
     */
    public function start(User $user, int|Room $room, RoomType $roomType = RoomType::PRIVATE): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId, $roomType) {
            $room = Room::where('id', $roomId)
                ->where('type', $roomType)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw PrivateRoomException::notFound();
            }

            // Host check
            if ($room->created_by !== $user->id) {
                throw PrivateRoomException::notHost();
            }

            // Status check
            if ($room->status !== RoomStatus::WAITING) {
                throw PrivateRoomException::alreadyStarted();
            }

            $players = $room->players()->with('user')->lockForUpdate()->get();

            // Player count check: must be full (2P = 2, 4P = 4)
            if ($players->count() !== $room->max_players) {
                throw PrivateRoomException::notEnoughPlayers(
                    "Need exactly {$room->max_players} players to start, currently have {$players->count()}"
                );
            }

            // Ready state check (all non-host players must be ready)
            $unready = $players->where('user_id', '!=', $room->created_by)->where('is_ready', false);
            if ($unready->count() > 0) {
                throw PrivateRoomException::playersNotReady();
            }

            // Deduct entry fee atomically for all players with deterministic reference IDs
            if ($room->entry_fee > 0) {
                try {
                    $prefix = $roomType === RoomType::VIP ? 'vip_room_start' : 'private_room_start';
                    foreach ($players as $p) {
                        $this->walletService->debit(
                            $p->user_id,
                            $room->entry_fee,
                            "{$prefix}:{$room->id}:{$p->user_id}",
                            TransactionType::ENTRY_FEE,
                            'coins'
                        );
                    }
                } catch (\App\Exceptions\InsufficientBalanceException $e) {
                    throw PrivateRoomException::insufficientBalance($e->getMessage());
                }
            }

            // Update room status and state version
            $room->status = RoomStatus::PLAYING;
            $room->state_version = ((int) $room->state_version) + 1;
            $room->save();

            // Prepare playerData for GameInitializerService (0-indexed seats)
            $playerData = [];
            foreach ($players->sortBy('seat_position') as $p) {
                $playerData[] = [
                    'seat_position' => $p->seat_position - 1,
                    'user_id' => $p->user_id,
                    'username' => $p->user->username ?? 'Player',
                    'color' => $p->color->value ?? $p->color,
                ];
            }

            // Initialize Game and Redis game state
            [$game, $gameState] = $this->gameInitializerService->initializeGame($room, $playerData);

            // Broadcast initial turn
            $initialTurnSeat = $gameState['current_turn_seat'] ?? 0;
            $initialUserId = $gameState['current_turn_user_id'] ?? $playerData[0]['user_id'];
            broadcast(new TurnChanged($room->id, $initialTurnSeat, $initialUserId, false, true));

            // Delayed turn timeout job: turn_seconds + 2
            $delay = ((int) ($room->turn_seconds ?? 15)) + 2;
            ProcessTurnTimeout::dispatch($room->id, $initialTurnSeat, $gameState['last_action_at'])
                ->delay(now()->addSeconds($delay));

            $publicSnapshot = $this->publicSnapshot($room->id);
            PrivateRoomUpdated::dispatch($room->id, 'started', $user->id, $publicSnapshot, (int) $room->state_version);

            return [
                'room' => $room->fresh(['creator', 'players.user']),
                'game' => $game,
                'game_state' => $gameState,
            ];
        });
    }

    /**
     * Get user-agnostic room snapshot (WITHOUT my_seat or is_host).
     * Exposes unified shape shared across broadcast events and API resources.
     * Contains NO sensitive user data (tokens, emails, phone numbers).
     *
     * @throws PrivateRoomException
     */
    public function publicSnapshot(int|Room $room, ?RoomType $roomType = null): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        $query = Room::with(['creator', 'players.user', 'game'])
            ->where('id', $roomId);

        if ($roomType !== null) {
            $query->where('type', $roomType);
        } else {
            $query->whereIn('type', [RoomType::PRIVATE, RoomType::VIP, RoomType::TEAM]);
        }

        $room = $query->first();

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        $playersCount = $room->players->count();
        $allGuestsReady = $room->players
            ->where('user_id', '!=', $room->created_by)
            ->where('is_ready', false)
            ->isEmpty();

        $statusStr = $room->status instanceof \BackedEnum ? $room->status->value : (string) $room->status;
        $isWaiting = ($room->status === RoomStatus::WAITING || $statusStr === 'waiting');
        $isPlaying = ($room->status === RoomStatus::PLAYING || $statusStr === 'playing');

        $canStart = $isWaiting
            && ($playersCount === $room->max_players)
            && $allGuestsReady;

        $gameId = $isPlaying ? $room->game?->id : null;

        return [
            'id' => $room->id,
            'code' => $room->room_code,
            'status' => $statusStr,
            'max_players' => $room->max_players,
            'entry_fee' => $room->entry_fee,
            'turn_seconds' => $room->turn_seconds,
            'host_user_id' => $room->created_by,
            'players' => $room->players->map(fn($p) => [
                'user_id' => $p->user_id,
                'name' => $p->user->username ?? 'Player',
                'avatar' => $p->user->avatar_url,
                'seat' => $p->seat_position,
                'color' => $p->color instanceof \BackedEnum ? $p->color->value : (string) $p->color,
                'is_ready' => (bool) $p->is_ready,
                'is_host' => $p->user_id === $room->created_by,
            ])->values()->toArray(),
            'can_start' => $canStart,
            'game_id' => $gameId,
            'version' => (int) ($room->state_version ?? 0),
        ];
    }

    /**
     * Get unified room snapshot matching the exact required API shape:
     * id, code, status, max_players, entry_fee, turn_seconds, host_user_id,
     * players[{user_id, name, avatar, seat, color, is_ready, is_host}],
     * my_seat, is_host, can_start, game_id (when playing), version.
     *
     * @throws PrivateRoomException
     */
    public function snapshot(int|Room $room, ?User $user = null, ?RoomType $roomType = null): array
    {
        $public = $this->publicSnapshot($room, $roomType);
        $myPlayer = $user ? collect($public['players'])->firstWhere('user_id', $user->id) : null;
        $isHost = $user ? ($user->id === $public['host_user_id']) : false;

        return array_merge($public, [
            'my_seat' => $myPlayer ? $myPlayer['seat'] : null,
            'is_host' => $isHost,
        ]);
    }
}
