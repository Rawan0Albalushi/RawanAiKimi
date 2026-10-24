<?php

namespace Tests\Unit;

use App\Exceptions\DocumentException;
use App\Services\Documents\DocumentChunk;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentRetriever;
use App\Services\Documents\DocumentUnderstandingService;
use App\Services\Documents\ExtractedDocument;
use App\Services\Documents\PdfTextExtractor;
use App\Services\Documents\TokenEstimator;
use Smalot\PdfParser\Parser;
use Tests\Support\PdfFixture;
use Tests\TestCase;

class PdfDocumentTest extends TestCase
{
    public function test_english_and_arabic_pages_keep_their_numbers(): void
    {
        $extractor = $this->extractor();
        $english = $this->write(PdfFixture::text([
            'Revenue grew in Muscat.',
            'The second page lists costs.',
        ]));
        $arabic = $this->write(PdfFixture::arabic('إيرادات مسقط'));

        $englishPages = $extractor->extract($english);
        $arabicPages = $extractor->extract($arabic);

        $this->assertSame([
            1 => 'Revenue grew in Muscat.',
            2 => 'The second page lists costs.',
        ], $englishPages['pages']);
        $this->assertSame([], $englishPages['empty_pages']);
        $this->assertSame([1 => 'إيرادات مسقط'], $arabicPages['pages']);
    }

    public function test_encrypted_empty_scanned_and_corrupt_pdfs_are_rejected(): void
    {
        $extractor = $this->extractor();

        $this->expectFailure($extractor, PdfFixture::encrypted(), 'This PDF is encrypted.');
        $this->expectFailure($extractor, PdfFixture::emptyDocument(), 'That PDF has no pages.');
        $this->expectFailure($extractor, PdfFixture::blankPage(), 'no extractable text');
        $this->expectFailure($extractor, "%PDF-1.4\nnot a document\n%%EOF\n", 'could not be read');
    }

    public function test_a_long_page_is_split_without_losing_its_page_number(): void
    {
        $chunker = new DocumentChunker(new TokenEstimator);
        $document = new ExtractedDocument('doc', 'notes.pdf', 100, 1, [
            1 => str_repeat('Revenue grew in Muscat. ', 80),
        ], []);

        $chunks = $chunker->chunk($document, 80);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame([1], array_values(array_unique(array_map(fn ($chunk) => $chunk->page, $chunks))));
        $this->assertStringContainsString('Muscat', implode("\n", array_map(fn ($chunk) => $chunk->text, $chunks)));
    }

    public function test_retrieval_prefers_the_page_that_shares_question_words(): void
    {
        config([
            'kimi.max_model_len' => 900,
            'kimi.max_tokens' => 180,
            'kimi.document_token_margin' => 40,
            'kimi.document_chunk_tokens' => 120,
            'kimi.document_max_chunks' => 80,
        ]);

        $plan = $this->service()->plan($this->longDocument(), 'question', 'What happened to revenue in Muscat?');
        $messages = $this->service()->messages($plan, $this->longDocument(), 0);
        $user = $messages[1]['content'];

        $this->assertSame('answer', $plan->stages[0]['type']);
        $this->assertNotSame([], $plan->uncoveredPages);
        $this->assertStringContainsString('untrusted data', $messages[0]['content']);
        $this->assertStringNotContainsString('Revenue grew in Muscat', $messages[0]['content']);
        $this->assertStringContainsString('Revenue grew in Muscat', $user);
        $this->assertStringContainsString('--- PAGE 2 ---', $user);
        $this->assertStringNotContainsString('payroll ledger', $user);
    }

    public function test_an_arabic_summary_request_reads_an_english_handout(): void
    {
        $handout = $this->handout();
        $asked = $this->service()->plan($handout, 'question', 'ما هو لون الشعار؟');
        $plan = $this->service()->plan($handout, 'question', 'لخص لي الموجود ف الملف ع شكل نقاط مختصرة');
        $messages = $this->service()->messages($plan, $handout, 0);
        $user = $messages[1]['content'];

        $this->assertSame('question', $asked->mode);
        $this->assertSame('summarize', $plan->mode);
        $this->assertNull($plan->localReply);
        $this->assertSame(['summary'], array_column($plan->stages, 'type'));
        $this->assertSame([], $plan->uncoveredPages);
        $this->assertStringContainsString('Reply entirely in Arabic', $messages[0]['content']);
        $this->assertStringContainsString('Summarize only the excerpts', $user);
        $this->assertStringContainsString('لخص لي الموجود ف الملف ع شكل نقاط مختصرة', $user);
        $this->assertStringContainsString('Full stack programming introduces', $user);
        $this->assertStringContainsString('From code to production', $user);
        $this->assertStringContainsString('--- PAGE 1 ---', $user);
        $this->assertStringContainsString('--- PAGE 2 ---', $user);
    }

