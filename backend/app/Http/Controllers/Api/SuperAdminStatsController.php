<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\PolicyService;
use App\Services\FinancialReadModel;
use App\Services\RedisHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Authoritative Super Admin system stats.
 *
 * Returns real, server-side data:
 *   - redis:        try a ping (or read from cache) without crashing the app
 *   - queue:        counts of jobs in queue tables
 *   - scheduler:    last heartbeat (we maintain a system_heartbeats table)
 *   - database:     size of primary tables
 *   - backups:      latest backup rows
 *   - audit:        recent audit log entries
 *   - members:      counts (total, active, inactive, executives)
 *   - contributions: totals for the current month
 */
class SuperAdminStatsController extends Controller
{
    public function __construct(private FinancialReadModel $financialReadModel) {}

    private static array $tableExistsCache = [];

    private static function tableExists(string $table): bool
    {
        if (isset(self::$tableExistsCache[$table])) {
            return self::$tableExistsCache[$table];
        }

        $exists = PolicyService::tableExists($table)
            || DB::getSchemaBuilder()->hasTable($table);
        self::$tableExistsCache[$table] = $exists;

        return $exists;
    }
    public function dashboard(Request $request): JsonResponse
    {
        $this->requireSuper($request);

        $stats = Cache::store('file')->remember('super-admin:dashboard', 30, function () {
            return [
                'redis' => $this->redisStatus(),
                'queue' => $this->queueStatus(),
                'scheduler' => $this->schedulerStatus(),
                'database' => $this->databaseStatus(),
                'backups' => $this->backupStatus(),
                'members' => $this->memberCounts(),
                'contributions' => $this->contributionTotals(),
                'audit' => $this->recentAudit(),
                'documents' => $this->documentStatus(),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $stats])
            ->header('Cache-Control', 'private, max-age=15');
    }

    public function health(Request $request): JsonResponse
    {
        $this->requireSuper($request);
        $payload = Cache::store('file')->remember('super-admin:health', 60, function () {
            return [
                'redis' => $this->redisStatus(),
                'queue' => $this->queueStatus(),
                'scheduler' => $this->schedulerStatus(),
                'database' => $this->databaseStatus(),
                'generated_at' => now()->toIso8601String(),
            ];
        });
        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'private, max-age=30');
    }

