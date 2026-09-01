<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:50',
            'country_code' => 'nullable|string|max:10',
            'type' => 'nullable|in:public,private',
            'max_players' => 'nullable|integer|between:2,4',
            'entry_fee' => 'nullable|integer|min:0',
        ];
    }
}
