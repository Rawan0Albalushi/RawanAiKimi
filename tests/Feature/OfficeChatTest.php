<?php

namespace Tests\Feature;

use App\Services\ChatStore;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OfficeFixture;
use Tests\TestCase;

class OfficeChatTest extends TestCase
{
    public function test_a_word_document_is_summarized_from_text_without_sending_the_package(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'Summarize this document.',
            'intent' => 'summarize',
            'office' => $this->upload(OfficeFixture::word([
                ['text' => 'Revenue', 'heading' => true],
                'Revenue grew in Muscat.',
            ]), 'notes.docx'),
        ])->assertRedirect(route('chat.index'));

        $body = $this->lastRun()->data();
        $messages = $body['input']['openai_input']['messages'];
        $encoded = json_encode($body);

        $this->assertIsString($encoded);
        $this->assertStringContainsString('untrusted data', $messages[0]['content']);
        $this->assertStringContainsString('--- SECTION 1 ---', $messages[1]['content']);
        $this->assertStringContainsString('Revenue grew in Muscat.', $messages[1]['content']);
        $this->assertStringNotContainsString('Revenue grew in Muscat.', $messages[0]['content']);
        $this->assertStringNotContainsString('[Content_Types]', $encoded);
        $this->assertStringNotContainsString('word/document.xml', $encoded);

        $attachment = $this->transcriptAttachment();
        $this->assertSame('docx', $attachment['kind']);
        $this->assertSame('notes.docx', $attachment['name']);
        $this->assertSame(1, $attachment['pages']);
        $this->assertArrayNotHasKey('url', $attachment);
        Storage::disk('local')->assertExists($this->stored($attachment['id'], 'docx'));
    }

    public function test_an_arabic_summary_request_reads_an_english_spreadsheet(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'لخص لي الموجود ف الملف ع شكل نقاط مختصرة',
            'office' => $this->upload(OfficeFixture::workbook([
                'Sales' => [
                    ['City', 'Revenue'],
                    ['Muscat', 12],
                ],
            ]), 'sales.xlsx'),
        ])->assertRedirect(route('chat.index'));

        $messages = $this->lastRun()->data()['input']['openai_input']['messages'];

        $this->assertStringContainsString('Reply entirely in Arabic', $messages[0]['content']);
        $this->assertStringContainsString('Summarize only the excerpts', $messages[1]['content']);
        $this->assertStringContainsString('--- SHEET 1 ---', $messages[1]['content']);
        $this->assertStringContainsString('Sheet: Sales', $messages[1]['content']);
        $this->assertStringContainsString('Muscat | 12', $messages[1]['content']);
        $this->assertSame('xlsx', $this->transcriptAttachment()['kind']);
    }

    public function test_invalid_macro_and_conflicting_office_uploads_never_call_runpod(): void
    {
        Storage::fake('local');
        Http::fake();

        $this->from('/')->post('/chat', [
            'message' => 'Read this',
            'office' => UploadedFile::fake()->create('notes.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertSessionHasErrors('office');

        $this->from('/')->post('/chat', [
            'message' => 'Read this',
            'office' => $this->upload(OfficeFixture::macroWord(), 'macros.docx'),
        ])->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('error', fn (mixed $error): bool => is_string($error) && str_contains($error, 'macros'))
            ->has('transcript', 0));

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'office' => $this->upload(OfficeFixture::word(['Hello']), 'notes.docx'),
            'image' => UploadedFile::fake()->create('photo.jpg', 4, 'image/jpeg'),
        ])->assertSessionHasErrors();

        Http::assertNothingSent();
    }

    public function test_a_follow_up_uses_the_stored_spreadsheet_text(): void
    {
        Storage::fake('local');
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/run')) {
                return Http::response(['id' => 'job-1', 'status' => 'IN_QUEUE']);
            }

            return Http::response([
                'id' => 'job-1',
                'status' => 'COMPLETED',
                'output' => [
                    'model' => 'kimi-vl',
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Recorded reply'],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14],
                ],
            ]);
        });

        $this->from('/')->post('/chat', [
            'message' => 'Which city is named?',
            'office' => $this->upload(OfficeFixture::workbook([
                'Sales' => [['City'], ['Muscat']],
            ]), 'sales.xlsx'),
        ]);
        $this->post('/chat/poll');

        $id = $this->transcriptAttachment()['id'];

        $this->from('/')->post('/chat', [
            'message' => 'Where is the city?',
            'document' => $id,
            'intent' => 'question',
        ])->assertRedirect(route('chat.index'));

        $messages = $this->lastRun()->data()['input']['openai_input']['messages'];

        $this->assertStringContainsString('Where is the city?', $messages[1]['content']);
        $this->assertStringContainsString('Muscat', $messages[1]['content']);
        $this->assertCount(1, array_filter(
            Storage::disk('local')->allFiles('chat-office'),
            fn (string $path): bool => str_ends_with($path, '.xlsx'),
        ));
    }

    public function test_deleting_the_chat_removes_office_files_and_old_files_are_pruned(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'Summarize this document.',
            'office' => $this->upload(OfficeFixture::word(['Revenue grew in Muscat.']), 'notes.docx'),
        ]);

        $chatId = session(ChatStore::ACTIVE);
        $this->assertIsString($chatId);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('chat-office'));
        $this->post(route('chat.delete', $chatId))->assertRedirect(route('chat.index'));
        $this->assertSame([], Storage::disk('local')->allFiles('chat-office'));

        $old = 'chat-office/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/11111111-1111-4111-8111-111111111111.docx';
        $oldJson = 'chat-office/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/11111111-1111-4111-8111-111111111111.json';
        $recent = 'chat-office/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/22222222-2222-4222-8222-222222222222.xlsx';
        Storage::disk('local')->put($old, 'PK old');
        Storage::disk('local')->put($oldJson, '{"name":"old.docx"}');
        Storage::disk('local')->put($recent, 'PK new');
        touch(Storage::disk('local')->path($old), now()->subMinutes(400)->getTimestamp());
        touch(Storage::disk('local')->path($oldJson), now()->subMinutes(400)->getTimestamp());

        $this->artisan('chat-documents:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertMissing($oldJson);
        Storage::disk('local')->assertExists($recent);
    }

    public function test_a_hostile_office_filename_cannot_choose_the_storage_path(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'Summarize this document.',
            'office' => $this->upload(OfficeFixture::word(['Safe text.']), '..\\..\\secret.docx'),
        ])->assertRedirect(route('chat.index'));

        $files = Storage::disk('local')->allFiles('chat-office');

        $this->assertCount(2, $files);

        foreach ($files as $path) {
            $this->assertDoesNotMatchRegularExpression('/secret|\.\./', $path);
            $this->assertMatchesRegularExpression('#^chat-office/[0-9a-f-]{36}/[0-9a-f-]{36}\.(docx|json)$#', $path);
        }

        $this->assertSame('secret.docx', $this->transcriptAttachment()['name']);
    }

    private function fakeQueue(): void
    {
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'id' => 'job-1',
                'status' => 'IN_QUEUE',
            ]),
            'https://api.runpod.ai/v2/f6icz11anfucte/status/*' => Http::response([
                'id' => 'job-1',
                'status' => 'IN_PROGRESS',
            ]),
            'https://api.runpod.ai/v2/f6icz11anfucte/cancel/*' => Http::response([
                'id' => 'job-1',
                'status' => 'CANCELLED',
            ]),
        ]);
    }

    private function lastRun(): Request
    {
        $runs = $this->recordedRuns();
        $this->assertNotEmpty($runs);

        return $runs->last()[0];
    }

    private function recordedRuns(): Collection
    {
        return collect(Http::recorded())->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), '/run'),
        )->values();
    }

    private function upload(string $contents, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'office');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptAttachment(): array
    {
        $attachment = $this->chatTranscript()[0]['attachment'] ?? null;
        $this->assertIsArray($attachment);

        return $attachment;
    }

    private function stored(string $id, string $extension): string
    {
        $key = session(ChatStore::ACTIVE);
        $this->assertIsString($key);

        return 'chat-office/'.$key.'/'.$id.'.'.$extension;
    }
}
