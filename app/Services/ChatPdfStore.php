<?php

namespace App\Services;

use App\Exceptions\DocumentException;
use App\Services\Documents\ExtractedDocument;
use App\Services\Documents\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;

class ChatPdfStore
{
    public const SESSION_KEY = 'chat.pdfs_key';

    public function __construct(private readonly PdfTextExtractor $extractor) {}

    /**
     * @return array{id: string, name: string, bytes: int, pages: int}
     */
    public function store(UploadedFile $file): array
    {
        $this->assertReadable($file);
        $bytes = file_get_contents($file->getPathname());

        if (! is_string($bytes) || $bytes === '') {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        $id = (string) Str::uuid();
        $name = $this->displayName($file);
        $pdfPath = $this->relativePath($id, 'pdf', create: true);
        $jsonPath = $this->relativePath($id, 'json');
        Storage::disk('local')->put($pdfPath, $bytes);

        try {
            $extracted = $this->extractor->extract(Storage::disk('local')->path($pdfPath));
            $payload = [
                'name' => $name,
                'bytes' => strlen($bytes),
                'page_count' => $extracted['page_count'],
                'pages' => $extracted['pages'],
                'empty_pages' => $extracted['empty_pages'],
            ];
            Storage::disk('local')->put($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (DocumentException $exception) {
            $this->delete($id);

            throw $exception;
        } catch (JsonException) {
            $this->delete($id);

            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        return [
            'id' => $id,
            'name' => $name,
            'bytes' => strlen($bytes),
            'pages' => $extracted['page_count'],
        ];
    }

    public function read(string $id): ExtractedDocument
    {
        try {
            $raw = Storage::disk('local')->get($this->relativePath($id, 'json'));
        } catch (DocumentException) {
            throw new DocumentException(__('chat.pdf_missing'));
        }

        if (! is_string($raw) || $raw === '') {
            throw new DocumentException(__('chat.pdf_missing'));
        }

        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        if (! is_array($payload)) {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        $name = $payload['name'] ?? null;
        $bytes = $payload['bytes'] ?? null;
        $pageCount = $payload['page_count'] ?? null;
        $pages = $payload['pages'] ?? null;
        $emptyPages = $payload['empty_pages'] ?? null;

        if (! is_string($name) || $name === '' || ! is_int($bytes) || $bytes < 1 || ! is_int($pageCount) || $pageCount < 1) {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        if (! is_array($pages) || $pages === [] || ! is_array($emptyPages)) {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        $cleanPages = [];

        foreach ($pages as $number => $text) {
            if (! is_string($text) || trim($text) === '' || ! is_numeric($number) || (int) $number < 1) {
                throw new DocumentException(__('chat.pdf_corrupted'));
            }

            $cleanPages[(int) $number] = $text;
        }

        ksort($cleanPages);
        $cleanEmpty = [];

        foreach ($emptyPages as $number) {
            if (! is_int($number) || $number < 1) {
                throw new DocumentException(__('chat.pdf_corrupted'));
            }

            $cleanEmpty[] = $number;
        }

        return new ExtractedDocument($id, $name, $bytes, $pageCount, $cleanPages, $cleanEmpty);
    }

    public function exists(string $id): bool
    {
        try {
            return Storage::disk('local')->exists($this->relativePath($id, 'pdf'));
        } catch (DocumentException) {
            return false;
        }
    }

    public function delete(string $id): void
    {
        try {
            Storage::disk('local')->delete([
                $this->relativePath($id, 'pdf'),
                $this->relativePath($id, 'json'),
            ]);
        } catch (DocumentException) {
            return;
        }
    }

    public function deleteSession(): void
    {
        $sessionId = $this->sessionKey();

        if ($sessionId === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory('chat-pdfs/'.$sessionId);
        session()->forget(self::SESSION_KEY);
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('kimi.document_retention_minutes'))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('chat-pdfs') as $path) {
            if (! str_ends_with(strtolower($path), '.pdf') || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            $disk->delete($path);
            $json = substr($path, 0, -4).'.json';

            if ($disk->exists($json)) {
                $disk->delete($json);
            }

            $deleted++;
        }

        foreach ($disk->allFiles('chat-pdfs') as $path) {
            if (str_ends_with(strtolower($path), '.json') && $disk->lastModified($path) <= $cutoff) {
                $disk->delete($path);
            }
        }

        foreach ($disk->directories('chat-pdfs') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    private function assertReadable(UploadedFile $file): void
    {
        $path = $file->getPathname();
        $maxBytes = (int) config('kimi.document_max_kilobytes') * 1024;
        $size = is_file($path) ? filesize($path) : false;

        if (! is_file($path) || $size === false || $size < 5) {
            throw new DocumentException(__('chat.pdf_invalid'));
        }

        if ($size > $maxBytes) {
            throw new DocumentException(__('chat.pdf_too_large', [
                'limit' => max(1, (int) round(((int) config('kimi.document_max_kilobytes')) / 1024)),
            ]));
        }

        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 5);

        if (is_resource($handle)) {
            fclose($handle);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($magic !== '%PDF-' || ! in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
            throw new DocumentException(__('chat.pdf_invalid'));
        }
    }

    private function displayName(UploadedFile $file): string
    {
        $name = str_replace('\\', '/', $file->getClientOriginalName());
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'document.pdf';
        }

        if (! str_ends_with(Str::lower($name), '.pdf')) {
            $name .= '.pdf';
        }

        return Str::limit($name, 120, '');
    }

    private function relativePath(string $id, string $extension, bool $create = false): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new DocumentException(__('chat.pdf_invalid'));
        }

        if (! in_array($extension, ['pdf', 'json'], true)) {
            throw new DocumentException(__('chat.pdf_invalid'));
        }

        $sessionId = $create ? $this->requireSessionKey() : $this->sessionKey();

        if ($sessionId === null) {
            throw new DocumentException(__('chat.pdf_missing'));
        }

        return 'chat-pdfs/'.$sessionId.'/'.$id.'.'.$extension;
    }

    private function requireSessionKey(): string
    {
        return $this->sessionKey(create: true) ?? throw new DocumentException(__('chat.pdf_invalid'));
    }

    private function sessionKey(bool $create = false): ?string
    {
        $existing = session()->get(self::SESSION_KEY);

        if (is_string($existing) && preg_match('/^[A-Za-z0-9]{32}$/', $existing) === 1) {
            return $existing;
        }

        if (! $create) {
            return null;
        }

        $key = Str::random(32);
        session()->put(self::SESSION_KEY, $key);

        return $key;
    }
}
