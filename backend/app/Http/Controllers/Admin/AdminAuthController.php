<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $password = (string) $request->input('password');

        $user = AuthService::userByEmail($email);

        if (! $user || ! AuthService::verifyPassword($password, $user->encrypted_password ?? '')) {
            return redirect()->route('admin.login')
                ->with('error', 'Invalid credentials.')
                ->withInput(['email' => $email]);
        }

        if (! empty($user->is_banned)) {
            return redirect()->route('admin.login')
                ->with('error', 'This account is banned.')
                ->withInput(['email' => $email]);
        }

        $roles = AuthService::rolesFor($user->id);
        if (! AuthService::canAccessSuperAdmin($roles)) {
            return redirect()->route('admin.login')
                ->with('error', 'Admin privileges are required.')
                ->withInput(['email' => $email]);
        }

        $token = JwtService::encode(
            JwtService::buildPayload($user->id, $user->email, $roles)
        );

        $request->session()->regenerate();
        $request->session()->put('admin_jwt', $token);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_jwt');
        $request->session()->regenerate();

        return redirect()->route('admin.login')->with('status', 'Signed out.');
    }

    protected function userById(string $id): ?object
    {
        return DB::table('auth_users')->where('id', $id)->first();
    }
}
