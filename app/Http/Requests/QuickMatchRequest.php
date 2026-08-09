<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuickMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_players' => 'nullable|integer|between:2,4',
            'entry_fee' => 'nullable|integer|min:0',
        ];
    }
}
