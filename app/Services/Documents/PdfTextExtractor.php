<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentException;
use Smalot\PdfParser\Parser;
use Throwable;

class PdfTextExtractor
{
    public function __construct(private readonly Parser $parser) {}

    /**
     * @return array{page_count: int, pages: array<int, string>, empty_pages: array<int, int>}
     */
    public function extract(string $path): array
    {
        if (! is_file($path)) {
            throw new DocumentException(__('chat.pdf_corrupted'));
        }

        try {
            $document = $this->parser->parseFile($path);
            $parsedPages = $document->getPages();
        } catch (Throwable $exception) {
            throw new DocumentException($this->failureMessage($exception));
        }

        $pageCount = count($parsedPages);

        if ($pageCount < 1) {
            throw new DocumentException(__('chat.pdf_empty'));
        }

        $limit = (int) config('kimi.document_max_pages');

        if ($pageCount > $limit) {
            throw new DocumentException(__('chat.pdf_too_many_pages', ['limit' => $limit]));
        }

        $pages = [];
        $emptyPages = [];
        $characters = 0;
        $characterLimit = (int) config('kimi.document_max_characters');

        foreach ($parsedPages as $index => $page) {
            $number = $index + 1;

            try {
                $text = $this->clean($page->getText());
            } catch (Throwable) {
                throw new DocumentException(__('chat.pdf_corrupted'));
            }

            if (! $this->hasLetter($text)) {
                $emptyPages[] = $number;

                continue;
            }

            $characters += mb_strlen($text);

            if ($characters > $characterLimit) {
                throw new DocumentException(__('chat.pdf_too_much_text', ['limit' => $characterLimit]));
            }

            $pages[$number] = $text;
        }

        if ($pages === []) {
            throw new DocumentException(__('chat.pdf_ocr_required'));
        }

        return [
            'page_count' => $pageCount,
            'pages' => $pages,
            'empty_pages' => $emptyPages,
        ];
    }

    private function failureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Secured pdf') || str_contains($message, 'Possible secured')) {
            return __('chat.pdf_encrypted');
        }

        return __('chat.pdf_corrupted');
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f", "\0"], ["\n", "\n", "\n", ''], $text);
        $text = preg_replace("/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u", '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function hasLetter(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1;
    }
}
