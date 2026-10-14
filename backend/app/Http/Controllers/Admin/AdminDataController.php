<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupAudit;
use App\Services\FinancialReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Read-only listings that make the Super Admin side navigation fully
 * functional. These reuse the application's existing tables (no new
 * models/permissions) and are Super-Admin-only via the `admin` middleware.
 */
class AdminDataController extends Controller
{
    public function __construct(private FinancialReadModel $financialReadModel) {}

    public function users(): View
    {
        return view('admin.users');
    }

    public function groups(): View
    {
        return view('admin.groups');
    }

    public function transactions(): View
    {
        return view('admin.transactions');
    }

    public function reports(): View
    {
        return view('admin.reports');
    }

    public function auditLogs(): View
    {
        return view('admin.audit-logs');
    }

    public function usersData(): JsonResponse
    {
        $rows = DB::table('auth_users')
            ->leftJoin('members', 'members.user_id', '=', 'auth_users.id')
            ->select('auth_users.id', 'auth_users.email', 'auth_users.phone', 'auth_users.is_banned', 'auth_users.created_at',
                'members.name as member_name', 'members.status as member_status')
            ->orderByDesc('auth_users.created_at')
            ->limit(200)
            ->get();

        $roles = Cache::store('redis')->remember('admin:users:roles', 60, function () {
            return DB::table('user_roles')->where('is_active', 1)->get()->groupBy('user_id');
        });

        $users = $rows->map(function ($u) use ($roles) {
            $u->roles = ($roles->get($u->id, collect()))->pluck('role')->all();

            return $u;
        });

        return response()->json(['users' => $users]);
    }

    public function groupsData(): JsonResponse
    {
        $roles = DB::table('user_roles')
            ->where('is_active', 1)
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->get();

        return response()->json(['groups' => $roles]);
    }

    public function transactionsData(): JsonResponse
    {
        return response()->json(['transactions' => $this->financialReadModel->recentConfirmedPayments(200)]);
    }

    public function reportsData(): JsonResponse
    {
        $summary = Cache::store('redis')->remember('admin:reports:summary', 60, function () {
            return [
                'members' => DB::table('members')->count(),
                'active_members' => DB::table('members')->where('is_active', 1)->count(),
                'total_contributions' => $this->financialReadModel->confirmedContributionTotals()['amount_minor'] / 100,
                'wallets' => DB::table('donation_wallet')->get(),
            ];
        });

        return response()->json(['reports' => $summary]);
    }

    public function auditData(Request $request): JsonResponse
    {
        $q = DB::table('audit_logs')->orderByDesc('created_at');

        if ($request->filled('action')) {
            $q->where('action', $request->input('action'));
        }

        if ($request->filled('backup_only') && $request->boolean('backup_only')) {
            $q->whereIn('action', [
                BackupAudit::BACKUP_CREATED,
                BackupAudit::BACKUP_VERIFIED,
                BackupAudit::BACKUP_DOWNLOADED,
                BackupAudit::BACKUP_DELETED,
                BackupAudit::RESTORE_STARTED,
                BackupAudit::RESTORE_COMPLETED,
                BackupAudit::RESTORE_FAILED,
                BackupAudit::SAFETY_BACKUP_CREATED,
                BackupAudit::BACKUP_PRUNED,
                BackupAudit::BACKUP_SETTINGS_UPDATED,
            ]);
        }

        $rows = $q->limit(200)->get();

        return response()->json(['audit_logs' => $rows]);
    }
}
