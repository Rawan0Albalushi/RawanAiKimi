<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

        $this->assertNull(collect(session('chat.transcript'))->firstWhere('role', 'system'));
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

        $this->withSession([
            'chat.transcript' => [
                ['role' => 'user', 'content' => 'one', 'at' => '2026-10-22T00:00:00+04:00'],
                ['role' => 'assistant', 'content' => 'two', 'at' => '2026-10-22T00:00:01+04:00'],
                ['role' => 'user', 'content' => 'three', 'at' => '2026-10-22T00:00:02+04:00'],
                ['role' => 'assistant', 'content' => 'four', 'at' => '2026-10-22T00:00:03+04:00'],
            ],
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
}
