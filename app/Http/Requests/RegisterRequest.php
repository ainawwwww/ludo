<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('country')) {
            $countryUpper = strtoupper((string) $this->input('country'));
            $this->merge(['country' => $countryUpper]);

            if (!$this->filled('country_code')) {
                $countries = config('countries');
                $matchedCountry = collect($countries)->firstWhere('code', $countryUpper);
                if ($matchedCountry && !empty($matchedCountry['dial_code'])) {
                    $this->merge([
                        'country_code' => $matchedCountry['dial_code'],
                    ]);
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'nullable|email|max:100|unique:users,email',
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'password' => 'required|string|min:6',
            'avatar_url' => 'nullable|string|url',
            'country' => ['required', 'string', 'max:10', function ($attribute, $value, $fail) {
                $countries = config('countries');
                $valid = collect($countries)->contains('code', strtoupper($value));
                if (!$valid) {
                    $fail('The selected country is not valid.');
                }
            }],
            'country_code' => 'nullable|string|max:10',
            'device_id' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ];
    }

    /**
     * Configure the validator instance.
     * Validates phone number against the selected country's phone pattern.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $country = $this->input('country');
            $phone = $this->input('phone');

            if (!empty($phone) && !empty($country)) {
                $countries = config('countries');
                $countryData = collect($countries)->firstWhere('code', strtoupper($country));

                if ($countryData && !empty($countryData['phone_pattern'])) {
                    $pattern = '/' . $countryData['phone_pattern'] . '/';
                    if (!preg_match($pattern, $phone)) {
                        $validator->errors()->add(
                            'phone',
                            "Phone number format is invalid for {$countryData['name']}. Expected format: {$countryData['phone_placeholder']}"
                        );
                    }
                }
            }
        });
    }
}
