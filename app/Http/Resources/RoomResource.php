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
            'room_id' => $this->id,
            'room_code' => $this->room_code,
            'title' => $this->title ?? 'Ludo VIP Lounge #' . $this->id,
            'name' => $this->title ?? 'Ludo VIP Lounge #' . $this->id,
            'category' => $this->category ?? 'social',
            'tags' => $this->tags ?? ['Ludo', 'Social'],
            'country_code' => $this->country_code,
            'cover_image' => $this->cover_image,
            'member_count' => $this->member_count ?? ($this->players ? $this->players->count() : 1),
            'is_live' => (bool) ($this->is_live ?? true),
            'is_mine' => $this->created_by === $request->user()?->id,
            'type' => $this->type,
            'max_players' => $this->max_players,
            'entry_fee' => $this->entry_fee,
            'status' => $this->status,
            'game_id' => $this->game?->id,
            'created_by' => $this->created_by,
            'creator' => new UserResource($this->whenLoaded('creator')),
            'players' => $this->players ? $this->players->map(function ($player) {
                return [
                    'id' => $player->id,
                    'user_id' => $player->user_id,
                    'username' => $player->user ? $player->user->username : 'Player',
                    'avatar_url' => $player->user ? $player->user->avatar_url : null,
                    'seat_position' => $player->seat_position,
                    'color' => $player->color,
                    'is_ready' => (bool) $player->is_ready,
                    'score' => $player->score ?? 0,
                    'joined_at' => $player->joined_at?->toIso8601String(),
                ];
            }) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
