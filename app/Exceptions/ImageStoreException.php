<?php

namespace App\Exceptions;

use RuntimeException;

class ImageStoreException extends RuntimeException
{
    public function __construct(public readonly string $userMessage)
    {
        parent::__construct($userMessage);
    }
}
