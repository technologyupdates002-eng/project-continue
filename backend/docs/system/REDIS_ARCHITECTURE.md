# Redis Architecture — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Redis Installation Status

| Component | Status | Version |
|-----------|--------|---------|
| Redis server | **Installed but NOT running** | v8.0.6 |
| Redis CLI | Installed | v8.0.6 |
| PHP redis extension | **NOT installed** | — |
| Predis library (`predis/predis`) | Installed (composer dependency) | ^3.6 |

### Verification commands

```bash
$ redis-server --version
Redis server v=8.0.6 sha=00000000:0 malloc=jemalloc-5.3.1 bits=64 build=1db027674eb818db

$ redis-cli --version
Redis server v=8.0.6

$ redis-cli ping
Could not connect to Redis at 127.0.0.1:6379: Connection refused
```

Redis server can be started with:
```bash
redis-server --daemonize yes --port 6379 --bind 127.0.0.1
```

---

## 2. Redis Configuration

### .env configuration

```
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
```

### config/database.php

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', '0'),
    ],
],
```

### config/cache.php

The cache config does **NOT** define a `redis` cache store:

```php
'stores' => [
    'array' => ['driver' => 'array'],
    'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
    'database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null],
],
```

If `CACHE_STORE=redis` were set, Laravel would attempt to use a non-existent
`redis` cache store configuration and fail.

### config/queue.php

The queue config does **NOT** define a `redis` queue connection:

```php
'connections' => [
    'sync' => ['driver' => 'sync'],
    'database' => ['driver' => 'database', 'table' => 'jobs', ...],
    'beanstalkd' => ['driver' => 'beanstalkd', ...],
    'sqs' => ['driver' => 'sqs', ...],
],
```

If `QUEUE_CONNECTION=redis` were set, Laravel would fail because no `redis`
queue connection is configured.

### config/session.php

The session driver is `file`, not `redis`. No `SESSION_CONNECTION` is set.

---

## 3. Is Redis Actually Used?

**NO.** Redis is configured in `.env` but is **not used** by the application.

### Intended Redis usage vs. actual usage

| Intended use | Configured in .env | Actually used? | Current driver |
|-------------|-------------------|----------------|----------------|
| Cache | `REDIS_CACHE_DB=1` | No — cache is `file` | `file` |
| Queue | `REDIS_QUEUE_DB=2` | No — queue is `sync` | `sync` |
| Sessions | — | No — session is `file` | `file` |
| Rate limiting | — | No | N/A |
| Locks | — | No — `Cache::lock()` uses file cache | `file` |
| Broadcasting | — | No — `BROADCAST_CONNECTION=log` | `log` |
| Other | — | No | N/A |

### Evidence in code

1. `config/app.php` registers `Illuminate\Redis\RedisServiceProvider` — this
   makes the `Redis` facade available, but the application only uses it in
   one place: `HealthController::redisStatus()` which calls `Redis::ping()`.

2. `HealthController::systemInfo()` and `HealthController::redisStatus()`
   are the **only** code that interacts with Redis.

3. The backup system uses `Cache::lock()` in `RestoreService` — this uses
   the file cache driver (since `CACHE_STORE=file`), not Redis.

4. No `Redis::command()`, `Redis::get()`, `Redis::set()`, or any other Redis
   operations exist in the application code.

---

## 4. Redis Health Check Location

The only Redis health check in the codebase is in:
`app/Http/Controllers/HealthController.php:65-74`

```php
protected function redisStatus(): string
{
    try {
        Redis::ping();
        return 'connected';
    } catch (\Throwable $e) {
        return 'error: '.$e->getMessage();
    }
}
```

This is exposed via `GET /api/system/info` (unauthenticated). Since Redis is
not running, this will return `"error: Connection refused"`.

---

## 5. Redis Architecture for Security Centre

### 5.1 Current state for Super Admin monitoring

Since Redis is **not running and not used**, the Security Centre should report:

```
Redis: ● Unavailable (not running)
```

The application has a fallback mechanism — when Redis is unavailable, it falls
back to file-based cache/session/queue. This is accurate because the
application does not actually depend on Redis.

### 5.2 If Redis is enabled in the future

If Redis is started and the cache/queue/session drivers are switched to
Redis, the Security Centre should monitor:

| Metric | How to obtain |
|--------|---------------|
| Redis availability | `Redis::ping()` |
| Memory usage | `Redis::info('memory')['used_memory_human']` |
| Memory limit | `Redis::config('GET', 'maxmemory')` |
| Connected clients | `Redis::info('clients')['connected_clients']` |
| Commands/sec | `Redis::info('stats')['instantaneous_ops_per_sec']` |
| Key count (cache DB) | `Redis::dbSize()` (for DB 0/1) — safe to expose |
| Queue-related usage | Check `jobs` table count (not Redis) |

### 5.3 Redis safety

The Security Centre must **NOT** provide:

- FLUSHALL / FLUSHDB buttons
- Arbitrary Redis command execution
- Raw access to Redis keys (may contain sensitive session/cache data)
- Ability to view Redis configuration or credentials

Cache clearing should use Laravel's `cache:clear` Artisan command.
Queue management should use Laravel's queue commands (`queue:work`,
`queue:retry`, `queue:flush`).

---

## 6. Recommendations

### 6.1 Immediate (for Security Centre)

1. Start Redis server if the infrastructure requires it, or document that
   Redis is optional in the Security Centre UI.
2. Add a `redis` cache store to `config/cache.php` if `CACHE_STORE=redis`
   is intended for production:
   ```php
   'redis' => [
       'driver' => 'redis',
       'connection' => 'default',
   ],
   ```
3. Add a `redis` queue connection to `config/queue.php` if
   `QUEUE_CONNECTION=redis` is intended for production:
   ```php
   'redis' => [
       'driver' => 'redis',
       'connection' => 'default',
       'queue' => env('REDIS_QUEUE', 'default'),
       'retry_after' => 90,
       'block_for' => null,
   ],
   ```

### 6.2 Security Centre monitoring

The Security Centre should show Redis status as:

- **Healthy** — if `Redis::ping()` succeeds
- **Degraded** — if Redis is reachable but not used for cache/queue/session
- **Unavailable** — if Redis is not running

Never falsely report "healthy" when Redis is not running.
