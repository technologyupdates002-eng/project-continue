<?php

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the bearer JWT and attaches the identity to the request.
 * Throws a JSON 401 (invalid_token) when missing or invalid.
 *
 * Also enforces idle timeout based on auth_users.last_seen_at.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (empty($token)) {
            throw new HttpException(401, 'invalid_token', 'Missing authentication token.');
        }

        try {
            $identity = AuthService::identity($token);
        } catch (\Throwable $e) {
            throw new HttpException(401, 'invalid_token', 'Invalid or expired authentication token.');
        }

        $userId = $identity['user_id'] ?? null;

        if ($userId) {
            $lastSeen = DB::table('auth_users')
                ->where('id', $userId)
                ->value('last_seen_at');

            if ($lastSeen) {
                $lastSeenTs = (new \DateTime($lastSeen))->getTimestamp();
                $nowTs = time();
                $idleMinutes = ($nowTs - $lastSeenTs) / 60;

                $idleTimeout = (int) config('jwt.idle_timeout_minutes', 10);

                if ($idleMinutes > $idleTimeout) {
                    throw new HttpException(401, 'session_idle_timeout', 'Session expired due to inactivity.');
                }
            }
        }

        $request->attributes->set('identity', $identity);

        return $next($request);
    }
}
