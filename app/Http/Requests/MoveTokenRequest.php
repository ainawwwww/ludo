<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quick_match_id' => 'required_without:room_id|integer|exists:rooms,id',
            'room_id' => 'required_without:quick_match_id|integer|exists:rooms,id',
            'token_index' => 'required|integer|between:0,3',
        ];
    }
}
