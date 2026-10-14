<?php

declare(strict_types=1);

namespace App\Observers;

use Illuminate\Support\Facades\Cache;

/**
 * Bumps a global data version whenever money or member data changes.
 * All v1 dashboard caches embed this version in their keys, so the next
 * dashboard read after a payment recomputes instantly (no stale balances),
 * while unchanged dashboards are served from cache.
 */
class FinancialChangeObserver
{
    public static function bump(): void
    {
        try {
            if (! Cache::add('khwwc:data_version', 2)) {
                Cache::increment('khwwc:data_version');
            }
        } catch (\Throwable) {
            // Cache outage must never block a financial write.
        }
    }

    public function created($model): void
    {
        self::bump();
    }

    public function updated($model): void
    {
        self::bump();
    }

    public function deleted($model): void
    {
        self::bump();
    }
}
