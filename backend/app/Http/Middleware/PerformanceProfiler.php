<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class PerformanceProfiler
{
    public function handle(Request $request, Closure $next): Response
    {
        // only enable in local or when X-PERF header present
        $enable = config('app.env') === 'local' || $request->header('X-PERF') === '1';

        $start = microtime(true);

        if ($enable) {
            DB::enableQueryLog();
        }

        $redisPingMs = null;
        try {
            $redisStart = microtime(true);
            $pong = Redis::ping();
            $redisPingMs = (microtime(true) - $redisStart) * 1000;
        } catch (\Throwable $e) {
            // ignore
            $redisPingMs = null;
        }

        $response = $next($request);

        $duration = (microtime(true) - $start) * 1000;

        $dbQueries = 0;
        $dbTime = 0.0;
        if ($enable) {
            $queries = DB::getQueryLog();
            $dbQueries = count($queries);
            foreach ($queries as $q) {
                $dbTime += isset($q['time']) ? (float)$q['time'] : 0.0;
            }
        }

        $queryList = [];
        if ($enable) {
            foreach ($queries as $q) {
                $queryList[] = [
                    'sql' => $q['query'] ?? null,
                    'time_ms' => isset($q['time']) ? (float)$q['time'] : null,
                    'bindings' => $q['bindings'] ?? [],
                ];
            }
        }

        $data = [
            'method' => $request->getMethod(),
            'url' => $request->fullUrl(),
            'duration_ms' => round($duration, 2),
            'db_query_count' => $dbQueries,
            'db_time_ms' => round($dbTime, 2),
            'redis_ping_ms' => $redisPingMs !== null ? round($redisPingMs, 2) : null,
            'status' => $response->getStatusCode(),
            'user_id' => $request->attributes->get('identity')['user_id'] ?? null,
            'ip' => $request->ip(),
            'queries' => $queryList,
        ];

        Log::channel('request_profile')->info('Request profile', $data);

        return $response;
    }
}
