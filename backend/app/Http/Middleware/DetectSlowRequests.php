<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DetectSlowRequests
{
    protected array $slowThresholds = [
        'GET' => 500,
        'POST' => 1000,
        'PUT' => 1000,
        'PATCH' => 1000,
        'DELETE' => 1000,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = (microtime(true) - $start) * 1000;

        $method = $request->getMethod();
        $threshold = $this->slowThresholds[$method] ?? 1000;

        if ($duration > $threshold) {
            $status = $response->getStatusCode();
            Log::channel('slow_queries')->info('Slow API request detected', [
                'method' => $method,
                'url' => $request->fullUrl(),
                'duration_ms' => round($duration, 2),
                'status' => $status,
                'user_id' => $request->attributes->get('identity')['user_id'] ?? null,
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
