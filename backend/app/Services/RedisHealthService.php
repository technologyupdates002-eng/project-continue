<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Safe wrapper around the Redis facade that:
 *   - caches the "is redis available" probe so we only try ONCE per minute
 *   - never throws (caller treats the no-redis case as a soft warning)
 *   - degrades to file cache transparently
 *
 * Designed for production environments where the phpredis extension or
 * redis-server may be missing; everything keeps working but loses the
 * high-throughput cache layer.
 */
class RedisHealthService
{
    public const CACHE_KEY_AVAILABLE = 'khwwc:redis:available';
    public const CACHE_TTL = 60; // seconds — re-probe every minute

    public static function isAvailable(): bool
    {
        $cached = Cache::store('file')->get(self::CACHE_KEY_AVAILABLE);
        if ($cached !== null) {
            return (bool) $cached;
        }
        return self::probe();
    }

    public static function probe(): bool
    {
        try {
            if (! class_exists('Redis')) {
                self::remember(false, 'phpredis extension not loaded');
                return false;
            }
            Redis::connection()->ping();
            self::remember(true);
            return true;
        } catch (Throwable $e) {
            self::remember(false, $e->getMessage());
            return false;
        }
    }

    public static function reset(): void
    {
        Cache::store('file')->forget(self::CACHE_KEY_AVAILABLE);
    }

    public static function pingSafe(): bool
    {
        try {
            if (! class_exists('Redis')) {
                return false;
            }
            Redis::connection()->ping();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function infoSafe(): array
    {
        if (! self::isAvailable()) {
            return [];
        }
        try {
            return Redis::info() ?: [];
        } catch (Throwable $e) {
            self::remember(false, $e->getMessage());
            return [];
        }
    }

    public static function dbsizeSafe(): int
    {
        if (! self::isAvailable()) {
            return 0;
        }
        try {
            return (int) Redis::dbsize();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function lastError(): ?string
    {
        return Cache::store('file')->get(self::CACHE_KEY_AVAILABLE . ':error');
    }

    private static function remember(bool $available, ?string $error = null): void
    {
        Cache::store('file')->put(self::CACHE_KEY_AVAILABLE, $available, self::CACHE_TTL);
        if ($error !== null) {
            Cache::store('file')->put(self::CACHE_KEY_AVAILABLE . ':error', $error, self::CACHE_TTL);
            Log::debug('RedisHealthService: ' . $error);
        } else {
            Cache::store('file')->forget(self::CACHE_KEY_AVAILABLE . ':error');
        }
    }
}
