<?php

namespace Tests\Feature;

use App\Services\ChatStore;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatTest extends TestCase
{
    public function test_the_chat_page_loads_without_exposing_runpod_secrets(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Chat/Index')
            ->where('transcript', [])
            ->where('pending', null)
            ->where('configured', true)
            ->where('modelName', 'kimi-vl')
            ->where('maxImageKilobytes', 8192)
            ->missing('apiKey')
            ->missing('endpointId'));

        $response->assertDontSee('test-key-not-real', false);
        $response->assertDontSee('f6icz11anfucte', false);
        $response->assertDontSee('api.runpod.ai', false);
        $response->assertDontSee('Bearer', false);
    }

    public function test_chat_routes_keep_the_web_middleware_group(): void
    {
        $route = Route::getRoutes()->getByName('chat.store');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_environment_example_has_no_secret_and_uses_file_session(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example);
        $this->assertStringContainsString('SESSION_DRIVER=file', $example);
        $this->assertStringContainsString('CACHE_STORE=file', $example);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $example);
        $this->assertMatchesRegularExpression('/^RUNPOD_API_KEY=\s*$/m', $example);
        $this->assertDoesNotMatchRegularExpression('/RUNPOD_API_KEY=\S+/', $example);
    }

    public function test_validation_rejects_empty_and_overlong_messages(): void
    {
        Http::fake();

        $this->from('/')->post('/chat', ['message' => '   '])->assertSessionHasErrors('message');
        $this->from('/')->post('/chat', ['message' => ['not-a-string']])->assertSessionHasErrors('message');
        $this->from('/')->post('/chat', ['message' => str_repeat('a', 4001)])->assertSessionHasErrors('message');

        Http::assertNothingSent();
    }

    public function test_arabic_validation_message_follows_the_session_locale(): void
    {
        $this->withSession(['locale' => 'ar'])
            ->from('/')
            ->post('/chat', ['message' => ''])
            ->assertSessionHasErrors([
                'message' => 'اكتب رسالة قبل الإرسال.',
            ]);
    }

    public function test_an_arabic_message_is_submitted_to_the_queue(): void
    {
        $this->fakeQueue();
        $message = str_repeat('م', 4000);

        $this->from('/')->post('/chat', ['message' => $message])->assertRedirect(route('chat.index'));

        Http::assertSent(function (Request $request) use ($message) {
            if (! str_ends_with($request->url(), '/run')) {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-key-not-real')
                && $body['input']['openai_route'] === '/v1/chat/completions'
                && $body['input']['openai_input']['model'] === 'kimi-vl'
                && $body['input']['openai_input']['stream'] === false
                && abs((float) $body['input']['openai_input']['temperature'] - 0.2) < 0.001
                && $body['input']['openai_input']['max_tokens'] === 512
                && $body['input']['openai_input']['messages'][0]['role'] === 'system'
                && str_contains($body['input']['openai_input']['messages'][0]['content'], 'You are a helpful assistant.')
                && str_contains($body['input']['openai_input']['messages'][0]['content'], 'Reply entirely in Arabic.')
                && $body['input']['openai_input']['messages'][1]['content'] === $message;
        });
    }

    public function test_an_arabic_question_with_english_terms_still_asks_for_an_arabic_reply(): void
    {
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'لخص لي خطوات انشاء مشروع laravel + react',
        ]);

        $system = $this->lastRun()->data()['input']['openai_input']['messages'][0]['content'];

        $this->assertStringContainsString('Reply entirely in Arabic.', $system);
        $this->assertStringNotContainsString('Reply entirely in English.', $system);
    }

    public function test_polling_a_completed_job_appends_the_assistant_reply(): void
    {
        $this->fakeQueue(status: $this->completed('Muscat.'));

        $this->from('/')->post('/chat', ['message' => 'What is the capital of Oman?']);
        $this->get('/')->assertDontSee('job-1', false);

        $this->post('/chat/poll')->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->where('error', null)
            ->where('transcript.0.role', 'user')
            ->where('transcript.0.content', 'What is the capital of Oman?')
            ->where('transcript.1.role', 'assistant')
            ->where('transcript.1.content', 'Muscat.')
            ->where('transcript.1.model', 'kimi-vl')
            ->where('transcript.1.usage.total_tokens', 14)
            ->where('latencyMs', fn (mixed $latency): bool => is_int($latency) && $latency >= 0));

        $this->assertNull(collect($this->chatTranscript())->firstWhere('role', 'system'));
    }

    public function test_a_one_element_output_array_is_accepted(): void
    {
        $this->fakeQueue(status: $this->completed('Reply', wrapped: true));

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('transcript.1.content', 'Reply')
            ->where('error', null));
    }

    public function test_queue_and_progress_states_do_not_invent_a_reply(): void
    {
        $this->fakeQueue(status: [
            'id' => 'job-1',
            'status' => 'IN_QUEUE',
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('transcript', 1)
            ->where('pending.status', 'IN_QUEUE')
            ->where('pending.message', 'Starting the model. This can take several minutes the first time.')
            ->missing('pending.job_id'));
    }

    public function test_a_follow_up_includes_the_previous_turn_and_respects_the_cap(): void
    {
        config(['kimi.context_messages' => 2]);
        $this->fakeCallback();

        $id = (string) Str::uuid();
        app(ChatStore::class)->putMessages($id, [
            ['role' => 'user', 'content' => 'one', 'at' => '2026-10-22T00:00:00+04:00'],
            ['role' => 'assistant', 'content' => 'two', 'at' => '2026-10-22T00:00:01+04:00'],
            ['role' => 'user', 'content' => 'three', 'at' => '2026-10-22T00:00:02+04:00'],
            ['role' => 'assistant', 'content' => 'four', 'at' => '2026-10-22T00:00:03+04:00'],
        ]);

        $this->withSession([
            ChatStore::ACTIVE => $id,
        ])->from('/')->post('/chat', ['message' => 'five']);

        $request = $this->lastRun();
        $contents = array_column($request->data()['input']['openai_input']['messages'], 'content');

        $this->assertSame([
            "You are a helpful assistant.\n\nThe user wrote in English. Reply entirely in English.",
            'four',
            'five',
        ], $contents);
    }

    public function test_resubmitting_the_same_failed_text_does_not_duplicate_the_turn(): void
    {
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::sequence()
                ->push(['message' => 'no'], 500)
                ->push(['id' => 'job-1', 'status' => 'IN_QUEUE'], 200),
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->from('/')->post('/chat', ['message' => 'Hello']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('transcript', 1)
            ->where('transcript.0.content', 'Hello')
            ->where('pending.status', 'IN_QUEUE')
            ->where('error', null));
    }

    public function test_a_second_message_is_refused_while_a_job_is_in_flight(): void
    {
        $this->fakeQueue();

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->from('/')->post('/chat', ['message' => 'Another']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('transcript', 1)
            ->where('transcript.0.content', 'Hello')
            ->where('error', 'A reply is already in progress.'));

        $this->assertCount(1, $this->recordedRuns());
    }

    public function test_a_completed_worker_error_does_not_become_assistant_text(): void
    {
        $this->fakeQueue(status: [
            'id' => 'job-1',
            'status' => 'COMPLETED',
            'output' => [
                'error' => [
                    'message' => 'CUDA out of memory',
                    'type' => 'worker_error',
                ],
            ],
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('transcript', 1)
            ->where('blocked', false)
            ->where('error', 'CUDA out of memory'));
    }

    public function test_a_startup_error_blocks_another_job_until_the_chat_is_cleared(): void
    {
        $this->fakeQueue(status: [
            'id' => 'job-1',
            'status' => 'COMPLETED',
            'output' => [
                'error' => [
                    'message' => 'vLLM failed to start',
                    'type' => 'startup_error',
                ],
            ],
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');
        $this->from('/')->post('/chat', ['message' => 'Try again']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('blocked', true)
            ->has('transcript', 1)
            ->where('error', fn (string $error): bool => str_contains($error, 'Clear the chat before trying again.')));

        $this->assertCount(1, $this->recordedRuns());
    }

    public function test_failed_status_is_an_error_without_an_assistant_turn(): void
    {
        $this->fakeQueue(status: ['id' => 'job-1', 'status' => 'FAILED']);
        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('error', 'The reply failed.')
            ->has('transcript', 1));
    }

    public function test_an_empty_reply_is_an_error(): void
    {
        $this->fakeQueue(status: [
            'id' => 'job-1',
            'status' => 'COMPLETED',
            'output' => [
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => '   '],
                ]],
            ],
        ]);

        $this->from('/')->post('/chat', ['message' => 'Again']);
        $this->post('/chat/poll');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('error', 'The model returned an empty reply.')
            ->has('transcript', 1));
    }

    public function test_a_missing_job_id_is_not_polled_or_retried(): void
    {
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'status' => 'IN_QUEUE',
            ]),
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->where('error', 'The request was not accepted. You can send it again.')
            ->has('transcript', 1));

        $this->assertCount(1, $this->recordedRuns());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/status/'));
    }

    public function test_refused_credentials_are_not_written_to_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);

        $this->get('/')
            ->assertDontSee('test-key-not-real', false)
            ->assertInertia(fn (Assert $page) => $page->where('error', 'RunPod refused the credentials.'));

        $this->assertNotEmpty($logged);

        foreach ($logged as $event) {
            $encoded = json_encode([$event->message, $event->context]);

            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('test-key-not-real', $encoded);
        }
    }

    public function test_a_submit_conflict_logs_the_remote_code_without_retrying_or_recording_the_prompt(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'kimi.request_failed') {
                $logged[] = $event;
            }
        });

        $prompt = 'Unique prompt that must stay out of the log 9f3c';

        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'code' => 409,
                'message' => [
                    'status' => 409,
                    'title' => 'Conflict',
                    'detail' => 'Endpoint refused the job. Echo: '.$prompt.' Authorization: Bearer test-key-not-real',
                    'code' => 'ENDPOINT_PAUSED',
                ],
                'input' => [
                    'openai_input' => [
                        'messages' => [
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ],
                ],
            ], 409),
        ]);

        $this->from('/')->post('/chat', ['message' => $prompt]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->where('error', 'The request was not accepted. You can send it again.'));

        Http::assertSentCount(1);
        $this->assertCount(1, $logged);

        $context = $logged[0]->context;
        $encoded = json_encode($context);

        $this->assertSame('submit', $context['operation']);
        $this->assertSame(409, $context['status']);
        $this->assertSame('ENDPOINT_PAUSED', $context['error_code']);
        $this->assertStringContainsString('Endpoint refused the job.', $context['error_message']);
        $this->assertStringNotContainsString($prompt, $context['error_message']);
        $this->assertStringNotContainsString('Bearer', $context['error_message']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('test-key-not-real', $encoded);
        $this->assertStringNotContainsString($prompt, $encoded);
        $this->assertArrayNotHasKey('input', $context);
    }

    public function test_an_unstructured_conflict_body_is_not_copied_into_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'kimi.request_failed') {
                $logged[] = $event;
            }
        });

        $prompt = 'Another private prompt 4c1e';

        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response(
                '<html>'.$prompt.' test-key-not-real</html>',
                409,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->from('/')->post('/chat', ['message' => $prompt]);

        Http::assertSentCount(1);
        $this->assertCount(1, $logged);

        $encoded = json_encode($logged[0]->context);

        $this->assertSame([
            'operation' => 'submit',
            'status' => 409,
        ], $logged[0]->context);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($prompt, $encoded);
        $this->assertStringNotContainsString('test-key-not-real', $encoded);
    }

    public function test_the_poll_budget_cancels_once_and_does_not_submit_again(): void
    {
        config(['kimi.poll_budget_seconds' => 1]);
        $this->fakeQueue();

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->travel(2)->seconds();
        $this->post('/chat/poll');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cancel/job-1'));
        $this->assertCount(1, $this->recordedRuns());

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->where('error', 'The wait ended before a reply arrived.'));
    }

    public function test_repeated_status_failures_stop_the_reply(): void
    {
        config(['kimi.transport_attempts' => 2]);
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'id' => 'job-1',
                'status' => 'IN_QUEUE',
            ]),
            'https://api.runpod.ai/v2/f6icz11anfucte/status/*' => Http::failedConnection(),
            'https://api.runpod.ai/v2/f6icz11anfucte/cancel/*' => Http::response([
                'status' => 'CANCELLED',
            ]),
        ]);

        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/poll');
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('pending.status', 'IN_QUEUE'));

        $this->post('/chat/poll');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/cancel/job-1'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->where('error', 'The status check kept failing, so this reply was stopped.'));
    }

    public function test_cancel_stops_the_job_and_keeps_the_user_message(): void
    {
        $this->fakeQueue();
        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/cancel');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('pending', null)
            ->has('transcript', 1)
            ->where('error', 'The reply was cancelled. The worker may keep running until RunPod stops it.'));

        $this->assertCount(1, $this->recordedCancels());
    }

    public function test_clear_cancels_an_in_flight_job_and_removes_the_transcript(): void
    {
        $this->fakeQueue();
        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $this->post('/chat/clear');

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('transcript', [])
            ->where('error', null)
            ->where('pending', null)
            ->where('blocked', false));

        $this->assertCount(1, $this->recordedCancels());
    }

    public function test_clearing_removes_history_from_the_next_request(): void
    {
        $this->fakeCallback();

        $this->from('/')->post('/chat', ['message' => 'First']);
        $this->post('/chat/poll');
        $this->post('/chat/clear');
        $this->from('/')->post('/chat', ['message' => 'Second']);

        $contents = array_column($this->lastRun()->data()['input']['openai_input']['messages'], 'content');

        $this->assertSame([
            "You are a helpful assistant.\n\nThe user wrote in English. Reply entirely in English.",
            'Second',
        ], $contents);
    }

    public function test_a_bad_base_url_never_leaves_the_application(): void
    {
        config(['kimi.base_url' => 'http://127.0.0.1:8000/v1']);

        $this->from('/')->post('/chat', ['message' => 'Hello']);

        Http::assertNothingSent();
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('configured', false)
            ->where('error', 'The inference URL is not the RunPod API.'));
    }

    public function test_send_is_rate_limited_without_starting_another_job(): void
    {
        $this->fakeQueue();

        for ($i = 0; $i < 8; $i++) {
            $this->post('/chat/clear');
            $this->from('/')->post('/chat', ['message' => 'Message '.$i]);
        }

        $this->from('/')->post('/chat', ['message' => 'One more']);

        $this->assertCount(8, $this->recordedRuns());
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('error', 'Wait a moment before sending another message.'));
    }

    public function test_the_locale_switch_is_shared_with_the_page(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'ar'])->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('copy.send', 'إرسال')
            ->where('copy.status_queue', 'يجري تشغيل النموذج. قد يستغرق ذلك عدة دقائق في المرة الأولى.'));
    }

    public function test_an_image_and_text_are_sent_as_one_data_url_without_history_or_session_bytes(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $id = (string) Str::uuid();
        app(ChatStore::class)->putMessages($id, [
            ['role' => 'user', 'content' => 'earlier question', 'at' => '2026-10-22T00:00:00+04:00'],
            ['role' => 'assistant', 'content' => 'earlier answer', 'at' => '2026-10-22T00:00:01+04:00'],
        ]);

        $this->withSession([
            ChatStore::ACTIVE => $id,
        ])->from('/')->post('/chat', [
            'message' => 'What is in this picture?',
            'image' => $this->imageUpload(),
        ])->assertRedirect(route('chat.index'));

        $messages = $this->lastRun()->data()['input']['openai_input']['messages'];
        $imageUrl = $messages[1]['content'][1]['image_url']['url'];

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertIsString($messages[0]['content']);
        $this->assertStringNotContainsString('earlier question', $messages[0]['content']);
        $this->assertSame('What is in this picture?', $messages[1]['content'][0]['text']);
        $this->assertSame('image_url', $messages[1]['content'][1]['type']);
        $this->assertMatchesRegularExpression('#\Adata:image/jpeg;base64,[A-Za-z0-9+/]+={0,2}\z#', $imageUrl);

        $session = json_encode(session()->all());
        $this->assertIsString($session);
        $this->assertStringNotContainsString('base64', $session);
        $this->assertStringNotContainsString('data:image', $session);
        $this->assertStringNotContainsString($imageUrl, $session);

        $turns = $this->chatTranscript();
        $id = $turns === [] ? null : ($turns[array_key_last($turns)]['attachment']['id'] ?? null);
        $this->assertIsString($id);

        $this->get(route('chat.attachments.show', $id))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('cache-control', 'no-store, private');

        $this->flushSession();
        $this->get(route('chat.attachments.show', $id))->assertNotFound();
    }

    public function test_a_png_is_reencoded_and_an_empty_arabic_caption_uses_the_default_prompt(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->withSession(['locale' => 'ar'])->from('/')->post('/chat', [
            'message' => '',
            'image' => $this->imageUpload('png'),
        ]);

        $messages = $this->lastRun()->data()['input']['openai_input']['messages'];

        $this->assertSame('صف هذه الصورة.', $messages[1]['content'][0]['text']);
        $this->assertStringContainsString('Reply entirely in Arabic.', $messages[0]['content']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $messages[1]['content'][1]['image_url']['url']);
    }

    public function test_a_later_text_turn_does_not_resend_the_image_and_polling_does_not_submit_again(): void
    {
        Storage::fake('local');
        $this->fakeCallback();

        $this->from('/')->post('/chat', [
            'message' => 'What is in this picture?',
            'image' => $this->imageUpload(),
        ]);
        $this->post('/chat/poll')->assertRedirect(route('chat.index'));
        $this->from('/')->post('/chat', ['message' => 'Thanks']);

        $messages = $this->lastRun()->data()['input']['openai_input']['messages'];

        foreach ($messages as $message) {
            $this->assertIsString($message['content']);
        }

        $encoded = json_encode($messages);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('What is in this picture?', $encoded);
        $this->assertStringContainsString('Thanks', $encoded);
        $this->assertStringNotContainsString('data:image', $encoded);
        $this->assertCount(2, $this->recordedRuns());
    }

    public function test_a_non_image_and_an_oversized_image_are_rejected_before_runpod(): void
    {
        Storage::fake('local');
        Http::fake();

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ])->assertSessionHasErrors('image');

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => UploadedFile::fake()->create('drawing.svg', 4, 'image/svg+xml'),
        ])->assertSessionHasErrors('image');

        config(['kimi.image_max_source_edge' => 4]);

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => $this->imageUpload('jpg', 8, 8),
        ])->assertSessionHasErrors('image');

        config(['kimi.image_max_kilobytes' => 1, 'kimi.image_max_source_edge' => 8000]);

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => UploadedFile::fake()->create('big.jpg', 3, 'image/jpeg'),
        ])->assertSessionHasErrors('image');

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('local')->allFiles('chat-images'));
    }

    public function test_clearing_the_chat_deletes_the_stored_image(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => $this->imageUpload(),
        ]);

        $this->assertNotEmpty(Storage::disk('local')->allFiles('chat-images'));
        $chatId = session(ChatStore::ACTIVE);
        $this->assertIsString($chatId);

        $this->post('/chat/clear')->assertRedirect(route('chat.index'));

        $this->assertNotEmpty(Storage::disk('local')->allFiles('chat-images'));
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('transcript', [])
            ->where('pending', null)
            ->has('chats', 1)
            ->where('chats.0.id', $chatId));

        $this->post(route('chat.delete', $chatId))->assertRedirect(route('chat.index'));

        $this->assertSame([], Storage::disk('local')->allFiles('chat-images'));
        Storage::disk('local')->assertMissing('chats/'.$chatId.'.json');
    }

    public function test_a_new_chat_keeps_the_previous_transcript_and_reopening_restores_it(): void
    {
        $this->fakeCallback();

        $this->from('/')->post('/chat', ['message' => 'What is the capital of Oman?']);
        $this->post('/chat/poll');
        $first = session(ChatStore::ACTIVE);
        $this->assertIsString($first);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('activeId', $first)
            ->where('transcript.1.content', 'Recorded reply'));

        $this->post('/chat/clear')->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('transcript', [])
            ->where('activeId', fn (mixed $id): bool => is_string($id) && $id !== $first)
            ->has('chats', 1)
            ->where('chats.0.id', $first)
            ->where('chats.0.title', 'What is the capital of Oman?'));

        $this->post(route('chat.open', $first))->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('activeId', $first)
            ->where('transcript.0.content', 'What is the capital of Oman?')
            ->where('transcript.1.content', 'Recorded reply'));
    }

    public function test_opening_another_chat_is_refused_while_a_reply_is_in_flight(): void
    {
        $this->fakeQueue();
        $this->from('/')->post('/chat', ['message' => 'Hello']);
        $first = session(ChatStore::ACTIVE);
        $this->assertIsString($first);

        $other = (string) Str::uuid();
        app(ChatStore::class)->putMessages($other, [
            ['role' => 'user', 'content' => 'older', 'at' => '2026-10-22T00:00:00+04:00'],
        ]);

        $this->post(route('chat.open', $other))->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('activeId', $first)
            ->where('error', 'A reply is already in progress.')
            ->where('transcript.0.content', 'Hello'));
    }

    public function test_deleting_one_chat_leaves_the_other_in_place(): void
    {
        $this->fakeCallback();

        $this->from('/')->post('/chat', ['message' => 'First']);
        $this->post('/chat/poll');
        $first = session(ChatStore::ACTIVE);
        $this->assertIsString($first);

        $this->post('/chat/clear');
        $this->from('/')->post('/chat', ['message' => 'Second']);
        $this->post('/chat/poll');

        $this->post(route('chat.delete', $first))->assertRedirect(route('chat.index'));

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('chats', 1)
            ->where('chats.0.title', 'Second')
            ->where('transcript.0.content', 'Second'));

        Storage::disk('local')->assertMissing('chats/'.$first.'.json');
    }

    public function test_an_image_stays_available_after_leaving_and_reopening_the_chat(): void
    {
        Storage::fake('local');
        $this->fakeQueue();

        $this->from('/')->post('/chat', [
            'message' => 'Look',
            'image' => $this->imageUpload(),
        ]);

        $chatId = session(ChatStore::ACTIVE);
        $turns = $this->chatTranscript();
        $imageId = $turns[array_key_last($turns)]['attachment']['id'] ?? null;
        $this->assertIsString($chatId);
        $this->assertIsString($imageId);

        $this->post('/chat/clear');
        $this->post(route('chat.open', $chatId))->assertRedirect(route('chat.index'));

        $this->get(route('chat.attachments.show', $imageId))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_an_echoed_image_payload_is_redacted_from_the_log(): void
    {
        Storage::fake('local');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'kimi.request_failed') {
                $logged[] = $event;
            }
        });

        Http::fake(function (Request $request) {
            return Http::response([
                'error' => [
                    'message' => 'rejected '.json_encode($request->data()),
                ],
            ], 500);
        });

        $this->from('/')->post('/chat', [
            'message' => 'Look at this',
            'image' => $this->imageUpload(),
        ]);

        $this->assertNotEmpty($logged);

        foreach ($logged as $event) {
            $encoded = json_encode([$event->message, $event->context]);

            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('base64', $encoded);
            $this->assertStringNotContainsString('data:image', $encoded);
            $this->assertStringNotContainsString('Look at this', $encoded);
        }
    }

    public function test_old_chat_images_are_pruned_and_a_recent_image_stays(): void
    {
        Storage::fake('local');
        $old = 'chat-images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/old.jpg';
        $recent = 'chat-images/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/recent.jpg';
        Storage::disk('local')->put($old, 'old');
        Storage::disk('local')->put($recent, 'new');
        touch(Storage::disk('local')->path($old), now()->subMinutes(400)->getTimestamp());

        $this->artisan('chat-images:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($recent);
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function fakeQueue(?array $status = null): void
    {
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'id' => 'job-1',
                'status' => 'IN_QUEUE',
            ]),
            'https://api.runpod.ai/v2/f6icz11anfucte/status/*' => Http::response($status ?? [
                'id' => 'job-1',
                'status' => 'IN_PROGRESS',
            ]),
            'https://api.runpod.ai/v2/f6icz11anfucte/cancel/*' => Http::response([
                'id' => 'job-1',
                'status' => 'CANCELLED',
            ]),
        ]);
    }

    private function fakeCallback(): void
    {
        $runs = 0;

        Http::fake(function (Request $request) use (&$runs) {
            if (str_ends_with($request->url(), '/run')) {
                $runs++;

                return Http::response([
                    'id' => 'job-'.$runs,
                    'status' => 'IN_QUEUE',
                ]);
            }

            if (str_contains($request->url(), '/status/')) {
                return Http::response($this->completed('Recorded reply'));
            }

            return Http::response(['status' => 'CANCELLED']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function completed(string $content, bool $wrapped = false): array
    {
        $output = [
            'model' => 'kimi-vl',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 4,
                'total_tokens' => 14,
            ],
        ];

        return [
            'id' => 'job-1',
            'status' => 'COMPLETED',
            'delayTime' => 1200,
            'executionTime' => 800,
            'output' => $wrapped ? [$output] : $output,
        ];
    }

    private function lastRun(): Request
    {
        $runs = $this->recordedRuns();

        $this->assertNotEmpty($runs);

        return $runs->last()[0];
    }

    private function recordedCancels(): Collection
    {
        return collect(Http::recorded())->filter(
            fn (array $pair): bool => str_contains($pair[0]->url(), '/cancel/'),
        )->values();
    }

    private function recordedRuns(): Collection
    {
        return collect(Http::recorded())->filter(
            fn (array $pair): bool => str_ends_with($pair[0]->url(), '/run'),
        )->values();
    }

    private function imageUpload(string $extension = 'jpg', int $width = 12, int $height = 8): UploadedFile
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to build a chat image.');
        }

        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 30, 90, 140);
        imagefilledrectangle($image, 0, 0, max(0, $width - 1), max(0, $height - 1), $color);
        $path = tempnam(sys_get_temp_dir(), 'chatimg');
        $this->assertNotFalse($path);

        if ($extension === 'png') {
            imagepng($image, $path);
            $name = 'photo.png';
            $mime = 'image/png';
        } else {
            imagejpeg($image, $path, 90);
            $name = 'photo.jpg';
            $mime = 'image/jpeg';
        }

        imagedestroy($image);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
