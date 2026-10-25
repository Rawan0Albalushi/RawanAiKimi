<?php

namespace App\Services\Documents;

final class DocumentPlan
{
    /**
     * @param  array<int, array<string, mixed>>  $stages
     * @param  array<int, int>  $coveredPages
     * @param  array<int, int>  $uncoveredPages
     * @param  array<int, int>  $emptyPages
     * @param  array<int, array{pages: array<int, int>, text: string}>  $partials
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $mode,
        public readonly string $question,
        public readonly string $language,
        public readonly int $chunkTokens,
        public readonly array $stages,
        public readonly array $coveredPages,
        public readonly array $uncoveredPages,
        public readonly array $emptyPages,
        public readonly ?string $localReply = null,
        public readonly int $cursor = 0,
        public readonly array $partials = [],
        public readonly string $source = 'pdf',
        public readonly string $unit = 'page',
    ) {}

    /**
     * @param  array<int, int>  $pages
     */
    public function withPartial(string $text, array $pages): self
    {
        $partials = $this->partials;
        $partials[] = [
            'pages' => array_values($pages),
            'text' => $text,
        ];

        return new self(
            documentId: $this->documentId,
            mode: $this->mode,
            question: $this->question,
            language: $this->language,
            chunkTokens: $this->chunkTokens,
            stages: $this->stages,
            coveredPages: $this->coveredPages,
            uncoveredPages: $this->uncoveredPages,
            emptyPages: $this->emptyPages,
            cursor: $this->cursor + 1,
            partials: $partials,
            source: $this->source,
            unit: $this->unit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSession(): array
    {
        return [
            'document_id' => $this->documentId,
            'mode' => $this->mode,
            'question' => $this->question,
            'language' => $this->language,
            'chunk_tokens' => $this->chunkTokens,
            'stages' => $this->stages,
            'covered_pages' => $this->coveredPages,
            'uncovered_pages' => $this->uncoveredPages,
            'empty_pages' => $this->emptyPages,
            'cursor' => $this->cursor,
            'partials' => $this->partials,
            'source' => $this->source,
            'unit' => $this->unit,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromSession(array $payload): ?self
    {
        $id = $payload['document_id'] ?? null;
        $mode = $payload['mode'] ?? null;
        $question = $payload['question'] ?? null;
        $language = $payload['language'] ?? null;
        $chunkTokens = $payload['chunk_tokens'] ?? null;
        $stages = $payload['stages'] ?? null;
        $cursor = $payload['cursor'] ?? null;

        if (! is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            return null;
        }

        if (! in_array($mode, ['summarize', 'question'], true) || ! is_string($question) || mb_strlen($question) > 4000) {
            return null;
        }

        if (! in_array($language, ['en', 'ar'], true) || ! is_int($chunkTokens) || $chunkTokens < 16 || $chunkTokens > 2000) {
            return null;
        }

        if (! is_array($stages) || $stages === [] || count($stages) > 24 || ! is_int($cursor) || $cursor < 0 || $cursor > count($stages)) {
            return null;
        }

        $cleanStages = [];

        foreach ($stages as $stage) {
            if (! is_array($stage) || ! in_array($stage['type'] ?? null, ['answer', 'summary', 'map', 'reduce'], true)) {
                return null;
            }

            $clean = ['type' => $stage['type']];

            if ($stage['type'] === 'reduce') {
                $indexes = self::intList($stage['partial_indexes'] ?? null, 24);

                if ($indexes === null) {
                    return null;
                }

                $clean['partial_indexes'] = $indexes;
            } else {
                $ids = self::intList($stage['chunk_ids'] ?? null, 80);

                if ($ids === null || $ids === []) {
                    return null;
                }

                $clean['chunk_ids'] = $ids;
            }

            $cleanStages[] = $clean;
        }

        $source = $payload['source'] ?? 'pdf';
        $unit = $payload['unit'] ?? 'page';

        if (! in_array($source, ['pdf', 'office'], true) || ! in_array($unit, ['page', 'section', 'sheet'], true)) {
            return null;
        }

        $covered = self::intList($payload['covered_pages'] ?? null, 500);
        $uncovered = self::intList($payload['uncovered_pages'] ?? null, 500);
        $empty = self::intList($payload['empty_pages'] ?? null, 500);
        $partials = self::partials($payload['partials'] ?? null);

        if ($covered === null || $uncovered === null || $empty === null || $partials === null) {
            return null;
        }

        return new self(
            documentId: $id,
            mode: $mode,
            question: $question,
            language: $language,
            chunkTokens: $chunkTokens,
            stages: $cleanStages,
            coveredPages: $covered,
            uncoveredPages: $uncovered,
            emptyPages: $empty,
            cursor: $cursor,
            partials: $partials,
            source: $source,
            unit: $unit,
        );
    }

    /**
     * @return array<int, int>|null
     */
    private static function intList(mixed $values, int $max): ?array
    {
        if (! is_array($values) || count($values) > $max) {
            return null;
        }

        $clean = [];

        foreach ($values as $value) {
            if (! is_int($value) || $value < 0 || $value > 10000) {
                return null;
            }

            $clean[] = $value;
        }

        return $clean;
    }

    /**
     * @return array<int, array{pages: array<int, int>, text: string}>|null
     */
    private static function partials(mixed $partials): ?array
    {
        if (! is_array($partials) || count($partials) > 24) {
            return null;
        }

        $clean = [];

        foreach ($partials as $partial) {
            if (! is_array($partial) || ! is_string($partial['text'] ?? null) || mb_strlen($partial['text']) > 8000) {
                return null;
            }

            $pages = self::intList($partial['pages'] ?? null, 500);

            if ($pages === null) {
                return null;
            }

            $clean[] = [
                'pages' => $pages,
                'text' => $partial['text'],
            ];
        }

        return $clean;
    }
}
