<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

class SystemHealthService
{
    public function overview(): array
    {
        return [
            'application' => $this->application(),
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
            'storage' => $this->storage(),
            'scheduler' => $this->scheduler(),
            'cache' => $this->cache(),
        ];
    }

    public function application(): array
    {
        return [
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'environment' => app()->environment(),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'debug' => (bool) config('app.debug'),
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'session_driver' => config('session.driver'),
        ];
    }

    public function database(): array
    {
        $payload = [
            'status' => 'unavailable',
            'connection' => config('database.default'),
            'database' => config('database.connections.mysql.database'),
            'latency_ms' => null,
            'table_count' => 0,
        ];

        try {
            $start = microtime(true);
            DB::connection()->getPdo();
            $payload['latency_ms'] = round((microtime(true) - $start) * 1000, 2);

            $tables = DB::select('SHOW TABLES');
            $payload['table_count'] = count($tables);
            $payload['database'] = DB::connection()->getDatabaseName();
            $payload['status'] = 'healthy';

            return $payload;
        } catch (\Throwable $e) {
            $payload['status'] = 'failed';
            $payload['error'] = $e->getMessage();

            return $payload;
        }
    }

    public function redis(): array
    {
        if (! RedisHealthService::isAvailable()) {
            return [
                'status' => 'unavailable',
                'note' => 'Redis not installed; using file cache. Cache test still works via the configured driver.',
                'cache_store' => config('cache.default'),
            ];
        }
        try {
            $start = microtime(true);
            Redis::ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            $info = Redis::info();

            return [
                'status' => 'healthy',
                'latency_ms' => $latency,
                'version' => $info['redis_version'] ?? 'unknown',
                'memory_used' => $info['used_memory_human'] ?? 'unknown',
                'memory_peak' => $info['used_memory_peak_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
                'uptime_in_seconds' => $info['uptime_in_seconds'] ?? 'unknown',
                'cache_test' => Cache::put('khwwc:health:test', true, 10) && Cache::get('khwwc:health:test') === true,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function queue(): array
    {
        try {
            $connection = config('queue.default');

            return [
                'status' => 'configured',
                'connection' => $connection,
                'driver' => config("queue.connections.{$connection}.driver", 'unknown'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function storage(): array
    {
        try {
            $writable = is_writable(storage_path()) && Storage::disk('local')->exists('.');

            return [
                'status' => $writable ? 'healthy' : 'error',
                'writable' => $writable,
                'path' => storage_path(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function cache(): array
    {
        try {
            $start = microtime(true);
            Cache::put('khwwc:health:cache_test', true, 10);
            $result = Cache::get('khwwc:health:cache_test') === true;
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status' => $result ? 'healthy' : 'failed',
                'store' => config('cache.default'),
                'latency_ms' => $latency,
                'test_passed' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    public function scheduler(): array
    {
        try {
            $events = Schedule::events();
            $tasks = [];

            foreach ($events as $event) {
                $tasks[] = [
                    'command' => $event->command ?: class_basename($event->callback),
                    'expression' => $event->expression,
                    'description' => method_exists($event, 'description') ? $event->description : '',
                ];
            }

            return [
                'status' => 'configured',
                'task_count' => count($tasks),
                'tasks' => $tasks,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
