<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    if (is_numeric($value)) {
                        if (!\App\Models\StoreItem::where('id', (int) $value)->exists()) {
                            $fail('The selected item does not exist.');
                        }
                    } else {
                        if (!\App\Models\StoreItem::where('item_key', (string) $value)->exists()) {
                            $fail('The selected item key does not exist.');
                        }
                    }
                },
            ],
        ];
    }
}
