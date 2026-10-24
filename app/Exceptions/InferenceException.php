<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InferenceException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $userMessage,
        public readonly bool $transient = false,
        public readonly ?string $errorType = null,
    ) {
        parent::__construct($userMessage);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'transient' => $this->transient,
            'error_type' => $this->errorType,
        ];
    }
}
