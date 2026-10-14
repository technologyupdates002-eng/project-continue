<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OperationalTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'phone_number' => ['required', 'string', 'max:20'],
            'narration' => ['nullable', 'string', 'max:500'],
        ];
    }
}
