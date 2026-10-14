<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/super-admin/* — system oversight screens.
 */
class SuperAdminPortalController extends PortalController
{
    private function guard(Request $request): array
    {
        return $this->requireAnyRole($request, ['super_admin']);
    }

    private function onlineCount(): int
    {
        return DB::table('user_presence')->where('is_online', 1)->where('last_seen', '>=', now()->subMinutes(5))->count();
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'total_users' => DB::table('auth_users')->count(),
            'online_users' => $this->onlineCount(),
            'total_members' => DB::table('members')->count(),
            'system_health' => $this->metrics(),
            'recent_logins' => DB::table('auth_users')->whereNotNull('last_sign_in_at')->orderByDesc('last_sign_in_at')->limit(10)
                ->get(['id', 'email', 'last_sign_in_at as date']),
            'pending_alerts' => DB::table('system_logs')->where('resolved', 0)->whereIn('log_level', ['error', 'critical'])->count(),
        ]);
    }

    private function metrics(): array
    {
        $dbOk = true;
        $start = microtime(true);
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $dbMs = round((microtime(true) - $start) * 1000, 1);
        $failed = 0;
        try {
            $failed = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
        }
        $queued = 0;
        try {
            $queued = DB::table('jobs')->count();
        } catch (\Throwable) {
        }

        return [
            ['name' => 'Database', 'value' => $dbMs.' ms', 'status' => $dbOk ? 'healthy' : 'critical'],
            ['name' => 'Queue backlog', 'value' => (string) $queued, 'status' => $queued > 500 ? 'warning' : 'healthy'],
            ['name' => 'Failed jobs', 'value' => (string) $failed, 'status' => $failed > 0 ? 'warning' : 'healthy'],
            ['name' => 'Cache', 'value' => config('cache.default'), 'status' => 'healthy'],
            ['name' => 'PHP', 'value' => PHP_VERSION, 'status' => 'healthy'],
        ];
    }

    public function member(Request $request, string $id): JsonResponse
    {
        $this->guard($request);
        $m = DB::table('members as m')->leftJoin('auth_users as u', 'u.id', '=', 'm.user_id')->select('m.*', 'u.email', 'u.last_sign_in_at')->where('m.id', $id)->first();
        if (! $m) {
            throw new HttpException(404, 'not_found', 'Member not found.');
        }
        DB::table('member_access_logs')->insert(['id' => $this->uuid(), 'super_admin_id' => $this->identity($request)['user_id'], 'member_id' => $id, 'access_type' => 'view', 'reason' => 'super-admin profile view', 'created_at' => now()]);

        return response()->json((array) $m + ['roles' => DB::table('user_roles')->where('user_id', $m->user_id)->pluck('role')]);
    }

    public function troubleshooting(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'issues' => DB::table('system_logs')->where('resolved', 0)->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($l) => ['id' => $l->id, 'severity' => $l->log_level, 'module' => $l->component, 'description' => $l->message, 'date' => (string) $l->created_at]),
            'diagnostics' => $this->metrics(),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->paginate(DB::table('audit_logs')->orderByDesc('created_at'), $request, 50));
    }

    public function security(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json(Cache::get('khwwc:security_settings', [
            'two_factor' => false,
            'password_policy' => ['min_length' => 8, 'require_special' => false, 'require_numbers' => true],
            'session_timeout' => (int) config('jwt.idle_timeout_minutes', 10),
            'ip_whitelist' => [],
        ]));
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'two_factor' => 'sometimes|boolean', 'password_policy' => 'sometimes|array',
            'session_timeout' => 'sometimes|integer|min:1|max:240', 'ip_whitelist' => 'sometimes|array',
        ]);
        $current = $this->security($request)->getData(true);
        Cache::forever('khwwc:security_settings', array_merge($current, $data));
        $this->audit($request, 'security_settings_updated', $data);

        return response()->json($this->ok('Security settings saved.'));
    }

    public function passwordManagement(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'policies' => [
                ['name' => 'Minimum length', 'value' => '8', 'status' => 'healthy'],
                ['name' => 'Users must change password', 'value' => (string) DB::table('auth_users')->where('must_change_password', 1)->count(), 'status' => 'healthy'],
            ],
            'expired_passwords' => DB::table('auth_users')->where('must_change_password', 1)->count(),
            'forced_resets' => DB::table('password_resets')->where('created_at', '>=', now()->subDays(30))->count(),
        ]);
    }

    public function accessControl(Request $request): JsonResponse
    {
        $this->guard($request);
        $perms = Cache::get('khwwc:role_permissions', []);

        return response()->json(DB::table('user_roles')->where('is_active', 1)->selectRaw('role, COUNT(*) as users')->groupBy('role')->get()
            ->map(fn ($r) => ['id' => $r->role, 'name' => ucwords(str_replace('_', ' ', $r->role)), 'users' => (int) $r->users, 'permissions' => $perms[$r->role] ?? []]));
    }

    public function updateAccess(Request $request, string $role): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['permissions' => 'required|array', 'permissions.*' => 'string|max:80']);
        $perms = Cache::get('khwwc:role_permissions', []);
        $perms[$role] = $data['permissions'];
        Cache::forever('khwwc:role_permissions', $perms);
        $this->audit($request, 'role_permissions_updated', ['role' => $role]);

        return response()->json($this->ok('Permissions saved.'));
    }

    public function onlineUsers(Request $request): JsonResponse
    {
        $this->guard($request);

        return app(\App\Http\Controllers\Api\PresenceController::class)->onlineUsers($request);
    }

    public function loginActivity(Request $request): JsonResponse
    {
        $this->guard($request);

        return app(\App\Http\Controllers\Api\PresenceController::class)->loginActivity($request);
    }

    public function monitoring(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'metrics' => $this->metrics(),
            'services' => [
                ['name' => 'API', 'status' => 'operational', 'uptime' => '—'],
                ['name' => 'Queue worker', 'status' => DB::table('system_heartbeats')->where('updated_at', '>=', now()->subMinutes(5))->exists() ? 'operational' : 'unknown', 'uptime' => '—'],
                ['name' => 'Co-op Bank', 'status' => DB::table('coop_tokens')->where('expires_at', '>', now())->exists() ? 'operational' : 'idle', 'uptime' => '—'],
            ],
        ]);
    }

    public function bank(Request $request): JsonResponse
    {
        $this->guard($request);
        $last = DB::table('bank_sync_audit')->orderByDesc('started_at')->first();

        return response()->json([
            'connected' => DB::table('coop_tokens')->where('expires_at', '>', now())->exists(),
            'last_sync' => (string) ($last->completed_at ?? ''),
            'accounts' => [[
                'name' => (string) config('coop_bank.account_name', 'KHWWC'),
                'number' => '••••'.substr((string) config('coop_bank.account_number', ''), -4),
                'balance' => (float) ($last->bank_balance ?? 0),
                'status' => (string) ($last->status ?? 'unknown'),
            ]],
        ]);
    }

    public function database(Request $request): JsonResponse
    {
        $this->guard($request);
        $db = DB::getDatabaseName();
        $size = DB::selectOne('SELECT ROUND(SUM(data_length + index_length)/1024/1024, 1) AS mb, COUNT(*) AS t FROM information_schema.tables WHERE table_schema = ?', [$db]);
        $conn = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
        $slow = DB::selectOne("SHOW GLOBAL STATUS LIKE 'Slow_queries'");

        return response()->json([
            'size' => ($size->mb ?? 0).' MB', 'tables' => (int) ($size->t ?? 0),
            'connections' => (int) ($conn->Value ?? 0), 'slow_queries' => (int) ($slow->Value ?? 0),
            'backup_last' => (string) (DB::table('backups')->orderByDesc('created_at')->value('created_at') ?? ''),
        ]);
    }

    public function system(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'version' => 'v1', 'uptime' => '—', 'environment' => app()->environment(),
            'features' => [
                ['name' => 'Co-op Bank STK', 'enabled' => (bool) config('coop_bank.account_number')],
                ['name' => 'SMS', 'enabled' => app(\App\Services\SmsService::class)->isConfigured()],
                ['name' => 'Queue', 'enabled' => config('queue.default') !== 'sync'],
            ],
        ]);
    }

    public function trace(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'traces' => DB::table('system_logs')->whereNotNull('request_path')->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($l) => ['id' => $l->id, 'request' => $l->request_path, 'duration' => '—', 'status' => (int) $l->status_code, 'date' => (string) $l->created_at]),
        ]);
    }
}
