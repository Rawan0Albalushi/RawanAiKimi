<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

class ChatStore
{
    public const ACTIVE = 'chat.active_id';

    private const ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function validId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    public function activeId(): ?string
    {
        $id = session()->get(self::ACTIVE);

        if (! is_string($id) || ! $this->validId($id)) {
            return null;
        }

        return $id;
    }

    public function ensureActive(): string
    {
        return $this->activeId() ?? $this->startNew();
    }

    public function startNew(): string
    {
        $current = $this->activeId();

        if ($current !== null && $this->messages($current) === []) {
            return $current;
        }

        $id = (string) Str::uuid();
        session()->put(self::ACTIVE, $id);

        return $id;
    }

    public function open(string $id): bool
    {
        if (! $this->exists($id)) {
            return false;
        }

        session()->put(self::ACTIVE, $id);

        return true;
    }

    public function exists(string $id): bool
    {
        return $this->validId($id) && Storage::disk('local')->exists($this->path($id));
    }

    public function delete(string $id): void
    {
        if (! $this->validId($id)) {
            return;
        }

        Storage::disk('local')->delete($this->path($id));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(string $id): array
    {
        $record = $this->read($id);

        return $record === null ? [] : $record['messages'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function putMessages(string $id, array $messages): string
    {
        if (! $this->validId($id)) {
            $id = (string) Str::uuid();
        }

        $existing = $this->read($id);
        $messages = $this->cleanMessages($messages);
        $now = now()->toIso8601String();
        $payload = [
            'id' => $id,
            'title' => $this->titleFrom($messages),
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
            'messages' => $messages,
        ];

        $written = Storage::disk('local')->put(
            $this->path($id),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        if ($written !== true) {
            throw new JsonException('The chat could not be saved.');
        }

        return $id;
    }

    /**
     * @return array<int, array{id: string, title: string, updatedAt: string}>
     */
    public function list(): array
    {
        $disk = Storage::disk('local');
        $items = [];

        if (! $disk->directoryExists('chats')) {
            return [];
        }

        foreach ($disk->files('chats') as $path) {
            $name = basename($path);

            if (preg_match('/^([0-9a-f-]{36})\.json$/', $name, $matches) !== 1 || ! $this->validId($matches[1])) {
                continue;
            }

            $record = $this->read($matches[1]);

            if ($record === null || $record['messages'] === []) {
                continue;
            }

            $items[] = [
                'id' => $record['id'],
                'title' => $record['title'] !== '' ? $record['title'] : __('chat.new_chat'),
                'updatedAt' => $record['updated_at'],
            ];
        }

        usort($items, fn (array $left, array $right): int => strcmp($right['updatedAt'], $left['updatedAt']));

        return $items;
    }

    /**
     * @return array{id: string, title: string, created_at: string, updated_at: string, messages: array<int, array<string, mixed>>}|null
     */
    private function read(string $id): ?array
    {
        if (! $this->validId($id)) {
            return null;
        }

        $raw = Storage::disk('local')->get($this->path($id));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['id'] ?? null) !== $id) {
            return null;
        }

        $messages = $this->cleanMessages(is_array($payload['messages'] ?? null) ? $payload['messages'] : []);
        $created = is_string($payload['created_at'] ?? null) ? $payload['created_at'] : now()->toIso8601String();
        $updated = is_string($payload['updated_at'] ?? null) ? $payload['updated_at'] : $created;

        return [
            'id' => $id,
            'title' => $this->titleFrom($messages),
            'created_at' => $created,
            'updated_at' => $updated,
            'messages' => $messages,
        ];
    }

    private function path(string $id): string
    {
        return 'chats/'.$id.'.json';
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function cleanMessages(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['role'] ?? null, ['user', 'assistant'], true) || ! is_string($row['content'] ?? null)) {
                continue;
            }

            $clean[] = $row;
        }

        return array_values($clean);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function titleFrom(array $messages): string
    {
        foreach ($messages as $turn) {
            if (($turn['role'] ?? null) !== 'user' || ! is_string($turn['content'] ?? null)) {
                continue;
            }

            $text = preg_replace('/\s+/u', ' ', trim($turn['content']));
            $text = is_string($text) ? trim($text) : '';

            if ($text === '') {
                continue;
            }

            return mb_substr($text, 0, 80);
        }

        return '';
    }
}
