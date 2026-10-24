<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class DocumentException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $userMessage)
    {
        parent::__construct($userMessage);
    }
}
