<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_code' => $this->room_code,
            'type' => $this->type,
            'max_players' => $this->max_players,
            'entry_fee' => $this->entry_fee,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'players' => $this->players ? $this->players->map(function ($player) {
                return [
                    'id' => $player->id,
                    'user_id' => $player->user_id,
                    'username' => $player->user ? $player->user->username : null,
                    'avatar_url' => $player->user ? $player->user->avatar_url : null,
                    'seat_position' => $player->seat_position,
                    'color' => $player->color,
                    'is_ready' => $player->is_ready,
                    'score' => $player->score,
                    'joined_at' => $player->joined_at?->toIso8601String(),
                ];
            }) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
