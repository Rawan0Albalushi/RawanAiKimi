<?php

namespace App\Services\Documents;

final class ExtractedDocument
{
    /**
     * @param  array<int, string>  $pages
     * @param  array<int, int>  $emptyPages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $bytes,
        public readonly int $pageCount,
        public readonly array $pages,
        public readonly array $emptyPages,
        public readonly string $unit = 'page',
    ) {}
}
