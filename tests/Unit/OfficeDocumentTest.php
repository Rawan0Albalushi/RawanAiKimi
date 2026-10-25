<?php

namespace Tests\Unit;

use App\Exceptions\DocumentException;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentRetriever;
use App\Services\Documents\DocumentUnderstandingService;
use App\Services\Documents\ExtractedDocument;
use App\Services\Documents\OfficeTextExtractor;
use App\Services\Documents\TokenEstimator;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\OfficeFixture;
use Tests\TestCase;

class OfficeDocumentTest extends TestCase
{
    public function test_word_keeps_headings_paragraphs_and_table_text(): void
    {
        $extracted = $this->extractor()->extract($this->write(OfficeFixture::word([
            ['text' => 'Revenue', 'heading' => true],
            'Revenue grew in Muscat.',
            ['text' => 'Costs', 'heading' => true],
            'The second section lists costs.',
        ], [
            ['City', 'مسقط'],
        ])));

        $this->assertSame('docx', $extracted['kind']);
        $this->assertSame('section', $extracted['unit']);
        $this->assertSame('Revenue'."\n\n".'Revenue grew in Muscat.', $extracted['pages'][1]);
        $this->assertStringContainsString('Costs', $extracted['pages'][2]);
        $this->assertStringContainsString('The second section lists costs.', $extracted['pages'][2]);
        $this->assertStringContainsString('City | مسقط', $extracted['pages'][2]);
    }

    public function test_excel_reads_sheets_cached_values_and_dates_without_calculating_formulas(): void
    {
        $serial = '45292';
        $expectedDate = (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->modify('+45292 days')->format('Y-m-d');
        $extracted = $this->extractor()->extract($this->write(OfficeFixture::workbook([
            'Sales' => [
                ['City', 'Revenue'],
                ['مسقط', 12],
                [12, ['formula' => 'A3*2', 'value' => '24']],
                ['Pending', ['formula' => 'A3*3']],
                ['Opened', ['date' => true, 'value' => $serial]],
            ],
            'Empty' => [],
        ])));

        $this->assertSame('xlsx', $extracted['kind']);
        $this->assertSame('sheet', $extracted['unit']);
        $this->assertSame(2, $extracted['page_count']);
        $this->assertSame([2], $extracted['empty_pages']);
        $sheet = $extracted['pages'][1];
        $this->assertStringContainsString('Sheet: Sales', $sheet);
        $this->assertStringContainsString('مسقط | 12', $sheet);
        $this->assertStringContainsString('12 | 24', $sheet);
        $this->assertStringNotContainsString('A3*2', $sheet);
        $this->assertStringContainsString('formula: A3*3', $sheet);
        $this->assertStringContainsString($expectedDate, $sheet);
    }

    public function test_macros_encryption_empty_external_and_corrupt_files_are_rejected(): void
    {
        $extractor = $this->extractor();

        $this->expectFailure($extractor, OfficeFixture::macroWord(), 'macros');
        $this->expectFailure($extractor, OfficeFixture::encryptedWord(), 'encrypted');
        $this->expectFailure($extractor, OfficeFixture::emptyWord(), 'no text');
        $this->expectFailure($extractor, OfficeFixture::workbook(['Outside' => [['Hello']]], true), 'another file');
        $this->expectFailure($extractor, "PK\x03\x04 not a workbook", 'could not be read');
    }

    public function test_a_sheet_with_too_many_rows_is_refused(): void
    {
        config(['kimi.document_max_rows' => 2]);

        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('more than 2 rows');

        $this->extractor()->extract($this->write(OfficeFixture::workbook([
            'Sales' => [['One'], ['Two'], ['Three']],
        ])));
    }

    public function test_an_arabic_summary_request_reads_an_english_spreadsheet(): void
    {
        $document = new ExtractedDocument('33333333-3333-4333-8333-333333333333', 'sales.xlsx', 2000, 1, [
            1 => 'Sheet: Sales'."\n".'City | Muscat'."\n".str_repeat('Revenue grew after the port expansion. ', 80),
        ], [], 'sheet');
        $service = new DocumentUnderstandingService(new TokenEstimator, new DocumentChunker(new TokenEstimator), new DocumentRetriever);
        $plan = $service->plan($document, 'question', 'لخص لي الموجود ف الملف ع شكل نقاط مختصرة', 'office');
        $messages = $service->messages($plan, $document, 0);

        $this->assertSame('summarize', $plan->mode);
        $this->assertSame('office', $plan->source);
        $this->assertSame('sheet', $plan->unit);
        $this->assertNull($plan->localReply);
        $this->assertStringContainsString('--- SHEET 1 ---', $messages[1]['content']);
        $this->assertStringContainsString('Muscat', $messages[1]['content']);
        $this->assertStringContainsString('Reply entirely in Arabic', $messages[0]['content']);
    }

    private function extractor(): OfficeTextExtractor
    {
        return new OfficeTextExtractor;
    }

    private function expectFailure(OfficeTextExtractor $extractor, string $bytes, string $fragment): void
    {
        try {
            $extractor->extract($this->write($bytes));
            $this->fail('The file should have been rejected.');
        } catch (DocumentException $exception) {
            $this->assertStringContainsString($fragment, $exception->userMessage);
        }
    }

    private function write(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'office');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);

        return $path;
    }
}
