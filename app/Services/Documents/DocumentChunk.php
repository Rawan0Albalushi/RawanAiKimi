<?php

namespace App\Services\Documents;

final class DocumentChunk
{
    public function __construct(
        public readonly int $id,
        public readonly int $page,
        public readonly string $text,
    ) {}
}
