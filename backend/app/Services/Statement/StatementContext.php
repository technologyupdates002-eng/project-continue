<?php

declare(strict_types=1);

namespace App\Services\Statement;

class StatementContext
{
    public function __construct(
        public readonly string $batchId,
        public readonly ?string $accountNumber = null,
        public readonly ?string $syncUserId = null,
    ) {}
}