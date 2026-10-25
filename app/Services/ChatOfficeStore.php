<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Services\Documents\ExtractedDocument;
use App\Services\Documents\OfficeTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

class ChatOfficeStore
{
    private const OWNER = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly OfficeTextExtractor $extractor) {}

    /**
     * @return array{id: string, name: string, bytes: int, pages: int, kind: string}
     */
    public function store(UploadedFile $file, string $ownerId): array
    {
        $extension = $this->assertReadable($file);
        $bytes = file_get_contents($file->getPathname());

        if (! is_string($bytes) || $bytes === '') {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $id = (string) Str::uuid();
        $name = $this->displayName($file, $extension);
        $binaryPath = $this->relativePath($ownerId, $id, $extension);
        $jsonPath = $this->relativePath($ownerId, $id, 'json');
        Storage::disk('local')->put($binaryPath, $bytes);

        try {
            $extracted = $this->extractor->extract(Storage::disk('local')->path($binaryPath));

            if ($extracted['kind'] !== $extension) {
                throw new DocumentException(__('chat.office_invalid'));
            }

            $payload = [
                'name' => $name,
                'bytes' => strlen($bytes),
                'page_count' => $extracted['page_count'],
                'pages' => $extracted['pages'],
                'empty_pages' => $extracted['empty_pages'],
                'unit' => $extracted['unit'],
                'kind' => $extracted['kind'],
            ];
            Storage::disk('local')->put($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (DocumentException $exception) {
            $this->delete($ownerId, $id);

            throw $exception;
        } catch (JsonException) {
            $this->delete($ownerId, $id);

            throw new DocumentException(__('chat.office_corrupted'));
        }

        return [
            'id' => $id,
            'name' => $name,
            'bytes' => strlen($bytes),
            'pages' => $extracted['page_count'],
            'kind' => $extracted['kind'],
        ];
    }

    public function read(string $ownerId, string $id): ExtractedDocument
    {
        try {
            $raw = Storage::disk('local')->get($this->relativePath($ownerId, $id, 'json'));
        } catch (DocumentException) {
            throw new DocumentException(__('chat.office_missing'));
        }

        if (! is_string($raw) || $raw === '') {
            throw new DocumentException(__('chat.office_missing'));
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        if (! is_array($payload)) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $name = $payload['name'] ?? null;
        $bytes = $payload['bytes'] ?? null;
        $pageCount = $payload['page_count'] ?? null;
        $pages = $payload['pages'] ?? null;
        $emptyPages = $payload['empty_pages'] ?? null;
        $unit = $payload['unit'] ?? null;

        if (! is_string($name) || $name === '' || ! is_int($bytes) || $bytes < 1 || ! is_int($pageCount) || $pageCount < 1) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        if (! in_array($unit, ['section', 'sheet'], true) || ! is_array($pages) || $pages === [] || ! is_array($emptyPages)) {
            throw new DocumentException(__('chat.office_corrupted'));
        }

        $cleanPages = [];

        foreach ($pages as $number => $text) {
            if (! is_string($text) || trim($text) === '' || ! is_numeric($number) || (int) $number < 1) {
                throw new DocumentException(__('chat.office_corrupted'));
            }

            $cleanPages[(int) $number] = $text;
        }

        ksort($cleanPages);
        $cleanEmpty = [];

        foreach ($emptyPages as $number) {
            if (! is_int($number) || $number < 1) {
                throw new DocumentException(__('chat.office_corrupted'));
            }

            $cleanEmpty[] = $number;
        }

        return new ExtractedDocument($id, $name, $bytes, $pageCount, $cleanPages, $cleanEmpty, $unit);
    }

    public function exists(string $ownerId, string $id): bool
    {
        try {
            return Storage::disk('local')->exists($this->relativePath($ownerId, $id, 'json'));
        } catch (DocumentException) {
            return false;
        }
    }

    public function delete(string $ownerId, string $id): void
    {
        try {
            Storage::disk('local')->delete([
                $this->relativePath($ownerId, $id, 'docx'),
                $this->relativePath($ownerId, $id, 'xlsx'),
                $this->relativePath($ownerId, $id, 'json'),
            ]);
        } catch (DocumentException) {
            return;
        }
    }

    public function deleteOwner(string $ownerId): void
    {
        if (preg_match(self::OWNER, $ownerId) !== 1) {
            return;
        }

        Storage::disk('local')->deleteDirectory('chat-office/'.$ownerId);
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('kimi.document_retention_minutes'))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('chat-office') as $path) {
            $lower = strtolower($path);

            if ((! str_ends_with($lower, '.docx') && ! str_ends_with($lower, '.xlsx')) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            $disk->delete($path);
            $json = substr($path, 0, -5).'.json';

            if ($disk->exists($json)) {
                $disk->delete($json);
            }

            $deleted++;
        }

        foreach ($disk->allFiles('chat-office') as $path) {
            if (str_ends_with(strtolower($path), '.json') && $disk->lastModified($path) <= $cutoff) {
                $disk->delete($path);
            }
        }

        foreach ($disk->directories('chat-office') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    private function assertReadable(UploadedFile $file): string
    {
        $path = $file->getPathname();
        $maxBytes = (int) config('kimi.document_max_kilobytes') * 1024;
        $size = is_file($path) ? filesize($path) : false;
        $extension = $this->extension($file->getClientOriginalName());

        if (! is_file($path) || $size === false || $size < 22 || $extension === null) {
            throw new DocumentException(__('chat.office_invalid'));
        }

        if ($size > $maxBytes) {
            throw new DocumentException(__('chat.office_too_large', [
                'limit' => max(1, (int) round(((int) config('kimi.document_max_kilobytes')) / 1024)),
            ]));
        }

        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);

        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($magic !== "PK\x03\x04") {
            throw new DocumentException(__('chat.office_invalid'));
        }

        return $extension;
    }

    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.'.$extension;
        }

        if (! str_ends_with(Str::lower($name), '.'.$extension)) {
            $name .= '.'.$extension;
        }

        return Str::limit($name, 120, '');
    }

    private function extension(string $name): ?string
    {
        $name = strtolower(basename(str_replace('\\', '/', $name)));

        foreach (['docx', 'xlsx'] as $extension) {
            if (str_ends_with($name, '.'.$extension)) {
                return $extension;
            }
        }

        return null;
    }

    private function relativePath(string $ownerId, string $id, string $extension): string
    {
        if (preg_match(self::OWNER, $ownerId) !== 1 || preg_match(self::OWNER, $id) !== 1) {
            throw new DocumentException(__('chat.office_invalid'));
        }

        if (! in_array($extension, ['docx', 'xlsx', 'json'], true)) {
            throw new DocumentException(__('chat.office_invalid'));
        }

        return 'chat-office/'.$ownerId.'/'.$id.'.'.$extension;
    }
}
