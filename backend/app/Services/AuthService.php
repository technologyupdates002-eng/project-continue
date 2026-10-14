<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Identity & authentication service — a 1:1 port of the legacy Auth.php
 * static library. Keeps the same method names and behaviour so downstream
 * controllers, the Policy engine and the Query Builder stay compatible.
 */
class AuthService
{
    public const STAFF_SET = [
        'admin', 'super_admin', 'treasurer', 'chairperson',
        'vice_chairperson', 'secretary', 'vice_secretary', 'patron',
    ];

    /**
     * Normalise a Kenyan phone number to E.164 format (+2547XXXXXXXX).
     */
    public static function normalizePhone(?string $phone): string
    {
        $p = ltrim(preg_replace('/\D/', '', (string) $phone), '+');

        if (str_starts_with($p, '0')) {
            $p = '254'.substr($p, 1);
        } elseif (str_starts_with($p, '254')) {
            // already international
        } elseif (strlen($p) === 9) {
            $p = '254'.$p;
        }

        return '+'.$p;
    }

    /**
     * Derive the synthetic email used by the legacy system for phone logins.
     * Note: intentionally excludes the leading '+'.
     */
    public static function emailForPhone(?string $phone): string
    {
        return ltrim(self::normalizePhone($phone), '+').'@welfare.local';
    }

    /**
     * Resolve the role set for a user, applying the implicit hierarchy:
     *  - super_admin implies admin
     *  - no roles => ['member']
     */
    public static function rolesFor(string $userId): array
    {
        $rows = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->pluck('role')
            ->all();

        $roles = array_values(array_unique($rows));

        if ($roles === []) {
            return ['member'];
        }

        if (in_array('super_admin', $roles, true) && ! in_array('admin', $roles, true)) {
            $roles[] = 'admin';
        }

        return $roles;
    }

    public static function isSuperAdmin(array $roles): bool
    {
        return in_array('super_admin', $roles, true);
    }

    public static function canAccessSuperAdmin(array $roles): bool
    {
        return self::isAdmin($roles);
    }

    public static function isAdmin(array $roles): bool
    {
        return in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
    }

    public static function isStaff(array $roles): bool
    {
        return count(array_intersect($roles, self::STAFF_SET)) > 0;
    }

    public static function hasRole(array $roles, string $role): bool
    {
        return in_array($role, $roles, true);
    }

