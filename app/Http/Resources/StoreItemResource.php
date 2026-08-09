<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'price' => $this->price,
            'currency_type' => $this->currency_type,
            'image_url' => $this->image_url,
            'is_active' => $this->is_active,
            'is_equipped' => $this->pivot ? (bool) $this->pivot->is_equipped : false,
            'purchased_at' => $this->pivot ? $this->pivot->purchased_at : null,
        ];
    }
}
