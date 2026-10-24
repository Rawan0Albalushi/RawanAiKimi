<?php

namespace App\Services;

use App\Exceptions\InferenceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KimiService
{
    private const ALLOWED_HOST = 'api.runpod.ai';

    /**
     * @param  array<int, array{role: string, content: string|array<int, array<string, mixed>>}>  $messages
     */
    public function submit(array $messages): SubmittedJob
    {
        $endpointId = $this->configuredEndpointId();
        $messages = $this->sanitizeMessages($messages);
        $sensitive = $this->sensitiveTexts($messages);

        if (! collect($messages)->contains(fn (array $message): bool => $message['role'] === 'user')) {
            throw new InferenceException(__('chat.message_required'));
        }

        try {
            $response = $this->request((int) config('kimi.submit_timeout'))
                ->post($this->url($endpointId, 'run'), [
                    'input' => [
                        'openai_route' => '/v1/chat/completions',
                        'openai_input' => [
                            'model' => (string) config('kimi.model'),
                            'messages' => $messages,
                            'temperature' => $this->temperature(),
                            'max_tokens' => (int) config('kimi.max_tokens'),
                            'stream' => false,
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            $this->logFailure('submit', null);

            throw new InferenceException(__('chat.submit_rejected'));
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->logFailure('submit', $response->status(), $response, $sensitive);

            throw new InferenceException(__('chat.bad_key'));
        }

        if (! $response->successful()) {
            $this->logFailure('submit', $response->status(), $response, $sensitive);

            throw new InferenceException(__('chat.submit_rejected'));
        }

        $id = $response->json('id');

        if (! is_string($id) || ! $this->validJobId($id)) {
            $this->logFailure('submit', $response->status(), $response, $sensitive);

            throw new InferenceException(__('chat.submit_rejected'));
        }

        $status = $response->json('status');

        return new SubmittedJob($id, $status === 'IN_PROGRESS' ? 'IN_PROGRESS' : 'IN_QUEUE');
    }

    public function fetchStatus(string $jobId): JobUpdate
    {
        $endpointId = $this->configuredEndpointId();

        if (! $this->validJobId($jobId)) {
            throw new InferenceException(__('chat.job_lost'));
        }

        try {
            $response = $this->request((int) config('kimi.status_timeout'))
                ->get($this->url($endpointId, 'status/'.$jobId));
        } catch (ConnectionException) {
            $this->logFailure('status', null);

            throw new InferenceException(__('chat.transport_retry'), transient: true);
        }

        if ($response->status() === 429 || $response->serverError()) {
            $this->logFailure('status', $response->status(), $response);

            throw new InferenceException(__('chat.transport_retry'), transient: true);
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->logFailure('status', $response->status(), $response);

            throw new InferenceException(__('chat.bad_key'));
        }

        if (! $response->successful()) {
            $this->logFailure('status', $response->status(), $response);

            throw new InferenceException(__('chat.job_lost'));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->logFailure('status', $response->status(), $response);

            throw new InferenceException(__('chat.unexpected_output'));
        }

        return $this->parseStatus($payload);
    }

    public function cancel(string $jobId): void
    {
        $endpointId = $this->configuredEndpointId();

        if (! $this->validJobId($jobId)) {
            throw new InferenceException(__('chat.cancel_failed'));
        }

        try {
            $response = $this->request((int) config('kimi.status_timeout'))
                ->post($this->url($endpointId, 'cancel/'.$jobId));
        } catch (ConnectionException) {
            $this->logFailure('cancel', null);

            throw new InferenceException(__('chat.cancel_failed'), transient: true);
        }

        if ($response->successful() || $response->status() === 404) {
            return;
        }

        $this->logFailure('cancel', $response->status(), $response);

        throw new InferenceException(
            __('chat.cancel_failed'),
            transient: $response->serverError() || $response->status() === 429,
        );
    }

    public function isConfigured(): bool
    {
        try {
            $this->baseUrl();
        } catch (InferenceException) {
            return false;
        }

        return $this->apiKey() !== '' && $this->endpointId() !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseStatus(array $payload): JobUpdate
    {
        $status = $payload['status'] ?? null;

        if (! is_string($status) || ! in_array($status, ['IN_QUEUE', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
            throw new InferenceException(__('chat.unexpected_output'));
        }

        if (in_array($status, ['IN_QUEUE', 'IN_PROGRESS'], true)) {
            return new JobUpdate($status, finished: false, successful: false);
        }

        $output = $this->normalizeOutput($payload['output'] ?? null);
        $error = $this->extractError($payload, $output);

        if ($error !== null || in_array($status, ['FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
            return new JobUpdate(
                status: $status,
                finished: true,
                successful: false,
                errorMessage: $error['message'] ?? null,
                errorType: $error['type'] ?? null,
            );
        }

        $completion = $this->completionFrom($output);

        if ($completion === null) {
            return new JobUpdate(
                status: $status,
                finished: true,
                successful: false,
                errorMessage: __('chat.empty_reply'),
            );
        }

        return new JobUpdate(
            status: $status,
            finished: true,
            successful: true,
            completion: $completion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeOutput(mixed $output): array
    {
        if ($output === null) {
            return [];
        }

        if (! is_array($output)) {
            throw new InferenceException(__('chat.unexpected_output'));
        }

        if (array_is_list($output)) {
            if (count($output) === 1 && is_array($output[0])) {
                return $output[0];
            }

            throw new InferenceException(__('chat.unexpected_output'));
        }

        return $output;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $output
     * @return array{message: string, type: ?string}|null
     */
    private function extractError(array $payload, array $output): ?array
    {
        foreach ([$payload['error'] ?? null, $output['error'] ?? null] as $error) {
            if (is_string($error) && trim($error) !== '') {
                return ['message' => trim($error), 'type' => null];
            }

            if (! is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;

            if (! is_string($message) || trim($message) === '') {
                continue;
            }

            $type = $error['type'] ?? null;

            return [
                'message' => trim($message),
                'type' => is_string($type) && $type !== '' ? $type : null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function completionFrom(array $output): ?KimiCompletion
    {
        $choice = $output['choices'][0] ?? null;

        if (! is_array($choice)) {
            return null;
        }

        $content = $choice['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $finishReason = $choice['finish_reason'] ?? null;
        $model = $output['model'] ?? null;

        return new KimiCompletion(
            content: $content,
            usage: $this->usage($output),
            model: is_string($model) && $model !== '' ? $model : null,
            finishReason: is_string($finishReason) && $finishReason !== '' ? $finishReason : null,
        );
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, int>|null
     */
    private function usage(array $output): ?array
    {
        $usage = $output['usage'] ?? null;

        if (! is_array($usage)) {
            return null;
        }

        $clean = [];

        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($usage[$key]) && is_numeric($usage[$key])) {
                $clean[$key] = (int) $usage[$key];
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeMessages(array $messages): array
    {
        $clean = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;
            $content = $this->sanitizeContent($role, $message['content'] ?? null);

            if (! in_array($role, ['system', 'user', 'assistant'], true) || $content === null) {
                continue;
            }

            $clean[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $clean;
    }

    private function sanitizeContent(mixed $role, mixed $content): string|array|null
    {
        if (is_string($content)) {
            return $content;
        }

        if ($role !== 'user' || ! is_array($content) || count($content) !== 2 || ! array_is_list($content)) {
            return null;
        }

        $text = $content[0];
        $image = $content[1];

        if (! is_array($text) || ! is_array($image) || count($text) !== 2 || count($image) !== 2) {
            return null;
        }

        if (($text['type'] ?? null) !== 'text' || ! is_string($text['text'] ?? null) || trim($text['text']) === '') {
            return null;
        }

        if (($image['type'] ?? null) !== 'image_url' || ! is_array($image['image_url'] ?? null) || count($image['image_url']) !== 1) {
            return null;
        }

        $url = $image['image_url']['url'] ?? null;

        if (! is_string($url) || preg_match('#\Adata:image/jpeg;base64,[A-Za-z0-9+/]+={0,2}\z#', $url) !== 1) {
            return null;
        }

        if (strlen($url) > 8_000_000) {
            return null;
        }

        return [
            ['type' => 'text', 'text' => $text['text']],
            ['type' => 'image_url', 'image_url' => ['url' => $url]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, string>
     */
    private function sensitiveTexts(array $messages): array
    {
        $texts = [];

        foreach ($messages as $message) {
            $content = $message['content'] ?? null;

            if (is_string($content)) {
                $texts[] = $content;

                continue;
            }

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
        }

        return $texts;
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withToken($this->apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('kimi.connect_timeout'))
            ->timeout($timeout);
    }

    private function url(string $endpointId, string $path): string
    {
        return $this->baseUrl().'/'.$endpointId.'/'.ltrim($path, '/');
    }

    private function configuredEndpointId(): string
    {
        $this->baseUrl();

        $endpointId = $this->endpointId();

        if ($this->apiKey() === '' || $endpointId === null) {
            throw new InferenceException(__('chat.not_configured'));
        }

        return $endpointId;
    }

    private function baseUrl(): string
    {
        $configured = rtrim((string) config('kimi.base_url'), '/');
        $parts = parse_url($configured);

        if (! is_array($parts)) {
            throw new InferenceException(__('chat.bad_endpoint'));
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if ($scheme !== 'https' || strcasecmp($host, self::ALLOWED_HOST) !== 0) {
            throw new InferenceException(__('chat.bad_endpoint'));
        }

        if (! in_array($path, ['', '/v2'], true)) {
            throw new InferenceException(__('chat.bad_endpoint'));
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['port'])) {
            throw new InferenceException(__('chat.bad_endpoint'));
        }

        return 'https://api.runpod.ai/v2';
    }

    private function endpointId(): ?string
    {
        $id = trim((string) config('kimi.endpoint_id'));

        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            return null;
        }

        return $id;
    }

    private function apiKey(): string
    {
        return trim((string) config('kimi.api_key'));
    }

    private function temperature(): float
    {
        return max(0.0, min((float) config('kimi.temperature'), 2.0));
    }

    private function validJobId(string $jobId): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $jobId) === 1;
    }

    /**
     * The response body is not logged. It can repeat the prompt or the API key.
     *
     * @param  array<int, string>  $sensitive
     */
    private function logFailure(string $operation, ?int $status, ?Response $response = null, array $sensitive = []): void
    {
        $remote = $response instanceof Response ? $this->remoteError($response, $sensitive) : ['code' => null, 'message' => null];

        Log::warning('kimi.request_failed', array_filter([
            'operation' => $operation,
            'status' => $status,
            'error_code' => $remote['code'],
            'error_message' => $remote['message'],
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param  array<int, string>  $sensitive
     * @return array{code: ?string, message: ?string}
     */
    private function remoteError(Response $response, array $sensitive): array
    {
        $payload = $response->json();

        if (! is_array($payload) || array_is_list($payload)) {
            return ['code' => null, 'message' => null];
        }

        return [
            'code' => $this->remoteErrorCode($payload),
            'message' => $this->sanitizeLogText($this->remoteErrorMessage($payload), $sensitive),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remoteErrorCode(array $payload): ?string
    {
        $candidates = [
            is_array($payload['error'] ?? null) ? ($payload['error']['code'] ?? null) : null,
            is_array($payload['message'] ?? null) ? ($payload['message']['code'] ?? null) : null,
            $payload['code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function remoteErrorMessage(array $payload): ?string
    {
        $candidates = [
            is_array($payload['message'] ?? null) ? ($payload['message']['detail'] ?? null) : null,
            is_array($payload['message'] ?? null) ? ($payload['message']['message'] ?? null) : null,
            is_string($payload['message'] ?? null) ? $payload['message'] : null,
            is_array($payload['error'] ?? null) ? ($payload['error']['message'] ?? null) : null,
            is_array($payload['error'] ?? null) ? ($payload['error']['detail'] ?? null) : null,
            is_string($payload['error'] ?? null) ? $payload['error'] : null,
            is_string($payload['detail'] ?? null) ? $payload['detail'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $sensitive
     */
    private function sanitizeLogText(?string $message, array $sensitive): ?string
    {
        if ($message === null) {
            return null;
        }

        $clean = trim($message);

        if ($clean === '') {
            return null;
        }

        $redacted = preg_replace('/Bearer\s+\S+/i', '[redacted]', $clean);
        $clean = is_string($redacted) ? $redacted : $clean;
        $redacted = preg_replace('/data:image\\\\?\/[a-z0-9.+-]+;base64,[A-Za-z0-9+\/=\\\\]+/i', '[redacted]', $clean);
        $clean = is_string($redacted) ? $redacted : $clean;

        $secrets = array_filter($sensitive, fn (mixed $value): bool => is_string($value) && $value !== '');
        $key = $this->apiKey();

        if ($key !== '') {
            $secrets[] = $key;
        }

        usort($secrets, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($secrets as $secret) {
            $clean = str_replace($secret, '[redacted]', $clean);
        }

        if ($key !== '' && stripos($clean, $key) !== false) {
            $replaced = str_ireplace($key, '[redacted]', $clean);
            $clean = is_string($replaced) ? $replaced : $clean;
        }

        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $clean);
        $clean = trim(is_string($collapsed) ? $collapsed : $clean);

        if ($clean === '') {
            return null;
        }

        return Str::limit($clean, 300, '');
    }
}
