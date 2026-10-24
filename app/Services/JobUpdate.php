<?php

namespace App\Services;

final readonly class JobUpdate
{
    public function __construct(
        public string $status,
        public bool $finished,
        public bool $successful,
        public ?KimiCompletion $completion = null,
        public ?string $errorMessage = null,
        public ?string $errorType = null,
    ) {}
}
