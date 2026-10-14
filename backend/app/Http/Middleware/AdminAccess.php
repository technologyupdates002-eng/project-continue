<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the Super Admin web area.
 *
 * Reuses the application's existing JWT identity (AuthService) — the web
 * login issues a JWT that is stored in the session, and this middleware
 * re-validates it on every request and enforces Admin/Super Admin access. No
 * parallel authentication or permission system is introduced.
 */
class AdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->hasSession()
            ? $request->session()->get('admin_jwt')
            : null;

        if (empty($token)) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }

        if (empty($token)) {
            return $this->deny($request, 'Authentication required.');
        }

        try {
            $identity = AuthService::identity($token);
        } catch (\Throwable $e) {
            if ($request->hasSession()) {
                $request->session()->forget('admin_jwt');
            }

            return $this->deny($request, 'Session expired. Please sign in again.');
        }

        if (! AuthService::canAccessSuperAdmin($identity['roles'] ?? [])) {
            abort(403, 'Admin privileges are required to access this area.');
        }

        $request->attributes->set('identity', $identity);
        $request->attributes->set('admin_user_id', $identity['user_id']);
        if ($request->hasSession()) {
            $request->session()->put('admin_email', $identity['email'] ?? null);
        }

        return $next($request);
    }

    protected function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => $message]], 401);
        }

        return redirect()->route('admin.login')->with('error', $message);
    }
}
