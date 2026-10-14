<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheManagementService
{
    public function overview(): array
    {
        return [
            'default' => config('cache.default'),
            'stores' => array_keys(config('cache.stores', [])),
        ];
    }

    public function clear(): array
    {
        try {
            Cache::flush();

            return [
                'success' => true,
                'message' => 'Application cache cleared.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function clearRedis(): array
    {
        try {
            Redis::flushDb();

            return [
                'success' => true,
                'message' => 'Redis database flushed.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function stats(): array
    {
        try {
            $info = Redis::info();

            return [
                'memory_used' => $info['used_memory_human'] ?? 'unknown',
                'memory_peak' => $info['used_memory_peak_human'] ?? 'unknown',
                'connected_clients' => $info['connected_clients'] ?? 'unknown',
                'total_keys' => Redis::dbsize(),
                'hit_rate' => $this->hitRate($info),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function hitRate(array $info): ?string
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        if ($total === 0) {
            return null;
        }

        return round(($hits / $total) * 100, 2) . '%';
    }
}
