<?php

namespace App\Exceptions;

use RuntimeException;

class AiException extends RuntimeException
{
    public static function rateLimited(): self
    {
        return new self('AI rate limit reached', 429);
    }

    public function isRateLimit(): bool
    {
        return $this->getCode() === 429;
    }

    public function isTimeout(): bool
    {
        return str_contains($this->getMessage(), 'cURL error 28');
    }
}
