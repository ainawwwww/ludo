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

        /** @var Room $room */
        $room = $this->resource;
        $user = $request->user();

        return app(\App\Services\PrivateRoomService::class)->snapshot($room, $user);
    }
}
