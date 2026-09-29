<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVipRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_players' => [
                'required',
                'integer',
                Rule::in(config('vip_room.allowed_max_players', [2, 4])),
            ],
            'entry_fee' => [
                'required',
                'integer',
                Rule::in(config('vip_room.allowed_entry_fees', [1000, 5000, 10000, 25000])),
            ],
            'turn_seconds' => [
                'nullable',
                'integer',
                Rule::in(config('vip_room.allowed_turn_seconds', [10, 15, 30])),
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
