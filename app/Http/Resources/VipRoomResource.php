<?php

namespace App\Http\Resources;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VipRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        /** @var Room $room */
        $room = $this->resource;
        $user = $request->user();

        return app(\App\Services\PrivateRoomService::class)->snapshot($room, $user, \App\Enums\RoomType::VIP);
    }
}
