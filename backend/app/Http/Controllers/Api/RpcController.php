<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\HookService;
use App\Services\ImportService;
use App\Services\MemberStatsService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RpcController extends Controller
{
    public function __construct(
        private ImportService $imports,
        private MemberStatsService $memberStats,
    ) {}

    public function handle(Request $request, string $name): JsonResponse
    {
        $body = $request->all();

        return match ($name) {
            'has_role' => $this->hasRole($body),
            'get_members_with_roles' => $this->getMembersWithRoles(),
            'get_member_login_activity' => $this->loginActivity($request, $body),
            'search_members' => $this->searchMembers($request, $body),
            'import_member_statement' => $this->importMemberStatement($body),
            'import_beneficiaries' => $this->importBeneficiaries($body),
            'assign_user_role' => $this->assignUserRole($body),
            'update_member_status' => $this->updateMemberStatus($body),
            'delete_member_safe' => $this->deleteMemberSafe($body),
            'admin_reset_password' => $this->adminResetPassword($request, $body),
            'generate_memo_reference' => $this->generateMemoReference(),
            'increment' => $this->increment($body),
            default => throw new HttpException(404, 'unknown_function', "RPC \"{$name}\" is not defined"),
        };
    }

    private function hasRole(array $body): JsonResponse
    {
        $identity = AuthService::identity($this->getBearerToken());
        $userId = (string) ($body['_user_id'] ?? $identity['user_id']);
        $role = (string) ($body['_role'] ?? '');

        return response()->json(
            in_array($role, AuthService::rolesFor($userId), true)
        );
    }

    private function getMembersWithRoles(): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $rows = DB::table('members as m')
            ->join('user_roles as ur', 'ur.user_id', '=', 'm.user_id')
            ->where('m.is_active', 1)
            ->select(
                'm.id',
                'm.name',
                'm.phone',
                'm.is_active',
                'm.user_id',
                'm.created_at',
                'ur.role'
            )
            ->orderBy('m.name')
            ->get()
            ->unique('id')
            ->values()
            ->all();

        return response()->json($rows);
    }

    private function loginActivity(Request $request, array $body): JsonResponse
    {
        AuthService::requireRole('super_admin');

        $search = trim((string) ($body['_search'] ?? ''));
        $limit = max(1, min((int) ($body['_limit'] ?? 50), 200));
        $offset = max(0, (int) ($body['_offset'] ?? 0));

        $query = DB::table('members as m')
            ->leftJoin('auth_users as u', 'u.id', '=', 'm.user_id')
            ->select(
                'm.id as member_id',
                'm.user_id',
                'm.name',
                'm.phone',
                'u.email',
                'm.is_active',
                'u.last_sign_in_at',
                'u.created_at'
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('m.name', 'like', "%{$search}%")
                    ->orWhere('m.phone', 'like', "%{$search}%");
            });
        }

        $total = (int) $query->count();
        $rows = $query->orderByDesc('u.last_sign_in_at')
            ->orderBy('m.name')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn ($row) => (array) $row + ['total_count' => $total, 'is_active' => (bool) $row->is_active])
            ->all();

        return response()->json($rows);
    }

    private function searchMembers(Request $request, array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $search = trim((string) ($body['_search'] ?? ''));
        $limit = max(1, min((int) ($body['_limit'] ?? 50), 200));
        $offset = max(0, (int) ($body['_offset'] ?? 0));

        if ($search === '') {
            $total = (int) DB::table('members')->count();
            $rows = DB::table('members as m')
                ->select('m.id', 'm.name', 'm.phone', 'm.member_id', 'm.status', 'm.is_active', 'm.user_id')
                ->orderBy('m.name')
                ->limit($limit)
                ->offset($offset)
                ->get()
                ->map(fn ($row) => (array) $row + ['total_count' => $total])
                ->all();

            return response()->json($rows);
        }

        $isNumeric = ctype_digit($search);
        $isAlpha = ctype_alpha($search);
        $searchLower = mb_strtolower($search);
        $searchLen = mb_strlen($search);
        $digits = preg_replace('/\D/', '', $search);

        $results = [];
        $seenIds = [];

        $collect = function (iterable $rows) use (&$results, &$seenIds): void {
            foreach ($rows as $row) {
                if (! isset($seenIds[$row->id])) {
                    $seenIds[$row->id] = true;
                    $results[] = $row;
                }
            }
        };

        $select = ['m.id', 'm.name', 'm.phone', 'm.member_id', 'm.status', 'm.is_active', 'm.user_id', 'm.total_contributions', 'm.total_penalties'];

        if ($isAlpha && $searchLen <= 10) {
            $collect(
                DB::table('members as m')
                    ->select($select)
                    ->where('m.member_id', $search)
                    ->get()
            );
        }

        if ($isAlpha && $searchLen >= 2) {
            $collect(
                DB::table('members as m')
                    ->select($select)
                    ->where('m.member_id', 'like', $search.'%')
                    ->get()
            );
        }

        if ($digits !== '') {
            $collect(
                DB::table('members as m')
                    ->select($select)
                    ->where(function ($q) use ($digits) {
                        $q->where('m.phone', $digits)
                            ->orWhere('m.phone', 'like', $digits.'%');
                    })
                    ->get()
            );
        }

        $fulltextHadResults = false;
        if ($searchLen >= 4) {
            $fulltextRows = DB::table('members as m')
                ->select($select)
                ->whereRaw('MATCH(m.name) AGAINST(? IN BOOLEAN MODE)', [$searchLower.'*'])
                ->get();

            if ($fulltextRows->isNotEmpty()) {
                $fulltextHadResults = true;
                $collect($fulltextRows);
            }
        }

        if ($searchLen < 4 || ! $fulltextHadResults) {
            $collect(
                DB::table('members as m')
                    ->select($select)
                    ->where('m.name', 'like', "%{$search}%")
                    ->orWhere('m.phone', 'like', "%{$search}%")
                    ->get()
            );
        }

        usort($results, fn ($a, $b) => strcmp($a->name, $b->name));

        $total = count($results);
        $paginated = array_slice($results, $offset, $limit);

        return response()->json(array_map(
            fn ($row) => (array) $row + ['total_count' => $total],
            $paginated
        ));
    }

    private function importMemberStatement(array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $rows = is_array($body['rows'] ?? null) ? $body['rows'] : (is_array($body) ? $body : []);
        $sourceName = (string) ($body['source_name'] ?? $body['sourceName'] ?? 'statement_import');
        $fileName = (string) ($body['file_name'] ?? $body['fileName'] ?? '');
        $createdBy = $this->currentUserId();

        $result = $this->imports->importMemberStatement($rows, $sourceName, $fileName, $createdBy);

        return response()->json($result);
    }

    private function importBeneficiaries(array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $rows = is_array($body['rows'] ?? null) ? $body['rows'] : (is_array($body) ? $body : []);
        $sourceName = (string) ($body['source_name'] ?? $body['sourceName'] ?? 'beneficiary_import');
        $fileName = (string) ($body['file_name'] ?? $body['fileName'] ?? '');
        $createdBy = $this->currentUserId();

        $result = $this->imports->importBeneficiaries($rows, $sourceName, $fileName, $createdBy);

        return response()->json($result);
    }

    private function assignUserRole(array $body): JsonResponse
    {
        $identity = $this->requireRole('admin', 'super_admin');

        $userId = (string) ($body['user_id_param'] ?? '');
        $role = (string) ($body['role_param'] ?? '');

        if ($userId === '' || $role === '') {
            throw new HttpException(400, 'invalid_request', 'user_id_param and role_param are required');
        }

        DB::table('user_roles')->upsert(
            [[
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => $role,
                'is_active' => 1,
            ]],
            ['user_id', 'role'],
            ['is_active']
        );

        return response()->json(['success' => true]);
    }

    private function updateMemberStatus(array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin');

        $status = (string) ($body['new_status'] ?? '');
        if (! in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            return response()->json(['success' => false, 'error' => 'Invalid status']);
        }

        $memberId = (string) ($body['target_member_id'] ?? '');
        $member = DB::table('members')->where('id', $memberId)->first();
        if ($member === null) {
            return response()->json(['success' => false, 'error' => 'Member not found']);
        }

        DB::transaction(function () use ($member, $status) {
            DB::table('members')->where('id', $member->id)->update([
                'status' => $status,
                'is_active' => $status === 'active' ? 1 : 0,
                'updated_at' => now(),
            ]);

            if (! empty($member->user_id)) {
                DB::table('auth_users')->where('id', $member->user_id)->update([
                    'is_banned' => $status === 'active' ? 0 : 1,
                    'updated_at' => now(),
                ]);
            }
        });
        $this->memberStats->forgetCache((string) $member->id);

        return response()->json([
            'success' => true,
            'member_name' => $member->name,
            'new_status' => $status,
        ]);
    }

    private function deleteMemberSafe(array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin');

        $memberId = (string) ($body['target_member_id'] ?? '');
        $member = DB::table('members')->where('id', $memberId)->first();
        if ($member === null) {
            return response()->json(['success' => false, 'error' => 'Member not found']);
        }

        DB::transaction(function () use ($member) {
            DB::table('unmatched_payments')
                ->whereIn('payment_id', function ($q) use ($member) {
                    $q->select('id')->from('payments')->where('member_id', $member->id);
                })
                ->delete();

            DB::table('members')->where('id', $member->id)->delete();

            if (! empty($member->user_id)) {
                DB::table('user_roles')->where('user_id', $member->user_id)->delete();
                DB::table('auth_users')->where('id', $member->user_id)->delete();
            }
        });

        return response()->json(['success' => true, 'deleted_member' => $member->name]);
    }

    private function adminResetPassword(Request $request, array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin');

        $password = (string) ($body['new_password'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));
        $userId = trim((string) ($body['target_user_id'] ?? ''));

        if (strlen($password) < 8) {
            throw new HttpException(422, 'weak_password', 'New password must be at least 8 characters');
        }
        if ($reason === '' || strlen($reason) > 2000) {
            throw new HttpException(422, 'invalid_request', 'A reset reason of 1 to 2000 characters is required');
        }
        if ($userId === '') {
            throw new HttpException(422, 'invalid_request', 'target_user_id is required');
        }

        $target = DB::table('auth_users')->where('id', $userId)->first();
        if (! $target) {
            throw new HttpException(404, 'user_not_found', 'The account to reset was not found');
        }

        $identity = $request->attributes->get('identity');
        $isSuperAdmin = in_array('super_admin', $identity['roles'] ?? [], true);
        if (! $isSuperAdmin && in_array('super_admin', AuthService::rolesFor((string) $target->id), true)) {
            throw new HttpException(403, 'insufficient_permissions', 'Administrators cannot reset a super-admin password');
        }
        $actorId = (string) ($identity['user_id'] ?? '');
        if ($actorId === '') {
            throw new HttpException(401, 'invalid_token', 'Authenticated administrator identity is unavailable');
        }
        $passwordHash = AuthService::hashPassword($password);
        $now = now();

        DB::transaction(function () use ($target, $actorId, $passwordHash, $reason, $request, $now): void {
            DB::table('auth_users')->where('id', $target->id)->update([
                'encrypted_password' => $passwordHash,
                'must_change_password' => 1,
                'updated_at' => $now,
            ]);
            DB::table('auth_refresh_tokens')->where('user_id', $target->id)->update(['revoked' => 1]);
            DB::table('password_resets')->insert([
                'id' => Uuid::v4(),
                'user_id' => $target->id,
                'reset_token' => bin2hex(random_bytes(32)),
                'reset_by' => $actorId,
                'reset_at' => $now,
                'new_password_hash' => $passwordHash,
                'created_at' => $now,
                'expires_at' => null,
                'reason' => $reason,
            ]);
            DB::table('login_activity')->insert([
                'id' => Uuid::v4(),
                'user_id' => $target->id,
                'email' => $target->email,
                'phone' => $target->phone,
                'role' => AuthService::primaryRole(AuthService::rolesFor((string) $target->id)),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ?? '',
                'event_type' => 'admin_password_reset',
                'success' => 1,
                'failure_reason' => null,
                'created_at' => $now,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. The user must change it after signing in.',
            'must_change_password' => true,
        ]);
    }

    private function generateMemoReference(): JsonResponse
    {
        AuthService::require();

        return response()->json(['reference' => HookService::memoReference()]);
    }

    private function increment(array $body): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $table = (string) ($body['table_name'] ?? '');
        $field = (string) ($body['field_name'] ?? '');

        if (! in_array($table, ['penalty_wallet', 'donation_wallet', 'operational_wallet'], true)
            || ! in_array($field, ['total_received', 'total_withdrawn', 'total_balance'], true)) {
            throw new HttpException(400, 'invalid_request', 'Unsupported wallet or field');
        }

        $amount = (float) ($body['amount'] ?? 0);
        $rowId = (string) ($body['row_id'] ?? '');

        DB::statement(
            "UPDATE `{$table}` SET `{$field}` = COALESCE(`{$field}`,0) + ? WHERE id = ?",
            [$amount, $rowId]
        );

        if ($field === 'total_received') {
            DB::statement(
                "UPDATE `{$table}` SET total_balance = COALESCE(total_balance,0) + ? WHERE id = ?",
                [$amount, $rowId]
            );
        } elseif ($field === 'total_withdrawn') {
            DB::statement(
                "UPDATE `{$table}` SET total_balance = COALESCE(total_balance,0) - ? WHERE id = ?",
                [abs($amount), $rowId]
            );
        }

        return response()->json(['success' => true]);
    }

    private function getBearerToken(): ?string
    {
        $header = '';

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

    private function currentUserId(): ?string
    {
        $token = $this->getBearerToken();
        if (! $token) {
            return null;
        }

        try {
            $identity = AuthService::identity($token);

            return $identity['user_id'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
