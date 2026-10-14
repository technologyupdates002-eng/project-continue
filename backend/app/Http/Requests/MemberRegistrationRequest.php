<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MemberRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:20'],
            'department' => ['required', 'string', 'max:255'],
            'working_location' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date'],
        ];
    }
}
