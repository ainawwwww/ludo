<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinPrivateRoomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation: trim and uppercase room_code.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('room_code')) {
            $this->merge([
                'room_code' => strtoupper(trim((string) $this->room_code)),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     * Validate exact 6-character alphanumeric room code.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_code' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{6}$/',
            ],
        ];
    }

    /**
     * Custom messages for validation.
     */
    public function messages(): array
    {
        return [
            'room_code.regex' => 'The room code must be exactly 6 uppercase alphanumeric characters.',
        ];
    }
}
