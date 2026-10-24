<?php

namespace Tests\Unit;

use App\Exceptions\InferenceException;
use App\Services\KimiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KimiServiceTest extends TestCase
{
    public function test_it_refuses_to_call_a_host_other_than_runpod(): void
    {
        config([
            'kimi.base_url' => 'https://evil.example/v2',
            'kimi.api_key' => 'test-key-not-real',
            'kimi.endpoint_id' => 'f6icz11anfucte',
        ]);

        try {
            app(KimiService::class)->submit([
                ['role' => 'user', 'content' => 'Hello'],
            ]);
            $this->fail('An off-host URL should be rejected.');
        } catch (InferenceException $exception) {
            $this->assertSame(__('chat.bad_endpoint'), $exception->userMessage);
        }

        Http::assertNothingSent();
    }

    public function test_it_does_not_call_runpod_without_credentials(): void
    {
        config(['kimi.api_key' => '']);

        try {
            app(KimiService::class)->submit([
                ['role' => 'user', 'content' => 'Hello'],
            ]);
            $this->fail('Missing credentials should be rejected.');
        } catch (InferenceException $exception) {
            $this->assertSame(__('chat.not_configured'), $exception->userMessage);
        }

        Http::assertNothingSent();
    }

    public function test_it_drops_a_remote_image_url_and_does_not_call_runpod(): void
    {
        try {
            app(KimiService::class)->submit([
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Look'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/secret.jpg']],
                    ],
                ],
            ]);
            $this->fail('A remote image URL should be rejected.');
        } catch (InferenceException $exception) {
            $this->assertSame(__('chat.message_required'), $exception->userMessage);
        }

        Http::assertNothingSent();
    }

    public function test_it_forwards_one_jpeg_data_url(): void
    {
        Http::fake([
            'https://api.runpod.ai/v2/f6icz11anfucte/run' => Http::response([
                'id' => 'job-1',
                'status' => 'IN_QUEUE',
            ]),
        ]);

        $url = 'data:image/jpeg;base64,aaaa';

        app(KimiService::class)->submit([
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Look'],
                    ['type' => 'image_url', 'image_url' => ['url' => $url]],
                ],
            ],
        ]);

        Http::assertSent(function (Request $request) use ($url): bool {
            $content = $request->data()['input']['openai_input']['messages'][0]['content'];
            $encoded = json_encode($request->data());

            return is_array($content)
                && $content[0]['text'] === 'Look'
                && $content[1]['image_url']['url'] === $url
                && is_string($encoded)
                && ! str_contains($encoded, 'https://example.com');
        });
    }
}
