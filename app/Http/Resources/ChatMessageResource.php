<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'user_id' => $this->user_id,
            'username' => $this->relationLoaded('user') ? $this->user?->username : null,
            'message' => $this->message,
            'message_type' => $this->message_type ?? 'text',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
