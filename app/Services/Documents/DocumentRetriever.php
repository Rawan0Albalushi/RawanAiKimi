<?php

namespace App\Services\Documents;

class DocumentRetriever
{
    /**
     * @var array<string, true>
     */
    private array $stop;

    public function __construct()
    {
        $words = [
            'the', 'and', 'for', 'that', 'with', 'this', 'from', 'are', 'was', 'were',
            'you', 'your', 'about', 'what', 'when', 'where', 'how', 'why', 'can',
            'could', 'would', 'should', 'into', 'over', 'than', 'then', 'them', 'they',
            'have', 'has', 'had', 'not', 'but', 'its', 'our', 'out', 'all', 'any',
            'which', 'who', 'have', 'will', 'just', 'pdf', 'document', 'page', 'pages',
            'في', 'من', 'على', 'إلى', 'الى', 'عن', 'هذا', 'هذه', 'ذلك', 'تلك', 'هل',
            'ما', 'ماذا', 'كيف', 'لماذا', 'مع', 'أو', 'او', 'ثم', 'قد', 'لم', 'لن',
            'إن', 'ان', 'أن', 'كان', 'كانت', 'هو', 'هي', 'هم', 'كما', 'بعد', 'قبل',
            'عند', 'حتى', 'كل', 'أي', 'الى', 'التي', 'الذي', 'هذه', 'هناك',
        ];

        $this->stop = array_fill_keys($words, true);
    }

    /**
     * Highest score first. A score of zero means the chunk shares no terms
     * with the question.
     *
     * @param  array<int, DocumentChunk>  $chunks
     * @return array<int, array{chunk: DocumentChunk, score: float}>
     */
    public function rank(array $chunks, string $question): array
    {
        $terms = $this->terms($question);
        $ranked = [];

        foreach ($chunks as $chunk) {
            $ranked[] = [
                'chunk' => $chunk,
                'score' => $this->score($terms, $chunk->text),
            ];
        }

        usort($ranked, function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];

            return $score !== 0 ? $score : $left['chunk']->id <=> $right['chunk']->id;
        });

        return $ranked;
    }

    /**
     * @param  array<int, string>  $questionTerms
     */
    private function score(array $questionTerms, string $text): float
    {
        if ($questionTerms === []) {
            return 0.0;
        }

        $chunkTerms = $this->terms($text);

        if ($chunkTerms === []) {
            return 0.0;
        }

        $overlap = array_intersect($questionTerms, $chunkTerms);
        $score = count($overlap) / count($questionTerms);
        $pairs = 0;
        $hits = 0;
        $joined = ' '.implode(' ', $chunkTerms).' ';

        for ($index = 0; $index < count($questionTerms) - 1; $index++) {
            $pairs++;
            $phrase = ' '.$questionTerms[$index].' '.$questionTerms[$index + 1].' ';

            if (str_contains($joined, $phrase)) {
                $hits++;
            }
        }

        if ($pairs > 0) {
            $score += 0.15 * ($hits / $pairs);
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $text): array
    {
        $text = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($parts)) {
            return [];
        }

        $terms = [];

        foreach ($parts as $part) {
            $term = $this->normalize($part);

            if (mb_strlen($term) < 2 || isset($this->stop[$term]) || in_array($term, $terms, true)) {
                continue;
            }

            $terms[] = $term;
        }

        return $terms;
    }

    private function normalize(string $term): string
    {
        if (str_starts_with($term, 'و') && mb_strlen($term) > 3) {
            $term = mb_substr($term, 1);
        }

        if (str_starts_with($term, 'ال') && mb_strlen($term) > 4) {
            return mb_substr($term, 2);
        }

        return $term;
    }
}
