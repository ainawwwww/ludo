<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FriendResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentUserId = $request->user()?->id;
        $other = $this->user_id === $currentUserId ? $this->friend : $this->user;
        $isOnline = (bool) ($other?->is_active && $other?->tokens()->exists());

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'friend_id' => $this->friend_id,
            'status' => $this->status,
            'sender' => new UserResource($this->whenLoaded('user')),
            'friend' => new UserResource($this->whenLoaded('friend')),
            'username' => $other?->username ?? 'Player',
            'avatar_url' => $other?->avatar_url,
            'is_online' => $isOnline,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
