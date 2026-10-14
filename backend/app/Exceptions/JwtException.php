<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * JWT-specific exception carrying an HTTP status code.
 */
class JwtException extends RuntimeException
{
    public function __construct(string $message, public int $status = 401, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous?->getCode() ?? 0, $previous);
    }
}
