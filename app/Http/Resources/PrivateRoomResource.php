<?php

namespace App\Http\Resources;

use App\Enums\RoomStatus;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrivateRoomResource extends JsonResource
{
    /**
     * Transform the resource into an array matching the exact unified private room snapshot shape:
     * id, code, status, max_players, entry_fee, turn_seconds, host_user_id,
     * players[{user_id, name, avatar, seat, color, is_ready, is_host}],
     * my_seat, is_host, can_start, game_id (when playing), version.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // If resource is already an array returned by PrivateRoomService::snapshot
        if (is_array($this->resource)) {
            return $this->resource;
        }

        // Otherwise format directly from Room model
        /** @var Room $room */
        $room = $this->resource;
        $user = $request->user();

        $myPlayer = $user && $room->players ? $room->players->firstWhere('user_id', $user->id) : null;
        $isHost = $user ? ($user->id === $room->created_by) : false;

        $playersCount = $room->players ? $room->players->count() : 0;
        $allGuestsReady = $room->players
            ? $room->players->where('user_id', '!=', $room->created_by)->where('is_ready', false)->isEmpty()
            : true;

        $statusStr = $room->status instanceof \BackedEnum ? $room->status->value : (string) $room->status;
        $isWaiting = ($room->status === RoomStatus::WAITING || $statusStr === 'waiting');
        $isPlaying = ($room->status === RoomStatus::PLAYING || $statusStr === 'playing');

        $canStart = $isWaiting && ($playersCount === $room->max_players) && $allGuestsReady;
        $gameId = $isPlaying ? $room->game?->id : null;

        return [
            'id' => $room->id,
            'code' => $room->room_code,
            'status' => $statusStr,
            'max_players' => $room->max_players,
            'entry_fee' => $room->entry_fee,
            'turn_seconds' => $room->turn_seconds,
            'host_user_id' => $room->created_by,
            'players' => $room->players ? $room->players->map(fn($p) => [
                'user_id' => $p->user_id,
                'name' => $p->user->username ?? 'Player',
                'avatar' => $p->user->avatar_url ?? null,
                'seat' => $p->seat_position,
                'color' => $p->color instanceof \BackedEnum ? $p->color->value : $p->color,
                'is_ready' => (bool) $p->is_ready,
                'is_host' => $p->user_id === $room->created_by,
            ])->values()->toArray() : [],
            'my_seat' => $myPlayer?->seat_position,
            'is_host' => $isHost,
            'can_start' => $canStart,
            'game_id' => $gameId,
            'version' => (int) ($room->state_version ?? 0),
        ];
    }
}
