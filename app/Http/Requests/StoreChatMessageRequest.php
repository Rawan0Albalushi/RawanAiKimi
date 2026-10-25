<?php

namespace App\Http\Requests;

use App\Services\ChatStore;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $pdfKilobytes = max(1, (int) config('kimi.document_max_kilobytes'));

        return [
            'message' => [
                'nullable',
                'string',
                'max:4000',
                Rule::requiredIf(function (): bool {
                    if ($this->hasFile('image')) {
                        return false;
                    }

                    $summarizing = $this->input('intent') === 'summarize'
                        && ($this->hasFile('pdf') || $this->hasFile('office') || $this->filled('document'));

                    return ! $summarizing;
                }),
            ],
            'intent' => ['nullable', 'in:summarize,question'],
            'document' => ['nullable', 'uuid'],
            'office' => [
                'nullable',
                'file',
                'max:'.$pdfKilobytes,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }

                    $name = strtolower(basename(str_replace('\\', '/', $value->getClientOriginalName())));

                    if (! str_ends_with($name, '.docx') && ! str_ends_with($name, '.xlsx')) {
                        $fail(__('chat.office_invalid'));

                        return;
                    }

                    $path = $value->getPathname();
                    $handle = fopen($path, 'rb');
                    $magic = $handle === false ? '' : (string) fread($handle, 4);

                    if (is_resource($handle)) {
                        fclose($handle);
                    }

                    if ($magic !== "PK\x03\x04") {
                        $fail(__('chat.office_invalid'));
                    }
                },
            ],
            'pdf' => [
                'nullable',
                'file',
                'mimes:pdf',
                'max:'.$pdfKilobytes,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        return;
                    }

                    $path = $value->getPathname();
                    $handle = fopen($path, 'rb');
                    $magic = $handle === false ? '' : (string) fread($handle, 5);

                    if (is_resource($handle)) {
                        fclose($handle);
                    }

                    if ($magic !== '%PDF-') {
                        $fail(__('chat.pdf_invalid'));
                    }
                },
            ],
            'image' => [
                'nullable',
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:'.$kilobytes,
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
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sources = (int) $this->hasFile('image') + (int) $this->hasFile('pdf') + (int) $this->hasFile('office') + (int) $this->filled('document');

                if ($sources > 1) {
                    $field = $this->hasFile('office') ? 'office' : 'pdf';
                    $validator->errors()->add($field, __('chat.pdf_and_image'));

                    return;
                }

                if ($validator->errors()->has('document') || ! $this->filled('document')) {
                    return;
                }

                $document = strtolower((string) $this->input('document'));
                $store = app(ChatStore::class);
                $activeId = $store->activeId();
                $transcript = $activeId === null ? [] : $store->messages($activeId);
                $known = false;

                if (is_array($transcript)) {
                    foreach ($transcript as $turn) {
                        $attachment = is_array($turn) ? ($turn['attachment'] ?? null) : null;

                        if (! is_array($attachment) || ! in_array($attachment['kind'] ?? null, ['pdf', 'docx', 'xlsx'], true) || ! is_string($attachment['id'] ?? null)) {
                            continue;
                        }

                        if (hash_equals(strtolower($attachment['id']), $document)) {
                            $known = true;
                            break;
                        }
                    }
                }

                if (! $known) {
                    $validator->errors()->add('document', __('chat.pdf_missing'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $pdfLimit = max(1, (int) round(((int) config('kimi.document_max_kilobytes')) / 1024));

        return [
            'message.required' => __('chat.message_required'),
            'message.string' => __('chat.message_required'),
            'message.max' => __('chat.message_too_long'),
            'image.file' => __('chat.image_invalid'),
            'image.mimes' => __('chat.image_invalid'),
            'image.max' => __('chat.image_too_large'),
            'pdf.file' => __('chat.pdf_invalid'),
            'pdf.mimes' => __('chat.pdf_invalid'),
            'pdf.max' => __('chat.pdf_too_large', ['limit' => $pdfLimit]),
            'office.file' => __('chat.office_invalid'),
            'office.max' => __('chat.office_too_large', ['limit' => $pdfLimit]),
            'document.uuid' => __('chat.pdf_missing'),
            'intent.in' => __('chat.message_required'),
        ];
    }
}
