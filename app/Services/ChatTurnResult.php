<?php

namespace App\Services;

final readonly class ChatTurnResult
{
    /**
     * @param  array<int, array<string, mixed>>  $transcript
     * @param  array{status: string, message: string}|null  $pending
     * @param  array<string, int>|null  $usage
     */
    public function __construct(
        public array $transcript,
        public ?array $pending,
        public ?string $error,
        public ?int $latencyMs,
        public ?array $usage,
        public bool $blocked,
        public bool $configured,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transcript' => $this->transcript,
            'pending' => $this->pending,
            'error' => $this->error,
            'latencyMs' => $this->latencyMs,
            'usage' => $this->usage,
            'blocked' => $this->blocked,
            'configured' => $this->configured,
        ];
    }
}
