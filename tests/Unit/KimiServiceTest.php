<?php

namespace Tests\Unit;

use App\Exceptions\InferenceException;
use App\Services\KimiService;
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
}
