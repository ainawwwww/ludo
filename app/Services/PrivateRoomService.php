<?php

namespace App\Services;

use App\Enums\PlayerColor;
use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Enums\TransactionType;
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
        protected GameInitializerService $gameInitializerService
    ) {}

    /**
     * Create a new private room.
     *
     * @throws PrivateRoomException
     * @throws InvalidArgumentException
     */
    public function create(
        User $user,
        int $maxPlayers,
        int $entryFee,
        ?int $turnSeconds = null,
        ?string $title = null
    ): Room {
        $allowedMaxPlayers = config('private_room.allowed_max_players', [2, 4]);
        if (!in_array($maxPlayers, $allowedMaxPlayers, true)) {
            throw new InvalidArgumentException("Invalid max_players: {$maxPlayers}. Allowed: " . implode(', ', $allowedMaxPlayers));
        }

        $allowedFees = config('private_room.allowed_entry_fees', [0, 500, 1000, 5000]);
        if (!in_array($entryFee, $allowedFees, true)) {
            throw new InvalidArgumentException("Invalid entry_fee: {$entryFee}. Allowed: " . implode(', ', $allowedFees));
        }

        $turnSeconds = $turnSeconds ?? config('private_room.default_turn_seconds', 15);
        $allowedTurnSeconds = config('private_room.allowed_turn_seconds', [10, 15, 30]);
        if (!in_array($turnSeconds, $allowedTurnSeconds, true)) {
            throw new InvalidArgumentException("Invalid turn_seconds: {$turnSeconds}. Allowed: " . implode(', ', $allowedTurnSeconds));
        }

        // Pre-check host balance
        if ($entryFee > 0 && $this->walletService->getBalance($user, 'coins') < $entryFee) {
            throw PrivateRoomException::insufficientBalance(
                "Insufficient coins to create room with entry fee of {$entryFee}"
            );
        }

        $code = $this->codeGenerator->generateUnique();

        return DB::transaction(function () use ($user, $code, $maxPlayers, $entryFee, $turnSeconds, $title) {
            $room = Room::create([
                'room_code' => $code,
                'title' => $title ?? "{$user->username}'s Room",
                'type' => RoomType::PRIVATE->value,
                'max_players' => $maxPlayers,
                'entry_fee' => $entryFee,
                'turn_seconds' => $turnSeconds,
                'status' => RoomStatus::WAITING->value,
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
     * Join an existing private room by room code.
     *
     * @throws PrivateRoomException
     */
    public function join(User $user, string $roomCode): RoomPlayer
    {
        $normalizedCode = strtoupper(trim($roomCode));
        if (!$this->codeGenerator->isValid($normalizedCode)) {
            throw PrivateRoomException::notFound('Invalid room code format');
        }

        return DB::transaction(function () use ($user, $normalizedCode) {
            // Find and lock room row
            $room = Room::where('room_code', $normalizedCode)
                ->where('type', RoomType::PRIVATE)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw PrivateRoomException::notFound("Private room with code {$normalizedCode} not found");
            }

            if ($room->status !== RoomStatus::WAITING) {
                throw PrivateRoomException::alreadyStarted('Room is not waiting for players');
            }

            // Check if user is already in the room
            if ($room->players()->where('user_id', $user->id)->exists()) {
                throw PrivateRoomException::alreadyInRoom();
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

            return RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => $assignedSeat,
                'color' => $assignedColor,
                'is_ready' => false,
                'joined_at' => now(),
            ]);
        });
    }

    /**
     * Leave a private room.
     * Host leaving disbands/cancels the room. Non-host leaving frees their seat.
     *
     * @throws PrivateRoomException
     */
    public function leave(User $user, int|Room $room): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId) {
            $room = Room::where('id', $roomId)
                ->where('type', RoomType::PRIVATE)
                ->lockForUpdate()
                ->first();

            if (!$room) {
                throw PrivateRoomException::notFound();
            }

            $player = $room->players()->where('user_id', $user->id)->first();
            if (!$player) {
                return ['action' => 'none', 'room_id' => $roomId];
            }

            // If host leaves, cancel room and disband all players
            if ($room->created_by === $user->id) {
                $room->status = RoomStatus::CANCELLED;
                $room->save();
                $room->players()->delete();

                return [
                    'action' => 'room_disbanded',
                    'room_id' => $room->id,
                    'status' => RoomStatus::CANCELLED->value,
                ];
            }

            // Non-host leaves
            $player->delete();

            return [
                'action' => 'player_left',
                'room_id' => $room->id,
                'user_id' => $user->id,
                'status' => $room->status->value,
            ];
        });
    }

    /**
     * Toggle or set player ready state.
     *
     * @throws PrivateRoomException
     */
    public function toggleReady(User $user, int|Room $room, ?bool $isReady = null): RoomPlayer
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId, $isReady) {
            $room = Room::where('id', $roomId)
                ->where('type', RoomType::PRIVATE)
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

            return $player;
        });
    }

    /**
     * Start match in a private room.
     * Host only, full room, all players ready, atomic entry fee deduction with rollback on failure.
     *
     * @throws PrivateRoomException
     */
    public function start(User $user, int|Room $room): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        return DB::transaction(function () use ($user, $roomId) {
            $room = Room::where('id', $roomId)
                ->where('type', RoomType::PRIVATE)
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

            // Player count check
            if ($players->count() < $room->max_players) {
                throw PrivateRoomException::notEnoughPlayers(
                    "Need {$room->max_players} players to start, currently have {$players->count()}"
                );
            }

            // Ready state check (all non-host players must be ready)
            $unready = $players->where('user_id', '!=', $room->created_by)->where('is_ready', false);
            if ($unready->count() > 0) {
                throw PrivateRoomException::playersNotReady();
            }

            // Deduct entry fee atomically for all players
            if ($room->entry_fee > 0) {
                foreach ($players as $p) {
                    $this->walletService->debit(
                        $p->user_id,
                        $room->entry_fee,
                        "private_room_{$room->id}_entry",
                        TransactionType::ENTRY_FEE,
                        'coins'
                    );
                }
            }

            // Update room status
            $room->status = RoomStatus::PLAYING;
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
            broadcast(new TurnChanged($room->id, $initialTurnSeat, $initialUserId, false));

            // Delayed turn timeout job: turn_seconds + 2
            $delay = ((int) ($room->turn_seconds ?? 15)) + 2;
            ProcessTurnTimeout::dispatch($room->id, $initialTurnSeat, $gameState['last_action_at'])
                ->delay(now()->addSeconds($delay));

            return [
                'room' => $room->fresh(['creator', 'players.user']),
                'game' => $game,
                'game_state' => $gameState,
            ];
        });
    }

    /**
     * Get room snapshot.
     */
    public function snapshot(int|Room $room, ?User $user = null): array
    {
        $roomId = $room instanceof Room ? $room->id : (int) $room;

        $room = Room::with(['creator', 'players.user'])
            ->where('id', $roomId)
            ->where('type', RoomType::PRIVATE)
            ->first();

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        return [
            'id' => $room->id,
            'room_code' => $room->room_code,
            'title' => $room->title,
            'status' => $room->status->value,
            'max_players' => $room->max_players,
            'turn_seconds' => $room->turn_seconds,
            'entry_fee' => $room->entry_fee,
            'host' => [
                'id' => $room->creator->id,
                'username' => $room->creator->username,
            ],
            'players' => $room->players->map(fn($p) => [
                'user_id' => $p->user_id,
                'username' => $p->user->username,
                'seat_position' => $p->seat_position,
                'color' => $p->color->value ?? $p->color,
                'is_ready' => (bool) $p->is_ready,
                'is_host' => $p->user_id === $room->created_by,
            ])->toArray(),
        ];
    }
}