    /**
     * Require authentication. Throws 401 when not authenticated.
     */
    public static function require(): void
    {
        $token = self::bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'invalid_token', 'Authentication required.');
        }
        self::identity($token);
    }

    /**
     * Require one of the given roles. Throws 403 when not authorized.
     */
    public static function requireRole(string ...$roles): void
    {
        $token = self::bearerToken();
        if ($token === null) {
            throw new HttpException(401, 'invalid_token', 'Authentication required.');
        }
        $identity = self::identity($token);
        if (count(array_intersect($identity['roles'], $roles)) === 0) {
            throw new HttpException(403, 'insufficient_permissions', 'Insufficient permissions.');
        }
    }

    private static function bearerToken(): ?string
    {
        $header = '';

        if (function_exists('app') && app()->bound('request')) {
            $request = app('request');
            $header = (string) ($request->header('Authorization') ?? '');
        }

        if ($header === '') {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } elseif (isset($_SERVER['Authorization'])) {
                $header = (string) $_SERVER['Authorization'];
            } elseif (function_exists('getallheaders')) {
                foreach (getallheaders() as $key => $value) {
                    if (strtolower($key) === 'authorization') {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }

        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if ($header === '' || stripos($header, 'bearer ') !== 0) {
            return null;
        }

        return trim(substr($header, 7)) ?: null;
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    /**
     * Issue a session: JWT access token + rotating refresh token.
     */
    public static function issueSession(array $user, array $roles = []): array
    {
        $userId = $user['id'];
        $email = $user['email'];
        $roles = $roles !== [] ? $roles : self::rolesFor($userId);
        $primaryRole = self::primaryRole($roles);
        $mustChangePassword = ! empty($user['must_change_password']);

        $accessToken = JwtService::encode(
            JwtService::buildPayload($userId, $email, $roles, null, $mustChangePassword)
        );

        $refreshToken = bin2hex(random_bytes(32));
        $refreshTtl = (int) config('jwt.refresh_ttl', 2592000);

        DB::table('auth_refresh_tokens')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'token' => $refreshToken,
            'revoked' => 0,
            'expires_at' => now()->addSeconds($refreshTtl),
            'created_at' => now(),
        ]);

        $member = DB::table('members')
            ->where('user_id', $userId)
            ->select('id', 'name', 'phone', 'status', 'is_active')
            ->first();

        return [
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl', 3600),
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $userId,
                'email' => $email,
                'role' => $primaryRole,
                'roles' => $roles,
                'must_change_password' => $mustChangePassword,
                'member' => $member ? [
                    'id' => $member->id,
                    'name' => $member->name,
                    'phone' => $member->phone,
                    'status' => $member->status,
                    'is_active' => (bool) $member->is_active,
                ] : null,
            ],
        ];
    }

    public static function primaryRole(array $roles): string
    {
        foreach ([
            'super_admin',
            'admin',
            'chairperson',
            'vice_chairperson',
            'secretary',
            'vice_secretary',
            'treasurer',
            'patron',
            'member',
        ] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return 'member';
    }

    /**
     * Resolve an identity from a Bearer token (legacy-compatible).
     *
     * @return array{user_id:string,email:string,roles:array,member_id:?string}
     */
    public static function identity(string $token): array
    {
        $payload = JwtService::decode($token);

        $userId = $payload['sub'] ?? null;
        $email = $payload['email'] ?? null;
        $roles = $payload['roles'] ?? null;

        if (! $userId || ! $email) {
            throw new RuntimeException('Malformed token payload.');
        }

        if ($roles === null) {
            $roles = self::rolesFor($userId);
        }

        $memberId = null;

        try {
            $member = DB::table('members')->where('user_id', $userId)->select('id')->first();
            $memberId = $member?->id;
        } catch (\Throwable) {
            // JWT validation must not depend on a live members table to authorize a
            // request whose role claims are already present and verified.
            $memberId = null;
        }

        return [
            'user_id' => $userId,
            'email' => $email,
            'roles' => $roles,
            'member_id' => $memberId,
        ];
    }

    /**
     * Merge an authorized view-as-member context without replacing the
     * authenticated user's identity or roles.
     */
    public static function mergeViewContext(array $identity, ?array $context): array
    {
        if ($context === null || $context === []) {
            return $identity;
        }

        $adminUserId = (string) ($context['admin_user_id'] ?? '');
        $userId = (string) ($identity['user_id'] ?? '');
        $memberId = (string) ($context['member_id'] ?? '');

        if ($adminUserId === '' || $memberId === '' || $adminUserId !== $userId) {
            return $identity;
        }

        return array_merge($identity, [
            'is_viewing_member' => true,
            'viewing_member_id' => $memberId,
            'view_as_member_session_id' => $context['session_id'] ?? null,
            'acting_admin_user_id' => $userId,
        ]);
    }

    /**
     * Return the member ID whose data should be scoped for this identity.
     */
    public static function effectiveMemberId(array $identity): ?string
    {
        if (! empty($identity['is_viewing_member']) && ! empty($identity['viewing_member_id'])) {
            return (string) $identity['viewing_member_id'];
        }

        return isset($identity['member_id']) ? (string) $identity['member_id'] : null;
    }

    /**
     * Look up a user by email or synthetic phone-derived email.
     */
    public static function userByEmail(string $email): ?object
    {
        return DB::table('auth_users')
            ->where('email', strtolower($email))
            ->first();
    }

    /**
     * Resolve the login input accepted by the admin form.
     * Supports an email, the synthetic welfare email, or a phone number.
     */
    public static function resolveLoginIdentity(string $input): ?object
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (str_contains($input, '@')) {
            return self::userByEmail($input);
        }

        return self::userByEmail(self::emailForPhone($input));
    }
}
