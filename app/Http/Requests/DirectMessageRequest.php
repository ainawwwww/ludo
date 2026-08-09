<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DirectMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:text,voice',
            'message' => 'required_if:type,text|prohibited_if:type,voice|nullable|string|max:1000',
            'voice_note' => 'required_if:type,voice|prohibited_if:type,text|nullable|file|mimetypes:audio/mpeg,audio/mp3,audio/x-m4a,audio/m4a,audio/aac,audio/wav,audio/x-wav,audio/ogg,audio/vorbis|max:5120',
            'voice_duration' => 'required_if:type,voice|prohibited_if:type,text|nullable|integer|min:1|max:300',
        ];
    }

    public function messages(): array
    {
        return [
            'message.required_if' => 'Text message is required when type is text.',
            'voice_note.required_if' => 'Voice note audio file is required when type is voice.',
            'voice_note.mimetypes' => 'Voice note must be a valid audio file (mp3, m4a, aac, wav, ogg).',
            'voice_note.max' => 'Voice note audio file must not exceed 5MB.',
            'voice_duration.required_if' => 'Voice duration is required when type is voice.',
            'voice_duration.max' => 'Voice note duration cannot exceed 300 seconds (5 minutes).',
        ];
    }
}
