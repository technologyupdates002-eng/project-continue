<?php

namespace App\Services\Cache;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Application-level cache facade with predictable, namespaced keys and TTLs.
 *
 * Financial truth remains in MySQL. Redis is used only for short-lived
 * aggregates, member stats, and operational metadata.
 *
 * Cache key namespaces (always prefixed via CACHE_PREFIX):
 *   khwwc:dashboard:summary           - 60s
 *   khwwc:dashboard:stats             - 30s
 *   khwwc:member:{id}:stats           - 120s
 *   khwwc:member:{id}:contribution-summary - 120s
 *   khwwc:admin:system-health         - 30s
 *   khwwc:admin:financial-health      - 30s
 *   khwwc:admin:bank-health           - 30s
 *   khwwc:welfare-settings            - 600s
 *   khwwc:notifications:unread:{userId} - 15s
 *   khwwc:events:unseen:{userId}:{lastSeenAt} - 15s
 *   khwwc:news:unread:{userId}        - 30s
 *
 * Mutations that should call CacheService::invalidateMember($id) or
 * CacheService::invalidateDashboards():
 *   - STK callback success
 *   - Bank reconciliation update
 *   - Manual contribution edit
 *   - Penalty update
 *   - Wallet debit/credit
 */
class CacheService
{
    public const TTL_SHORT = 30;
    public const TTL_MEDIUM = 120;
    public const TTL_LONG = 600;
    public const TTL_VERY_SHORT = 15;

    public function rememberDashboardStats(Closure $callback): mixed
    {
        return $this->remember('dashboard:stats', self::TTL_SHORT, $callback);
    }

    public function rememberDashboardSummary(Closure $callback): mixed
    {
        return $this->remember('dashboard:summary', self::TTL_SHORT, $callback);
    }

    public function rememberMemberStats(string $memberId, Closure $callback): mixed
    {
        return $this->remember("member:{$memberId}:stats", self::TTL_MEDIUM, $callback);
    }

    public function rememberMemberContributionSummary(string $memberId, Closure $callback): mixed
    {
        return $this->remember("member:{$memberId}:contribution-summary", self::TTL_MEDIUM, $callback);
    }

    public function rememberSystemHealth(Closure $callback): mixed
    {
        return $this->remember('admin:system-health', self::TTL_SHORT, $callback);
    }

    public function rememberFinancialHealth(Closure $callback): mixed
    {
        return $this->remember('admin:financial-health', self::TTL_SHORT, $callback);
    }

    public function rememberBankHealth(Closure $callback): mixed
    {
        return $this->remember('admin:bank-health', self::TTL_SHORT, $callback);
    }

    public function rememberWelfareSettings(Closure $callback): mixed
    {
        return $this->remember('welfare-settings', self::TTL_LONG, $callback);
    }

    public function rememberUnreadNotificationCount(string $userId, Closure $callback): mixed
    {
        return $this->remember("notifications:unread:{$userId}", self::TTL_VERY_SHORT, $callback);
    }

    public function rememberUnreadNewsCount(string $userId, Closure $callback): mixed
    {
        return $this->remember("news:unread:{$userId}", self::TTL_SHORT, $callback);
    }

    public function rememberUnseenEventCount(string $userId, ?string $lastSeenAt, Closure $callback): mixed
    {
        $key = $lastSeenAt ? "events:unseen:{$userId}:" . md5($lastSeenAt) : "events:unseen:{$userId}:none";
        return $this->remember($key, self::TTL_VERY_SHORT, $callback);
    }

    private function remember(string $key, int $ttl, Closure $callback): mixed
    {
        try {
            return Cache::store('redis')->remember($key, $ttl, $callback);
        } catch (\RedisException|\RuntimeException) {
            return $callback();
        }
    }

    public function invalidateMember(string $memberId): void
    {
        Cache::store('redis')->forget("member:{$memberId}:stats");
        Cache::store('redis')->forget("member:{$memberId}:contribution-summary");
        $this->invalidateDashboards();
    }

    public function invalidateDashboards(): void
    {
        Cache::store('redis')->forget('dashboard:stats');
        Cache::store('redis')->forget('dashboard:summary');
        Cache::store('redis')->forget('admin:financial-health');
        Cache::store('redis')->forget('admin:bank-health');
    }

    public function invalidateSystemHealth(): void
    {
        Cache::store('redis')->forget('admin:system-health');
    }

    public function invalidateWelfareSettings(): void
    {
        Cache::store('redis')->forget('welfare-settings');
    }

    public function invalidateUserNotifications(string $userId): void
    {
        Cache::store('redis')->forget("notifications:unread:{$userId}");
    }

    public function invalidateUserNews(string $userId): void
    {
        Cache::store('redis')->forget("news:unread:{$userId}");
    }

    public function invalidateUserEvents(string $userId): void
    {
        Cache::store('redis')->delete(Cache::store('redis')->getRedis()->keys("events:unseen:{$userId}:*"));
    }
}