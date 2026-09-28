<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePrivateRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     * Whitelists from config/private_room.php.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'max_players' => [
                'required',
                'integer',
                Rule::in(config('private_room.allowed_max_players', [2, 4])),
            ],
            'entry_fee' => [
                'required',
                'integer',
                Rule::in(config('private_room.allowed_entry_fees', [0, 500, 1000, 5000])),
            ],
            'turn_seconds' => [
                'nullable',
                'integer',
                Rule::in(config('private_room.allowed_turn_seconds', [10, 15, 30])),
            ],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
