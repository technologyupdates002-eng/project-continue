<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    public function memberStatus(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! AuthService::isAdmin($identity['roles'] ?? [])) {
            return response()->json(['error' => 'insufficient_permissions'], 403);
        }

        $onlineThreshold = (int) config('presence.online_threshold_seconds', 120);
        $roles = $this->primaryRoleSubquery();

        $rows = DB::table('members as m')
            ->leftJoin('auth_users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('user_presence as p', 'p.user_id', '=', 'm.user_id')
            ->leftJoinSub($roles, 'ur', 'ur.user_id', '=', 'm.user_id')
            ->select(
                'm.id as member_id',
                'm.user_id',
                'm.name',
                'm.phone',
                'm.profile_picture_url as avatar_url',
                'm.is_active',
                'ur.role',
                'p.is_online',
                DB::raw('COALESCE(p.last_seen, u.last_seen_at) as last_seen'),
                'u.last_sign_in_at'
            )
            ->orderBy('m.name')
            ->get()
            ->map(function ($row) use ($onlineThreshold) {
                $lastSeen = $row->last_seen ? strtotime($row->last_seen) : null;
                $isOnline = $lastSeen !== null && (time() - $lastSeen) <= $onlineThreshold;

                return [
                    'member_id' => $row->member_id,
                    'user_id' => $row->user_id,
                    'name' => $row->name,
                    'phone' => $row->phone,
                    'avatar_url' => $row->avatar_url,
                    'role' => $row->role ?? 'member',
                    'is_online' => $isOnline,
                    'last_seen' => $row->last_seen,
                    'last_login_at' => $row->last_sign_in_at,
                    'is_active' => (bool) $row->is_active,
                ];
            })
            ->all();

        return response()->json([
            'data' => $rows,
            'online_count' => count(array_filter($rows, fn ($row) => $row['is_online'])),
            'total_members' => count($rows),
        ]);
    }

    public function onlineUsers(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! AuthService::isAdmin($identity['roles'] ?? [])) {
            return response()->json(['error' => 'insufficient_permissions'], 403);
        }

        $onlineThreshold = (int) config('presence.online_threshold_seconds', 120);
        $cutoff = now()->subSeconds($onlineThreshold)->toDateTimeString();
        $roles = $this->primaryRoleSubquery();

        $query = DB::table('auth_users as u')
            ->leftJoin('user_presence as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('members as m', 'm.user_id', '=', 'u.id')
            ->leftJoinSub($roles, 'ur', 'ur.user_id', '=', 'u.id')
            ->select(
                'u.id as user_id',
                'p.is_online',
                DB::raw('COALESCE(p.last_seen, u.last_seen_at) as last_seen'),
                'u.email',
                'u.phone',
                'u.last_sign_in_at',
                'm.name as member_name',
                'm.phone as member_phone',
                'ur.role'
            );

        $onlineCount = DB::table('auth_users as u')
            ->leftJoin('user_presence as p', 'p.user_id', '=', 'u.id')
            ->whereRaw('COALESCE(p.last_seen, u.last_seen_at) >= ?', [$cutoff])
            ->count('u.id');
        $totalCount = DB::table('auth_users')->count();

        $status = $request->query('status');
        if ($status === 'online') {
            $query->whereRaw('COALESCE(p.last_seen, u.last_seen_at) >= ?', [$cutoff]);
        } elseif ($status === 'offline') {
            $query->where(function ($builder) use ($cutoff) {
                $builder->whereNull('p.last_seen')
                    ->whereNull('u.last_seen_at')
                    ->orWhereRaw('COALESCE(p.last_seen, u.last_seen_at) < ?', [$cutoff]);
            });
        }

        $limit = min(100, max(1, (int) $request->query('limit', 100)));
        $offset = max(0, (int) $request->query('offset', 0));

        $rows = $query
            ->orderByRaw('COALESCE(p.last_seen, u.last_seen_at) DESC')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(function ($row) use ($onlineThreshold) {
                $lastSeen = $row->last_seen ? strtotime($row->last_seen) : null;
                $isActuallyOnline = $lastSeen !== null && (time() - $lastSeen) <= $onlineThreshold;
                return [
                    'user_id' => $row->user_id,
                    'name' => $row->member_name ?? $row->email,
                    'email' => $row->email,
                    'phone' => $row->member_phone ?? $row->phone,
                    'role' => $row->role ?? 'member',
                    'is_online' => $isActuallyOnline,
                    'last_seen' => $row->last_seen,
                    'last_login_at' => $row->last_sign_in_at,
                ];
            })
            ->all();

        return response()->json([
            'data' => $rows,
            'online_count' => $onlineCount,
            'offline_count' => max(0, $totalCount - $onlineCount),
            'total_count' => $totalCount,
        ]);
    }

    public function loginActivity(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! AuthService::canAccessSuperAdmin($identity['roles'] ?? [])) {
            return response()->json(['error' => 'insufficient_permissions'], 403);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'role' => ['nullable', 'string', 'max:40'],
            'success' => ['nullable', 'in:true,false'],
            'event_type' => ['nullable', 'in:login,login_failed,token_refresh,historical_login'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $roles = $this->primaryRoleSubquery();
        $activityEvents = DB::table('login_activity as la')
            ->leftJoin('members as m', 'm.user_id', '=', 'la.user_id')
            ->select(
                'la.id',
                'la.user_id',
                'la.email',
                'la.phone',
                'la.role',
                'la.ip_address',
                'la.user_agent',
                'la.event_type',
                'la.success',
                'la.failure_reason',
                'la.created_at',
                'la.logged_out_at',
                'm.name as member_name'
            );
        $historicalEvents = DB::table('auth_users as u')
            ->leftJoin('members as m', 'm.user_id', '=', 'u.id')
            ->leftJoinSub($roles, 'ur', 'ur.user_id', '=', 'u.id')
            ->whereNotNull('u.last_sign_in_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('login_activity as existing')
                    ->whereColumn('existing.user_id', 'u.id')
                    ->where('existing.event_type', 'login')
                    ->where('existing.success', 1)
                    ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, existing.created_at, u.last_sign_in_at)) <= 5');
            })
            ->selectRaw(
                "CONCAT('historical-', u.id) as id, u.id as user_id, u.email, u.phone, COALESCE(ur.role, 'member') as role, NULL as ip_address, NULL as user_agent, 'historical_login' as event_type, 1 as success, NULL as failure_reason, u.last_sign_in_at as created_at, NULL as logged_out_at, m.name as member_name"
            );
        $events = $activityEvents->unionAll($historicalEvents);
        $query = DB::query()->fromSub($events, 'la');

        $search = $filters['search'] ?? null;
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('la.member_name', 'like', "%{$search}%")
                    ->orWhere('la.email', 'like', "%{$search}%")
                    ->orWhere('la.phone', 'like', "%{$search}%");
            });
        }

        $role = $filters['role'] ?? null;
        if ($role) {
            $query->where('la.role', $role);
        }

        $success = $filters['success'] ?? null;
        if ($success !== null) {
            $query->where('la.success', $success === 'true' ? 1 : 0);
        }

        if (! empty($filters['event_type'])) {
            $query->where('la.event_type', $filters['event_type']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('la.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('la.created_at', '<=', $filters['to']);
        }

        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);

        $total = (clone $query)->count('la.id');
        $successful = (clone $query)->where('la.success', 1)->count('la.id');
        $failed = (clone $query)->where('la.success', 0)->count('la.id');
        $rows = $query->orderByDesc('la.created_at')
            ->orderByDesc('la.id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->all();

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function primaryRoleSubquery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('user_roles')
            ->where('is_active', 1)
            ->select('user_id')
            ->selectRaw(
                "CASE
                    WHEN MAX(CASE WHEN role = 'super_admin' THEN 1 ELSE 0 END) = 1 THEN 'super_admin'
                    WHEN MAX(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) = 1 THEN 'admin'
                    WHEN MAX(CASE WHEN role = 'chairperson' THEN 1 ELSE 0 END) = 1 THEN 'chairperson'
                    WHEN MAX(CASE WHEN role = 'vice_chairperson' THEN 1 ELSE 0 END) = 1 THEN 'vice_chairperson'
                    WHEN MAX(CASE WHEN role = 'secretary' THEN 1 ELSE 0 END) = 1 THEN 'secretary'
                    WHEN MAX(CASE WHEN role = 'vice_secretary' THEN 1 ELSE 0 END) = 1 THEN 'vice_secretary'
                    WHEN MAX(CASE WHEN role = 'treasurer' THEN 1 ELSE 0 END) = 1 THEN 'treasurer'
                    WHEN MAX(CASE WHEN role = 'executive' THEN 1 ELSE 0 END) = 1 THEN 'executive'
                    WHEN MAX(CASE WHEN role = 'patron' THEN 1 ELSE 0 END) = 1 THEN 'patron'
                    ELSE 'member'
                END as role"
            )
            ->groupBy('user_id');
    }
}
