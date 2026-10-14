<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects already-authenticated Super Admins away from the login page.
 */
class AdminGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->session()->get('admin_jwt');

        if (! empty($token)) {
            try {
                $identity = AuthService::identity($token);
                if (AuthService::canAccessSuperAdmin($identity['roles'] ?? [])) {
                    return redirect()->route('admin.dashboard');
                }
            } catch (\Throwable) {
                $request->session()->forget('admin_jwt');
            }
        }

        return $next($request);
    }
}
