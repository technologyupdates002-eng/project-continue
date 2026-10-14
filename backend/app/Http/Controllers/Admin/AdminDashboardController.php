<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\SystemHealthService;
use App\Services\QueueManagementService;
use App\Services\CacheManagementService;
use App\Services\FinancialIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected SystemHealthService $health,
        protected QueueManagementService $queue,
        protected CacheManagementService $cache,
        protected FinancialIntegrityService $financial,
    ) {}

    public function page(): View
    {
        return view('admin.dashboard');
    }

    public function stats(): JsonResponse
    {
        $identity = request()->attributes->get('identity');
        $roles = $identity['roles'] ?? [];
        if (! in_array('admin', $roles, true) && ! in_array('super_admin', $roles, true)) {
            throw new HttpException(403, 'forbidden', 'Admin access required.');
        }

        $stats = DB::table('backups')
            ->where('is_safety', false)
            ->where('status', '!=', Backup::STATUS_DELETED)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as restored,
                SUM(CASE WHEN verification_status = ? THEN 1 ELSE 0 END) as verified,
                SUM(size_bytes) as storage_used_bytes
            ', [Backup::STATUS_COMPLETED, Backup::STATUS_FAILED, Backup::STATUS_RESTORED, Backup::VERIFICATION_PASSED])
            ->first();

        $latest = Backup::where('is_safety', false)
            ->where('status', Backup::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->first();

        $latestVerified = Backup::where('is_safety', false)
            ->where('status', Backup::STATUS_COMPLETED)
            ->where('verification_status', Backup::VERIFICATION_PASSED)
            ->orderByDesc('created_at')
            ->first();

        $safetyBackups = Backup::where('is_safety', true)
            ->where('status', '!=', Backup::STATUS_DELETED)
            ->count();

        return response()->json([
            'stats' => [
                'total' => (int) ($stats->total ?? 0),
                'completed' => (int) ($stats->completed ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
                'restored' => (int) ($stats->restored ?? 0),
                'verified' => (int) ($stats->verified ?? 0),
                'storage_used_bytes' => (int) ($stats->storage_used_bytes ?? 0),
                'latest_backup' => $latest ? ['id' => $latest->id, 'name' => $latest->name, 'created_at' => $latest->created_at?->toIso8601String()] : null,
                'latest_verified_backup' => $latestVerified ? ['id' => $latestVerified->id, 'name' => $latestVerified->name, 'created_at' => $latestVerified->created_at?->toIso8601String()] : null,
                'users' => DB::table('auth_users')->count(),
                'members' => DB::table('members')->count(),
                'safety_backups' => $safetyBackups,
            ],
            'system' => $this->health->overview(),
            'queue' => $this->queue->overview(),
            'cache' => $this->cache->overview(),
            'financial' => $this->financial->overview(),
        ]);
    }
}
