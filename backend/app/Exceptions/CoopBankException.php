<?php

namespace App\Exceptions;

class CoopBankException extends \RuntimeException
{
    protected ?string $errorType;
    protected ?string $bankErrorCode;
    protected bool $shouldRetry;

    public function __construct(
        string $message = '',
        ?string $errorType = null,
        ?string $bankErrorCode = null,
        bool $shouldRetry = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorType = $errorType;
        $this->bankErrorCode = $bankErrorCode;
        $this->shouldRetry = $shouldRetry;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function getErrorCategory(): ?string
    {
        return $this->errorType;
    }

    public function getBankErrorCode(): ?string
    {
        return $this->bankErrorCode;
    }

    public function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }
}
