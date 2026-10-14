<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grant_type' => 'sometimes|in:password,refresh_token',
            'email' => 'nullable|string',
            'phone' => 'nullable|string',
            'password' => 'nullable|string',
            'refresh_token' => 'nullable|string',
        ];
    }
}
