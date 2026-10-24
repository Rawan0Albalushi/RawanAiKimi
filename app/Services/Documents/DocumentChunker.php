<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentException;

class DocumentChunker
{
    public function __construct(private readonly TokenEstimator $tokens) {}

    /**
     * @return array<int, DocumentChunk>
     */
    public function chunk(ExtractedDocument $document, int $maxTokens): array
    {
        $chunks = [];

        foreach ($document->pages as $page => $text) {
            foreach ($this->split((string) $text, $maxTokens) as $part) {
                $chunks[] = new DocumentChunk(count($chunks), (int) $page, $part);
            }
        }

        $limit = (int) config('kimi.document_max_chunks');

        if (count($chunks) > $limit) {
            throw new DocumentException(__('chat.pdf_too_many_chunks', ['limit' => $limit]));
        }

        if ($chunks === []) {
            throw new DocumentException(__('chat.pdf_ocr_required'));
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $text, int $maxTokens): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if ($this->tokens->estimate($text) <= $maxTokens) {
            return [$text];
        }

        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [$text];

        return $this->pack($paragraphs, $maxTokens);
    }

    /**
     * @param  array<int, string>  $pieces
     * @return array<int, string>
     */
    private function pack(array $pieces, int $maxTokens): array
    {
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $piece = trim($piece);

            if ($piece === '') {
                continue;
            }

            if ($this->tokens->estimate($piece) > $maxTokens) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                array_push($chunks, ...$this->splitHard($piece, $maxTokens));

                continue;
            }

            $candidate = $current === '' ? $piece : $current."\n\n".$piece;

            if ($this->tokens->estimate($candidate) <= $maxTokens) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $current = $piece;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function splitHard(string $text, int $maxTokens): array
    {
        $sentences = preg_split('/(?<=[\.\!\?؟])\s+/u', $text) ?: [$text];

        if (is_array($sentences) && count($sentences) > 1) {
            $packed = $this->pack($sentences, $maxTokens);
            $fits = true;

            foreach ($packed as $part) {
                if ($this->tokens->estimate($part) > $maxTokens) {
                    $fits = false;
                    break;
                }
            }

            if ($fits) {
                return $packed;
            }
        }

        $result = [];
        $remaining = $text;

        while ($remaining !== '') {
            if ($this->tokens->estimate($remaining) <= $maxTokens) {
                $result[] = $remaining;
                break;
            }

            $best = $this->largestFit($remaining, $maxTokens);

            if ($best < 1) {
                throw new DocumentException(__('chat.pdf_context_too_small'));
            }

            $result[] = trim(mb_substr($remaining, 0, $best));
            $remaining = trim(mb_substr($remaining, $best));
        }

        return array_values(array_filter($result, fn (string $part): bool => $part !== ''));
    }

    private function largestFit(string $text, int $maxTokens): int
    {
        $low = 1;
        $high = mb_strlen($text) - 1;
        $best = 0;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $slice = mb_substr($text, 0, $mid);

            if ($this->tokens->estimate($slice) <= $maxTokens) {
                $best = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return $best;
    }
}