    public function test_a_question_with_no_shared_words_does_not_invent_a_model_prompt(): void
    {
        config([
            'kimi.max_model_len' => 900,
            'kimi.max_tokens' => 180,
            'kimi.document_token_margin' => 40,
            'kimi.document_chunk_tokens' => 120,
        ]);

        $plan = $this->service()->plan($this->longDocument(), 'question', 'zephyr quantum');

        $this->assertSame([], $plan->stages);
        $this->assertIsString($plan->localReply);
        $this->assertStringContainsString('No passage in this PDF', $plan->localReply);
    }

    public function test_arabic_questions_match_arabic_pages_after_the_definite_article(): void
    {
        $ranked = (new DocumentRetriever)->rank([
            new DocumentChunk(0, 1, 'تكاليف التشغيل'),
            new DocumentChunk(1, 2, 'إيرادات مسقط'),
        ], 'ما هي الإيرادات؟');

        $this->assertSame(1, $ranked[0]['chunk']->id);
        $this->assertGreaterThan(0, $ranked[0]['score']);
        $this->assertSame(0.0, $ranked[1]['score']);
    }

    public function test_summaries_are_staged_instead_of_silently_dropping_pages(): void
    {
        config([
            'kimi.max_model_len' => 900,
            'kimi.max_tokens' => 160,
            'kimi.document_token_margin' => 30,
            'kimi.document_chunk_tokens' => 70,
            'kimi.document_partial_tokens' => 40,
            'kimi.document_max_requests' => 8,
        ]);

        $plan = $this->service()->plan($this->longDocument(), 'summarize', 'Summarize this PDF.');
        $types = array_column($plan->stages, 'type');

        $this->assertGreaterThan(1, count($plan->stages));
        $this->assertLessThanOrEqual(8, count($plan->stages));
        $this->assertSame('reduce', $types[array_key_last($types)]);
        $this->assertContains(1, $plan->coveredPages);
        $this->assertContains(2, $plan->coveredPages);
        $this->assertSame([], $plan->uncoveredPages);
    }

    public function test_too_many_passes_are_refused_before_a_prompt_is_built(): void
    {
        config([
            'kimi.max_model_len' => 900,
            'kimi.max_tokens' => 160,
            'kimi.document_token_margin' => 30,
            'kimi.document_chunk_tokens' => 70,
            'kimi.document_partial_tokens' => 40,
            'kimi.document_max_requests' => 1,
        ]);

        $this->expectExceptionMessage('more than 1 model passes');

        $this->service()->plan($this->longDocument(), 'summarize', 'Summarize this PDF.');
    }

    private function extractor(): PdfTextExtractor
    {
        return new PdfTextExtractor(new Parser);
    }

    private function service(): DocumentUnderstandingService
    {
        $tokens = new TokenEstimator;

        return new DocumentUnderstandingService($tokens, new DocumentChunker($tokens), new DocumentRetriever);
    }

    private function handout(): ExtractedDocument
    {
        return new ExtractedDocument('22222222-2222-4222-8222-222222222222', 'full_stack_programming.pdf', 72207, 2, [
            1 => 'Full stack programming introduces the request path. '.str_repeat('Layers separate the interface from storage. ', 34),
            2 => 'From code to production covers the workflow. '.str_repeat('React talks to Laravel and MySQL. ', 54),
        ], []);
    }

    private function longDocument(): ExtractedDocument
    {
        return new ExtractedDocument('11111111-1111-4111-8111-111111111111', 'notes.pdf', 2000, 2, [
            1 => str_repeat('payroll ledger ', 80),
            2 => 'Revenue grew in Muscat after the port expansion.',
        ], [3]);
    }

    private function expectFailure(PdfTextExtractor $extractor, string $pdf, string $fragment): void
    {
        try {
            $extractor->extract($this->write($pdf));
            $this->fail('The PDF should have been rejected.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString($fragment, $exception->userMessage);
        }
    }

    private function write(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdfdoc');
        $this->assertNotFalse($path);
        file_put_contents($path, $pdf);

        return $path;
    }
}
