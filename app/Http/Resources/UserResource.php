<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'country' => $this->country,
            'country_code' => $this->country_code,
            'google_id' => $this->google_id,
            'auth_provider' => $this->auth_provider,
            'level' => $this->level,
            'xp' => $this->xp,
            'coins' => $this->coins,
            'diamonds' => $this->diamonds,
            'is_active' => $this->is_active,
            'is_guest' => (bool) $this->is_guest,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
