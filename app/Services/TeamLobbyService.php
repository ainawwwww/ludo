<?php

namespace App\Services;

use App\Enums\RoomStatus;
use App\Enums\RoomType;
use App\Exceptions\PrivateRoomException;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Support\TeamAssignment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Isolated Service for 2-Seat Team Party Lobbies (CREATE / JOIN flow).
 * Handles 2-player lobby lifecycle before entering the unified 2v2 matchmaking queue.
 */
class TeamLobbyService
{
    public const ALLOWED_ENTRY_FEES = [0, 500, 1000, 2500, 5000, 10000];

    public function __construct(
        protected WalletService $walletService,
        protected RoomCodeGenerator $codeGenerator
    ) {}

    /**
     * Get user's active room (waiting or playing) across ANY room type (private, vip, team).
     */
    public function getUserActiveTeamRoom(User|int $user): ?Room
    {
        $userId = $user instanceof User ? $user->id : (int) $user;

        return Room::whereIn('status', [RoomStatus::WAITING, RoomStatus::PLAYING])
            ->whereIn('type', [RoomType::PRIVATE, RoomType::VIP, RoomType::TEAM])
            ->whereHas('players', fn($q) => $q->where('user_id', $userId))
            ->first();
    }

    /**
     * Create a 2-seat Team Party Lobby (Host + Teammate).
     *
     * @throws PrivateRoomException
     */
    public function create(
        User $user,
        int $entryFee = 500,
        ?int $turnSeconds = 15
    ): Room {
        $allowed = config('team_room.allowed_entry_fees', self::ALLOWED_ENTRY_FEES);
        if (!in_array($entryFee, $allowed, true)) {
            throw new InvalidArgumentException("Entry fee {$entryFee} is not allowed.");
        }

        // Check if user is already in an active room
        if ($this->getUserActiveTeamRoom($user)) {
            throw PrivateRoomException::alreadyInRoom();
        }

        // Check wallet balance
        if ($entryFee > 0 && $this->walletService->getBalance($user) < $entryFee) {
            throw PrivateRoomException::insufficientBalance();
        }

        return DB::transaction(function () use ($user, $entryFee, $turnSeconds) {
            $code = $this->codeGenerator->generateUnique();

            $room = Room::create([
                'room_code' => $code,
                'type' => RoomType::TEAM,
                'max_players' => 2, // 2-seat party lobby
                'entry_fee' => $entryFee,
                'turn_seconds' => $turnSeconds ?? 15,
                'status' => RoomStatus::WAITING,
                'created_by' => $user->id,
                'state_version' => 1,
            ]);

            // Host takes seat 1 (Red)
            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => 1,
                'color' => 'red',
                'is_ready' => true,
                'joined_at' => now(),
            ]);

            $room->update(['member_count' => 1]);

            return $room->fresh(['players.user', 'creator']);
        });
    }

    /**
     * Join an existing 2-seat Team Party Lobby using a 6-digit code.
     *
     * @throws PrivateRoomException
     */
    public function join(User $user, string $roomCode): Room
    {
        $code = strtoupper(trim($roomCode));

        if (!$this->codeGenerator->isValid($code)) {
            throw PrivateRoomException::invalidCode();
        }

        $room = Room::where('room_code', $code)
            ->where('type', RoomType::TEAM)
            ->where('status', RoomStatus::WAITING)
            ->lockForUpdate()
            ->first();

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        if ($this->getUserActiveTeamRoom($user)) {
            throw PrivateRoomException::alreadyInRoom();
        }

        if ($room->member_count >= 2) {
            throw PrivateRoomException::full();
        }

        if ($room->entry_fee > 0 && $this->walletService->getBalance($user) < $room->entry_fee) {
            throw PrivateRoomException::insufficientBalance();
        }

        return DB::transaction(function () use ($room, $user) {
            // Re-check member count inside transaction lock
            $currentPlayersCount = $room->players()->count();
            if ($currentPlayersCount >= 2) {
                throw PrivateRoomException::full();
            }

            // Teammate guest takes seat 2 (Yellow in party lobby)
            RoomPlayer::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'seat_position' => 2,
                'color' => 'yellow',
                'is_ready' => false,
                'joined_at' => now(),
            ]);

            $room->increment('member_count');
            $room->increment('state_version');

            $updatedRoom = $room->fresh(['players.user', 'creator']);
            $snapshot = app(\App\Services\PrivateRoomService::class)->publicSnapshot($updatedRoom);

            event(new \App\Events\TeamPartnerJoined(
                $room->id,
                $user->id,
                $user->username ?? 'Partner',
                2,
                'yellow',
                $snapshot,
                $updatedRoom->state_version
            ));

            event(new \App\Events\PrivateRoomUpdated(
                $room->id,
                'guest_joined',
                $user->id,
                $snapshot,
                $updatedRoom->state_version
            ));

            return $updatedRoom;
        });
    }

    /**
     * Leave the team party lobby.
     *
     * @throws PrivateRoomException
     */
    public function leave(User $user): array
    {
        $room = $this->getUserActiveTeamRoom($user);

        if (!$room) {
            throw PrivateRoomException::notFound();
        }

        if ($room->status !== RoomStatus::WAITING) {
            throw PrivateRoomException::cannotLeavePlaying();
        }

        return DB::transaction(function () use ($room, $user) {
            $isHost = ((int) $room->created_by === (int) $user->id);

            if ($isHost) {
                // Host leaves -> disband team lobby
                $room->status = RoomStatus::CANCELLED;
                $room->increment('state_version');
                $room->save();

                return [
                    'status' => 'disbanded',
                    'message' => 'Team room disbanded by host.',
                    'room' => $room,
                ];
            }

            // Guest leaves -> remove player
            RoomPlayer::where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->delete();

            $room->decrement('member_count');
            $room->increment('state_version');
            $room->save();

            return [
                'status' => 'left',
                'message' => 'Left team room.',
                'room' => $room->fresh(['players.user']),
            ];
        });
    }

    /**
     * Toggle readiness of guest player in team party lobby.
     */
    public function toggleReady(User $user, ?bool $isReady = null): Room
    {
        $room = $this->getUserActiveTeamRoom($user);

        if (!$room || $room->status !== RoomStatus::WAITING) {
            throw PrivateRoomException::notFound();
        }

        $player = RoomPlayer::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$player) {
            throw PrivateRoomException::notFound();
        }

        $newReady = $isReady ?? !$player->is_ready;

        DB::transaction(function () use ($room, $player, $newReady) {
            $player->update(['is_ready' => $newReady]);
            $room->increment('state_version');
        });

        return $room->fresh(['players.user', 'creator']);
    }

    /**
     * Check if a team party lobby is fully ready (2 players, guest is ready).
     */
    public function canQueueForMatch(Room $room): bool
    {
        if ($room->status !== RoomStatus::WAITING || $room->member_count < 2) {
            return false;
        }

        $guest = $room->players()->where('seat_position', 2)->first();
        return $guest !== null && (bool) $guest->is_ready;
    }
}
