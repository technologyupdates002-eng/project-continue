<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use App\Services\AuthService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function token(Request $request)
    {
        try {
            $grant = $request->query('grant_type', 'password');

            if ($grant === 'refresh_token') {
                $refreshToken = trim((string) $request->input('refresh_token', ''));
                if ($refreshToken === '') {
                    throw new HttpException(400, 'invalid_request', 'refresh_token is required');
                }

                $row = DB::table('auth_refresh_tokens')
                    ->where('token', $refreshToken)
                    ->where('revoked', 0)
                    ->where('expires_at', '>', now()->toDateTimeString())
                    ->first();

                if (! $row) {
                    throw new HttpException(401, 'invalid_grant', 'Refresh token is invalid or expired');
                }

                DB::table('auth_refresh_tokens')->where('id', $row->id)->update(['revoked' => 1]);
                $user = DB::table('auth_users')->where('id', $row->user_id)->first();
                if (! $user) {
                    throw new HttpException(401, 'invalid_grant', 'Account no longer exists');
                }

                $roles = AuthService::rolesFor($user->id);

                $this->recordSessionRefresh($user, $request, $roles);

                return response()->json(AuthService::issueSession((array) $user, $roles));
            }

            $email = trim((string) $request->input('email', ''));
            $phone = trim((string) $request->input('phone', ''));
            $password = (string) $request->input('password', '');

            if ($email === '' && $phone !== '') {
                $email = AuthService::emailForPhone($phone);
            }

            if ($email === '' || $password === '') {
                throw new HttpException(400, 'invalid_request', 'Phone/email and password are required');
            }

            $emailLower = strtolower($email);
            $normalizedPhone = $phone !== '' ? AuthService::normalizePhone($phone) : null;
            $welfareLocalPhone = str_ends_with($email, '@welfare.local') ? '+'.str_replace('@welfare.local', '', $email) : null;

            $user = DB::table('auth_users')
                ->where('email', $emailLower)
                ->when($normalizedPhone, fn ($q) => $q->orWhere('phone', $normalizedPhone))
                ->when($welfareLocalPhone, fn ($q) => $q->orWhere('phone', $welfareLocalPhone))
                ->first();

            if (! $user) {
                $this->recordFailedLogin($email, $phone, $request, 'Invalid login credentials');
                throw new HttpException(400, 'invalid_credentials', 'Invalid login credentials');
            }

            if (! AuthService::verifyPassword($password, (string) $user->encrypted_password)) {
                $this->recordFailedLogin($user->email, $user->phone, $request, 'Invalid login credentials');
                throw new HttpException(400, 'invalid_credentials', 'Invalid login credentials');
            }

            if ((int) $user->is_banned === 1) {
                $this->recordFailedLogin($user->email, $user->phone, $request, 'User is banned');
                throw new HttpException(403, 'user_banned', 'This account has been deactivated');
            }

            $roles = AuthService::rolesFor($user->id);

            $this->recordLoginActivity($user, $request, true, $roles);

            return response()->json(AuthService::issueSession((array) $user, $roles));
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new HttpException(401, 'invalid_credentials', 'Invalid login credentials');
        }
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! $identity || ! $identity['user_id']) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $isActive = (bool) $request->input('activity', false);
        $now = now();

        if ($isActive) {
            DB::table('auth_users')
                ->where('id', $identity['user_id'])
                ->update(['last_seen_at' => $now]);
        }

        $this->updateUserPresence($identity['user_id'], true, $now);

        return response()->json(['success' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        DB::table('auth_refresh_tokens')->where('user_id', $identity['user_id'])->update(['revoked' => 1]);

        DB::table('auth_users')
            ->where('id', $identity['user_id'])
            ->update(['last_seen_at' => null]);

        $this->updateUserPresence($identity['user_id'], false);

        $this->markLastLoginActivity($identity['user_id']);

        return response()->json([], 204);
    }

    public function resetDashboardPin(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $currentPassword = (string) $request->input('current_password', '');
        $pin = (string) $request->input('pin', '');

        if ($currentPassword === '' || ! preg_match('/^\d{4}$/', $pin)) {
            throw new HttpException(422, 'invalid_request', 'Current password and a 4-digit PIN are required.');
        }

        $user = DB::table('auth_users')->where('id', $identity['user_id'])->first();
        if (! $user || ! AuthService::verifyPassword($currentPassword, (string) $user->encrypted_password)) {
            throw new HttpException(403, 'invalid_password', 'Current password is incorrect.');
        }

        $pinHash = hash('sha256', $identity['user_id'].':'.$pin);
        DB::table('dashboard_security')->updateOrInsert(
            ['user_id' => $identity['user_id']],
            ['pin_hash' => $pinHash, 'updated_at' => now()]
        );

        return response()->json(['message' => 'Dashboard PIN reset successfully.']);
    }

    private function recordLoginActivity(object $user, Request $request, bool $success, array $roles = []): void
    {
        $role = $roles !== [] ? AuthService::primaryRole($roles) : ($user->role ?? null);

        $now = now()->toDateTimeString();

        DB::table('auth_users')
            ->where('id', $user->id)
            ->update([
                'last_sign_in_at' => $now,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

        $this->updateUserPresence($user->id, true);

        DB::table('login_activity')->insert([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'role' => $role,
            'ip_address' => $this->getIpAddress($request),
            'user_agent' => $request->userAgent() ?? '',
            'event_type' => $success ? 'login' : 'login_failed',
            'success' => $success ? 1 : 0,
            'created_at' => $now,
        ]);
    }

    private function recordFailedLogin(?string $email, ?string $phone, Request $request, string $reason): void
    {
        DB::table('login_activity')->insert([
            'id' => Uuid::v4(),
            'user_id' => null,
            'email' => $email,
            'phone' => $phone ? AuthService::normalizePhone($phone) : null,
            'role' => null,
            'ip_address' => $this->getIpAddress($request),
            'user_agent' => $request->userAgent() ?? '',
            'event_type' => 'login_failed',
            'success' => 0,
            'failure_reason' => $reason,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    private function recordSessionRefresh(object $user, Request $request, array $roles): void
    {
        DB::table('login_activity')->insert([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'role' => $roles !== [] ? AuthService::primaryRole($roles) : ($user->role ?? null),
            'ip_address' => $this->getIpAddress($request),
            'user_agent' => $request->userAgent() ?? '',
            'event_type' => 'token_refresh',
            'success' => 1,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    private function markLastLoginActivity(string $userId): void
    {
        $latest = DB::table('login_activity')
            ->where('user_id', $userId)
            ->where('event_type', 'login')
            ->where('success', 1)
            ->where('logged_out_at', null)
            ->orderByDesc('created_at')
            ->first();

        if ($latest) {
            DB::table('login_activity')
                ->where('id', $latest->id)
                ->update(['logged_out_at' => now()->toDateTimeString()]);
        }
    }

    private function updateUserPresence(string $userId, bool $online, ?Carbon $lastSeen = null): void
    {
        $lastSeen ??= now();
        $now = $lastSeen->toDateTimeString();

        DB::table('user_presence')->insertOrIgnore([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'is_online' => $online ? 1 : 0,
            'last_seen' => $now,
        ]);

        DB::table('user_presence')->where('user_id', $userId)->update([
            'is_online' => $online ? 1 : 0,
            'last_seen' => $now,
        ]);
    }

    private function getIpAddress(Request $request): ?string
    {
        $ip = $request->ip();
        if ($ip) {
            return $ip;
        }

        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded) {
            return trim(explode(',', $forwarded)[0]);
        }

        return $request->getClientIp();
    }

    public function signup(Request $request)
    {
        $identity = $request->attributes->get('identity', null);
        $openRegistration = DB::table('registration_config')->value('active') === 1;

        if (! $identity && ! $openRegistration) {
            throw new HttpException(403, 'forbidden', 'Self registration is currently closed');
        }

        $name = trim((string) $request->input('name', ''));
        $phone = trim((string) $request->input('phone', ''));

        if ($name === '' || $phone === '') {
            throw new HttpException(400, 'invalid_request', 'name and phone are required');
        }

        $phone = AuthService::normalizePhone($phone);
        $email = trim((string) $request->input('email', AuthService::emailForPhone($phone)));
        $password = (string) $request->input('password', 'Member2026');

        if (DB::table('auth_users')->where('email', strtolower($email))->exists()) {
            throw new HttpException(409, 'user_exists', 'An account with this phone number already exists');
        }

        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::transaction(function () use ($userId, $memberId, $name, $phone, $email, $password, $request) {
            DB::table('auth_users')->insert([
                'id' => $userId,
                'email' => strtolower($email),
                'phone' => $phone,
                'encrypted_password' => AuthService::hashPassword($password),
                'raw_user_meta_data' => json_encode(['name' => $name]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('user_roles')->insert([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => 'member',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('members')->insert([
                'id' => $memberId,
                'user_id' => $userId,
                'name' => $name,
                'phone' => $phone,
                'member_id' => $request->input('member_id', null),
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'user' => ['id' => $userId, 'email' => strtolower($email)],
            'member' => ['id' => $memberId, 'name' => $name, 'phone' => $phone],
            'login' => ['phone' => $phone],
        ], 201);
    }

    public function user(Request $request)
    {
        $identity = $request->attributes->get('identity');
        $user = DB::table('auth_users')->where('id', $identity['user_id'])->first();

        $member = DB::table('members')->where('user_id', $identity['user_id'])->first();

        return response()->json([
            'id' => $identity['user_id'],
            'email' => $identity['email'],
            'role' => AuthService::primaryRole($identity['roles']),
            'roles' => $identity['roles'],
            'must_change_password' => (bool) ($user->must_change_password ?? 0),
            'last_login_at' => $user->last_sign_in_at ?? null,
            'last_seen_at' => $user->last_seen_at ?? null,
            'member' => $member ? (array) $member : null,
        ]);
    }

    public function changePassword(Request $request)
    {
        $identity = $request->attributes->get('identity');
        $current = (string) $request->input('current_password', '');
        $next = (string) $request->input('new_password', '');

        if (strlen($next) < 6) {
            throw new HttpException(422, 'weak_password', 'New password must be at least 6 characters');
        }

        $user = DB::table('auth_users')->where('id', $identity['user_id'])->first();
        if (! $user || $current === '' || ! AuthService::verifyPassword($current, (string) $user->encrypted_password)) {
            throw new HttpException(422, 'invalid_credentials', 'Current password is incorrect');
        }

        DB::table('auth_users')->where('id', $identity['user_id'])->update([
            'encrypted_password' => AuthService::hashPassword($next),
            'must_change_password' => 0,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function passwordManagementUsers(Request $request): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin');
        $identity = $request->attributes->get('identity');
        $canManageSuperAdmins = in_array('super_admin', $identity['roles'] ?? [], true);

        $users = DB::table('auth_users')
            ->leftJoin('members', 'members.user_id', '=', 'auth_users.id')
            ->orderBy('auth_users.email')
            ->get([
                'auth_users.id',
                'auth_users.email',
                'auth_users.phone',
                'auth_users.is_banned',
                'auth_users.must_change_password',
                'auth_users.raw_user_meta_data',
                'members.id as member_id',
                'members.name as member_name',
            ])
            ->map(function (object $user): array {
                $roles = AuthService::rolesFor((string) $user->id);
                $metadata = json_decode((string) ($user->raw_user_meta_data ?? '{}'), true);

                return [
                    'id' => (string) $user->id,
                    'user_id' => (string) $user->id,
                    'member_id' => $user->member_id,
                    'name' => $user->member_name ?: ($metadata['name'] ?? $user->email),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'roles' => $roles,
                    'role' => AuthService::primaryRole($roles),
                    'is_active' => (int) $user->is_banned !== 1,
                    'must_change_password' => (bool) $user->must_change_password,
                ];
            })
            ->when(! $canManageSuperAdmins, function ($users) {
                return $users->reject(fn (array $user): bool => in_array('super_admin', $user['roles'], true));
            })
            ->values();

        return response()->json($users);
    }
}
