<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RollDiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => 'required|integer|exists:rooms,id',
        ];
    }
}
