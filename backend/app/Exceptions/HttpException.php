<?php

namespace App\Exceptions;

use Exception;

/**
 * HTTP exception with an application error code, mirroring the legacy
 * HttpException used throughout the custom PHP backend.
 *
 * Rendered as: {"error": {"code": "...", "message": "..."}}
 */
class HttpException extends Exception
{
    public function __construct(
        public int $httpStatus,
        public string $errorCode,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
