<?php

namespace App\Services;

final readonly class KimiCompletion
{
    /**
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public string $content,
        public ?array $usage = null,
        public ?string $model = null,
        public ?string $finishReason = null,
    ) {}
}
