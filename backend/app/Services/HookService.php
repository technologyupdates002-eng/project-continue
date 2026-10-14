<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hook service for legacy RPC functions.
 */
class HookService
{
    public static function memoReference(): string
    {
        $prefix = 'MEMO';
        $date = now()->format('ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        return "{$prefix}-{$date}-{$random}";
    }
}
