<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;

class CoopTransferException extends CoopBankException
{
    protected static array $errorMap = [
        '-8' => ['Account authorization failure', false],
        '-2' => ['Invalid or missing parameter', false],
        '-11' => ['Message reference exceeds 28 characters', false],
        'DUPLICATE_REFERENCE' => ['Duplicate message reference', false],
        'INSUFFICIENT_FUNDS' => ['Insufficient funds', false],
        'DAILY_LIMIT_EXCEEDED' => ['Daily transfer limit exceeded', false],
        'INVALID_CURRENCY' => ['Invalid currency', false],
        'INVALID_BANK_CODE' => ['Invalid bank code', false],
        'AMOUNT_MISMATCH' => ['Amount does not match request', false],
    ];

    public static function fromBankError(string $errorCode, string $message, ?\Throwable $previous = null): self
    {
        $mapped = self::$errorMap[$errorCode] ?? [$message, true];

        return new self(
            $message ?: $mapped[0],
            'bank_error',
            $errorCode,
            $mapped[1],
            $previous
        );
    }

    public static function fromQueryException(QueryException $e, string $fallback = 'Database error'): self
    {

        $msg = $e->getMessage();

        if (str_contains($msg, 'Duplicate entry') && str_contains($msg, 'uq_message_reference')) {
            return new self('Duplicate message reference', 'duplicate_reference', 'DUPLICATE_REFERENCE', false, $e);
        }

        return new self($fallback, 'database_error', null, true, $e);
    }
}
