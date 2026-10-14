<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminRegistrationConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'retiring_date' => ['nullable', 'date'],
            'registration_fee' => ['required', 'numeric', 'min:0'],
            'active' => ['required', 'boolean'],
            'show_on_login' => ['required', 'boolean'],
            'auto_approve' => ['required', 'boolean'],
        ];
    }
}
