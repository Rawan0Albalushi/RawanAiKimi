<?php

namespace Tests;

use App\Services\ChatStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();
        Storage::fake('local');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function chatTranscript(): array
    {
        $id = session(ChatStore::ACTIVE);

        if (! is_string($id)) {
            return [];
        }

        return app(ChatStore::class)->messages($id);
    }
}
