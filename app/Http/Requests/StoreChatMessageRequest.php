<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kilobytes = max(1, (int) config('kimi.image_max_kilobytes'));
        $edge = max(1, (int) config('kimi.image_max_source_edge'));

        return [
            'message' => ['nullable', 'string', 'max:4000', 'required_without:image'],
            'image' => [
                'nullable',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.$kilobytes,
                'required_without:message',
                function (string $attribute, mixed $value, Closure $fail) use ($edge): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }

                    $info = @getimagesize($value->getPathname());

                    if ($info === false) {
                        $fail(__('chat.image_invalid'));

                        return;
                    }

                    $width = $info[0] ?? 0;
                    $height = $info[1] ?? 0;

                    if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1 || $width > $edge || $height > $edge) {
                        $fail(__('chat.image_too_large'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required_without' => __('chat.message_required'),
            'message.string' => __('chat.message_required'),
            'message.max' => __('chat.message_too_long'),
            'image.required_without' => __('chat.message_required'),
            'image.file' => __('chat.image_invalid'),
            'image.mimes' => __('chat.image_invalid'),
            'image.max' => __('chat.image_too_large'),
        ];
    }
}
