<?php

declare(strict_types=1);

namespace App\Services\Statement;

enum StatementMode: string
{
    case TREASURER_STATEMENT_IMPORT = 'TREASURER_STATEMENT_IMPORT';
    case BANK_STATEMENT_SYNC = 'BANK_STATEMENT_SYNC';
}