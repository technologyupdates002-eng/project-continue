<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['sometimes', 'nullable', 'string', 'max:1000', 'required_without:messages'],
            'messages' => ['sometimes', 'array', 'max:50'],
            'messages.*.role' => ['required_with:messages', 'in:user,assistant,system'],
            'messages.*.content' => ['required_with:messages', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'uuid'],
            'context_type' => ['nullable', 'string', 'max:100'],
            'context' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('query') && $this->has('messages') && is_array($this->input('messages'))) {
            $lastUserMessage = collect($this->input('messages'))
                ->filter(fn ($message) => is_array($message) && ($message['role'] ?? null) === 'user')
                ->last();

            if (is_array($lastUserMessage) && isset($lastUserMessage['content'])) {
                $this->merge([
                    'query' => (string) $lastUserMessage['content'],
                ]);
            }
        }
    }
}
