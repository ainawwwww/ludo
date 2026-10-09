<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendFriendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'friend_id' => 'required_without:username|nullable|integer|exists:users,id',
            'username' => 'required_without:friend_id|nullable|string|exists:users,username',
        ];
    }
}
