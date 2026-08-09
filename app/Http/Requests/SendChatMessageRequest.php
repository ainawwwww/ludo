<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => 'required|integer|exists:rooms,id',
            'message' => 'required|string|max:500',
            'message_type' => 'nullable|in:text,emoji,voice,quick_chat',
        ];
    }
}
