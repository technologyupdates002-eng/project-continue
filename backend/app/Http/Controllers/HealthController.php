<?php

namespace App\Http\Controllers;

use App\Services\RedisHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * Lightweight health check — mirrors the legacy GET /api/health.
     * No authentication required.
     */
    public function health()
    {
        $database = 'connected';
        $dbError = null;

        try {
            DB::connection()->getPdo();
            DB::table('members')->exists();
        } catch (\Throwable $e) {
            $database = 'error';
            $dbError = $e->getMessage();
        }

        $status = $database === 'connected' ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'service' => 'khcww-welfare-api',
            'database' => $database,
            'db_error' => $dbError,
            'time' => now()->toIso8601String(),
        ], $status === 'ok' ? 200 : 503);
    }

    /**
     * System information probe used by the dashboard and monitoring.
     */
    public function systemInfo()
    {
        $checks = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database' => DB::connection()->getDatabaseName(),
            'cache_store' => config('cache.default'),
            'queue_connection' => config('queue.default'),
            'session_driver' => config('session.driver'),
            'storage_writable' => is_writable(storage_path()) && Storage::disk('local')->exists('.'),
            'redis' => $this->redisStatus(),
            'redis_health' => $this->redisHealthDetails(),
            'queue_health' => $this->queueHealthDetails(),
        ];

        return response()->json([
            'status' => 'ok',
            'service' => 'khcww-welfare-api',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ]);
    }

    protected function redisStatus(): string
    {
        return RedisHealthService::isAvailable() ? 'connected' : 'unavailable';
    }

    /**
     * Real Redis health: actual SET/GET/TIME probe + memory + version + latency.
     * Reports unhealthy if any probe fails, not merely if ping() succeeds.
     */
    protected function redisHealthDetails(): array
    {
        if (! RedisHealthService::isAvailable()) {
            return [
                'status' => 'unavailable',
                'driver' => config('cache.default'),
                'note' => 'Redis not installed/running; using file cache fallback. Performance is unaffected for users.',
            ];
        }

        $start = microtime(true);
        try {
            $probeKey = 'khwwc:health:probe:' . bin2hex(random_bytes(4));
            Redis::set($probeKey, 'ok', 'EX', 5);
            $value = Redis::get($probeKey);
            Redis::del($probeKey);

            if ($value !== 'ok') {
                return ['status' => 'error', 'error' => 'SET/GET round-trip mismatch'];
            }

            $info = Redis::info();
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => 'healthy',
                'latency_ms' => $latencyMs,
                'version' => $info['redis_version'] ?? null,
                'uptime_seconds' => (int) ($info['uptime_in_seconds'] ?? 0),
                'memory_used' => $info['used_memory_human'] ?? null,
                'memory_peak' => $info['used_memory_peak_human'] ?? null,
                'connected_clients' => $info['connected_clients'] ?? null,
                'total_keys' => Redis::dbsize(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Queue health: connection, name, queue size, failed jobs count.
     */
    protected function queueHealthDetails(): array
    {
        try {
            $connection = config('queue.default');
            $queueName = config("queue.connections.{$connection}.queue", 'default');
            $driver = config("queue.connections.{$connection}.driver", 'unknown');

            $size = 0;
            try {
                $size = Queue::size($queueName);
            } catch (\Throwable $e) {
                $size = -1;
            }

            $failedCount = 0;
            try {
                $failedCount = DB::table('failed_jobs')->count();
            } catch (\Throwable $e) {
                $failedCount = -1;
            }

            return [
                'status' => 'configured',
                'connection' => $connection,
                'driver' => $driver,
                'queue' => $queueName,
                'pending_jobs' => $size,
                'failed_jobs' => $failedCount,
                'worker_note' => 'Worker status not inspectable without process control; verify with `ps aux | grep queue:work`',
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }
}
