<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Distributed lock service backed by Redis.
 *
 * Uses SET NX EX semantics with an owner token, releasing the lock only when
 * the token matches (Lua CAS). Locks always have a TTL to recover from worker
 * crashes; never use this to gate anything that could create a permanent lock.
 *
 * Usage:
 *   $lock = app(RedisLockService::class);
 *   if (! $lock->acquire('coop:statement-sync', 30)) { return; }
 *   try { ... } finally { $lock->release('coop:statement-sync'); }
 *
 * Or the convenience wrapper:
 *   $lock->run('coop:statement-sync', 30, function () { ... });
 */
class RedisLockService
{
    public function acquire(string $key, int $ttlSeconds = 30): ?string
    {
        $token = bin2hex(random_bytes(16));
        $fullKey = $this->prefix($key);

        $ok = Redis::set($fullKey, $token, 'EX', $ttlSeconds, 'NX');
        if ($ok === true || $ok === 'OK' || $ok === 1) {
            return $token;
        }
        return null;
    }

    public function release(string $key, ?string $token = null): bool
    {
        $fullKey = $this->prefix($key);
        if ($token === null) {
            return (bool) Redis::del($fullKey);
        }

        $script = <<<LUA
if redis.call("get", KEYS[1]) == ARGV[1] then
  return redis.call("del", KEYS[1])
else
  return 0
end
LUA;
        $result = Redis::eval($script, 1, $fullKey, $token);
        return (int) $result === 1;
    }

    public function isLocked(string $key): bool
    {
        return (bool) Redis::exists($this->prefix($key));
    }

    public function ttl(string $key): int
    {
        return (int) Redis::ttl($this->prefix($key));
    }

    /**
     * Acquire, run callback, release. Returns the callback return value or null
     * if the lock could not be acquired.
     */
    public function run(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $token = $this->acquire($key, $ttlSeconds);
        if ($token === null) {
            return null;
        }
        try {
            return $callback();
        } finally {
            try {
                $this->release($key, $token);
            } catch (Throwable $e) {
                // best-effort release; TTL will recover
            }
        }
    }

    public function forceRelease(string $key): bool
    {
        return (bool) Redis::del($this->prefix($key));
    }

    protected function prefix(string $key): string
    {
        $prefix = config('cache.prefix', 'khwwc');
        return $prefix . '_lock:' . $key;
    }
}