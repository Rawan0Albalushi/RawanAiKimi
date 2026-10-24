<?php

namespace App\Services;

use App\Exceptions\ImageStoreException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatImageStore
{
    public const SESSION_KEY = 'chat.images_key';

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @return array{id: string, bytes: int, width: int, height: int}
     */
    public function store(UploadedFile $file): array
    {
        $this->assertReadable($file);
        $encoded = $this->encode($file->getPathname());
        $id = (string) Str::uuid();

        Storage::disk('local')->put($this->relativePath($id, create: true), $encoded['bytes']);

        return [
            'id' => $id,
            'bytes' => strlen($encoded['bytes']),
            'width' => $encoded['width'],
            'height' => $encoded['height'],
        ];
    }

    public function read(string $id): string
    {
        $bytes = Storage::disk('local')->get($this->relativePath($id));

        if (! is_string($bytes) || $bytes === '') {
            throw new ImageStoreException(__('chat.image_missing'));
        }

        return $bytes;
    }

    public function dataUrl(string $id): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->read($id));
    }

    public function exists(string $id): bool
    {
        try {
            return Storage::disk('local')->exists($this->relativePath($id));
        } catch (ImageStoreException) {
            return false;
        }
    }

    public function delete(string $id): void
    {
        try {
            Storage::disk('local')->delete($this->relativePath($id));
        } catch (ImageStoreException) {
            return;
        }
    }

    public function deleteSession(): void
    {
        $sessionId = $this->sessionKey();

        if ($sessionId === null) {
            return;
        }

        Storage::disk('local')->deleteDirectory('chat-images/'.$sessionId);
        session()->forget(self::SESSION_KEY);
    }

    public function prune(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes((int) config('kimi.image_retention_minutes'))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('chat-images') as $path) {
            if (! str_ends_with(strtolower($path), '.jpg')) {
                continue;
            }

            if ($disk->lastModified($path) > $cutoff) {
                continue;
            }

            $disk->delete($path);
            $deleted++;
        }

        foreach ($disk->directories('chat-images') as $directory) {
            if ($disk->allFiles($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }

        return $deleted;
    }

    private function assertReadable(UploadedFile $file): void
    {
        $path = $file->getPathname();
        $maxBytes = (int) config('kimi.image_max_kilobytes') * 1024;

        if (! is_file($path) || filesize($path) === false || filesize($path) > $maxBytes) {
            throw new ImageStoreException(__('chat.image_too_large'));
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        $detected = is_array($info) ? ($info['mime'] ?? null) : null;

        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIME_TYPES, true) || $mime !== $detected) {
            throw new ImageStoreException(__('chat.image_invalid'));
        }

        $width = $info[0] ?? 0;
        $height = $info[1] ?? 0;
        $edge = (int) config('kimi.image_max_source_edge');

        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1 || $width > $edge || $height > $edge) {
            throw new ImageStoreException(__('chat.image_too_large'));
        }
    }

    /**
     * @return array{bytes: string, width: int, height: int}
     */
    private function encode(string $path): array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw new ImageStoreException(__('chat.image_unreadable'));
        }

        $sourceBytes = file_get_contents($path);

        if (! is_string($sourceBytes) || $sourceBytes === '') {
            throw new ImageStoreException(__('chat.image_unreadable'));
        }

        $source = @imagecreatefromstring($sourceBytes);

        if ($source === false) {
            throw new ImageStoreException(__('chat.image_unreadable'));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $maxEdge = max(1, (int) config('kimi.image_max_edge'));
        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            imagedestroy($source);

            throw new ImageStoreException(__('chat.image_unreadable'));
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imageinterlace($canvas, false);
        imagedestroy($source);

        ob_start();
        $written = imagejpeg($canvas, null, (int) config('kimi.image_jpeg_quality'));
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        if ($written !== true || ! is_string($jpeg) || $jpeg === '') {
            throw new ImageStoreException(__('chat.image_unreadable'));
        }

        return [
            'bytes' => $jpeg,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    private function relativePath(string $id, bool $create = false): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            throw new ImageStoreException(__('chat.image_invalid'));
        }

        $sessionId = $create ? $this->requireSessionKey() : $this->sessionKey();

        if ($sessionId === null) {
            throw new ImageStoreException(__('chat.image_missing'));
        }

        return 'chat-images/'.$sessionId.'/'.$id.'.jpg';
    }

    private function requireSessionKey(): string
    {
        return $this->sessionKey(create: true) ?? throw new ImageStoreException(__('chat.image_invalid'));
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
