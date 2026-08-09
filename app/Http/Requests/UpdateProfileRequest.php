<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateProfileRequest
 *
 * Validates profile update payload (multipart/form-data).
 *
 * Request Format (Postman-style):
 * POST /api/v1/profile (multipart/form-data)
 * Headers: Authorization: Bearer <token>
 *
 * Fields:
 *   name        (string, optional)  — New display name. Max 3 changes per rolling 24h.
 *   avatar      (file, optional)    — Profile image. Max 2MB, jpg/png only.
 *   gender      (string, optional)  — One of: male, female, unspecified.
 *   dob         (date, optional)    — Date of birth (YYYY-MM-DD). Must be past, min age 13.
 *   country     (string, optional)  — Country name or code, max 100 chars.
 *   bio         (string, optional)  — Short bio, max 255 chars.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:50',
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'gender' => 'nullable|string|in:male,female,unspecified',
            'dob' => [
                'nullable',
                'date',
                'before:today',
                'before_or_equal:' . now()->subYears(13)->toDateString(),
            ],
            'country' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.max' => 'Avatar image must not exceed 2MB.',
            'avatar.mimes' => 'Avatar must be a JPG or PNG image.',
            'dob.before' => 'Date of birth must be a past date.',
            'dob.before_or_equal' => 'You must be at least 13 years old.',
        ];
    }
}
