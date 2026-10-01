<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinVipRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_code' => ['required', 'string', 'max:20'],
        ];
    }
}
