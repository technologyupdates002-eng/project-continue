<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class B2cTransferRequest extends FormRequest
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
            'member_id' => ['nullable', 'string'],
            'narration' => ['nullable', 'string', 'max:500'],
            'withdrawal_id' => ['required', 'string'],
            'wallet_type' => ['required', 'string', 'in:penalty,donation,operational'],
        ];
    }
}
