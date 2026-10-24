<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');

        if (! is_string($message)) {
            return;
        }

        $normalized = preg_replace("/\r\n|\r/", "\n", trim($message));

        if (is_string($normalized)) {
            $this->merge([
                'message' => $normalized,
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => __('chat.message_required'),
            'message.string' => __('chat.message_required'),
            'message.max' => __('chat.message_too_long'),
        ];
    }
}