    private function requireSuper(Request $request): void
    {
        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];
        if (! AuthService::canAccessSuperAdmin($roles)) {
            throw new HttpException(403, 'forbidden', 'Admin privileges are required.');
        }
    }

    private function redisStatus(): array
    {
        $out = ['configured' => false, 'reachable' => false, 'driver' => config('cache.default')];
        $out['configured'] = config('cache.default') === 'redis'
            || config('database.redis.client') !== null
            || env('REDIS_HOST') !== null;
        $out['reachable'] = RedisHealthService::isAvailable();
        if (! $out['reachable']) {
            $out['error'] = 'Redis not installed/running; using file cache fallback.';
        }
        return $out;
    }

    private function queueStatus(): array
    {
        $out = ['driver' => config('queue.default'), 'pending' => 0, 'failed' => 0, 'processed' => 0];
        try {
            $tables = ['jobs', 'failed_jobs', 'job_batches'];
            foreach ($tables as $t) {
                if (! self::tableExists($t)) continue;
            }
            if (self::tableExists('jobs')) {
                $out['pending'] = (int) DB::table('jobs')->count();
            }
            if (self::tableExists('failed_jobs')) {
                $out['failed'] = (int) DB::table('failed_jobs')->count();
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private function schedulerStatus(): array
    {
        $out = ['last_heartbeat_at' => null, 'age_seconds' => null, 'healthy' => false];
        try {
            if (self::tableExists('system_heartbeats')) {
                $row = DB::table('system_heartbeats')
                    ->where('component', 'scheduler')
                    ->orderByDesc('created_at')
                    ->first();
                if ($row) {
                    $out['last_heartbeat_at'] = (string) $row->created_at;
                    $age = now()->diffInSeconds(\Carbon\Carbon::parse($row->created_at));
                    $out['age_seconds'] = $age;
                    $out['healthy'] = $age < 600;
                }
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private function databaseStatus(): array
    {
        $out = ['driver' => DB::connection()->getDriverName(), 'tables' => []];
        try {
            $tables = ['members', 'auth_users', 'user_roles', 'contributions', 'penalties', 'meeting_minutes', 'sms_logs', 'audit_logs', 'signatory_signatures'];
            $counts = DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM members) AS members,
                    (SELECT COUNT(*) FROM auth_users) AS auth_users,
                    (SELECT COUNT(*) FROM user_roles) AS user_roles,
                    (SELECT COUNT(*) FROM contributions) AS contributions,
                    (SELECT COUNT(*) FROM penalties) AS penalties,
                    (SELECT COUNT(*) FROM meeting_minutes) AS meeting_minutes,
                    (SELECT COUNT(*) FROM sms_logs) AS sms_logs,
                    (SELECT COUNT(*) FROM audit_logs) AS audit_logs,
                    (SELECT COUNT(*) FROM signatory_signatures) AS signatory_signatures
            ");
            foreach ($tables as $t) {
                $key = str_replace('-', '_', $t);
                $out['tables'][$t] = (int) ($counts->$key ?? 0);
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    private function backupStatus(): array
    {
        try {
            if (! self::tableExists('backups')) {
                return ['latest' => null, 'count' => 0];
            }
            $latest = DB::table('backups')->orderByDesc('created_at')->first();
            return [
                'latest' => $latest ? (array) $latest : null,
                'count' => (int) DB::table('backups')->count(),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function memberCounts(): array
    {
        try {
            $counts = DB::selectOne("
                SELECT
                    (SELECT COUNT(*) FROM members) AS total,
                    (SELECT COUNT(*) FROM members WHERE is_active = 1) AS active,
                    (SELECT COUNT(*) FROM members WHERE is_active = 0) AS inactive,
                    (SELECT COUNT(DISTINCT user_id) FROM user_roles WHERE is_active = 1 AND role IN ('admin','super_admin','treasurer','chairperson','vice_chairperson','secretary','vice_secretary','patron')) AS executives
            ");
            return [
                'total' => (int) ($counts->total ?? 0),
                'active' => (int) ($counts->active ?? 0),
                'inactive' => (int) ($counts->inactive ?? 0),
                'executives' => (int) ($counts->executives ?? 0),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function contributionTotals(): array
    {
        try {
            if (! self::tableExists('payments')) {
                return [];
            }
            $row = $this->financialReadModel->confirmedContributionTotals(
                now()->startOfMonth(),
                now()->endOfMonth(),
            );
            return [
                'month' => now()->format('Y-m'),
                'count' => $row['count'],
                'total' => $row['amount_minor'] / 100,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function recentAudit(): array
    {
        try {
            if (! self::tableExists('audit_logs')) {
                return [];
            }
            return DB::table('audit_logs')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function documentStatus(): array
    {
        $out = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0, 'storage_bytes' => 0, 'storage_files' => 0, 'db_orphans' => 0, 'file_orphans' => 0];
        try {
            if (! self::tableExists('documents')) {
                return $out;
            }

            $stats = DB::table('documents')
                ->whereNull('deleted_at')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END) as expired,
                    SUM(size_bytes) as storage_bytes
                ', ['pending', 'approved', 'rejected', now()->toDateString()])
                ->first();

            $out['total'] = (int) ($stats->total ?? 0);
            $out['pending'] = (int) ($stats->pending ?? 0);
            $out['approved'] = (int) ($stats->approved ?? 0);
            $out['rejected'] = (int) ($stats->rejected ?? 0);
            $out['expired'] = (int) ($stats->expired ?? 0);
            $out['storage_bytes'] = (int) ($stats->storage_bytes ?? 0);
            $out['storage_files'] = $out['total'];

            $rows = DB::table('documents')->whereNull('deleted_at')->select('id', 'file_url')->get();
            $storageRoot = storage_path('app/private/documents');
            $dbOrphans = 0;
            foreach ($rows as $r) {
                $abs = $storageRoot . '/' . $r->file_url;
                if (! is_file($abs)) {
                    $dbOrphans++;
                }
            }
            $out['db_orphans'] = $dbOrphans;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }
}
