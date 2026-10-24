<?php

namespace App\Services;

final readonly class SubmittedJob
{
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}
