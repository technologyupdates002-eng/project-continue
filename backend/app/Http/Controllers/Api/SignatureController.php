<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\MemberRoleResolver;
use App\Services\WithdrawalSignatureService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative backend for officer signatures.
 *
 * Storage: `signatory_signatures` table (one row per user per signatory role).
 * Resolution: an officer may upload/replace ONLY their own signature for
 * their own role(s). Admins may upload/replace on behalf of any user.
 *
 * Files are persisted via the existing storage controller; this controller
 * just manages the DB row.
 */
class SignatureController extends Controller
{
    public function __construct(
        private MemberRoleResolver $roles,
        private WithdrawalSignatureService $signatureService,
    ) {}

    /**
     * GET /api/signatures?role=secretary
     * Returns the caller's signature for their own role, or the active
     * role holder's signature to another executive preparing an official record.
     */
    public function current(Request $request): JsonResponse
    {
        $role = (string) $request->query('role', '');
        if (! array_key_exists($role, MemberRoleResolver::SIGNATORY_ROLES)) {
            throw new HttpException(422, 'invalid_role', 'Unknown signatory role.');
        }
        $userId = $this->userId($request);
        if (! $this->roles->hasRole($userId, $role) && ! $this->roles->isExecutive($userId) && ! $this->isAdminOrSuper($request)) {
            throw new HttpException(403, 'forbidden', 'Only office bearers may view officer signatures.');
        }
        $targetUserId = $this->roles->hasRole($userId, $role)
            ? $userId
            : $this->roles->userIdForRole($role);
        if ($targetUserId === null) {
            return response()->json(['data' => null]);
        }

        $row = DB::table('signatory_signatures')
            ->where('signatory_role', $role)
            ->where('user_id', $targetUserId)
            ->orderByDesc('updated_at')
            ->first();
        if (! $row) {
            return response()->json(['data' => [
                'user_id' => $targetUserId,
                'signatory_role' => $role,
                'full_name' => $this->roles->memberNameForUser($targetUserId),
                'signature_url' => null,
            ]]);
        }

        $data = $this->withPreview($row);
        $data['full_name'] = $data['full_name'] ?: $this->roles->memberNameForUser($targetUserId);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/signatures
     * Returns all signature rows (admin-only).
     */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $rows = DB::table('signatory_signatures')->orderByDesc('updated_at')->get();
        return response()->json(['data' => $rows->map(fn ($row) => $this->withPreview($row))->all()]);
    }

    /**
     * POST /api/signatures — body: { signatory_role, signature_url, full_name? }
     * The officer uploads to /api/storage/v1/object/signatory-signatures/... first
     * (which returns the storage URL), then calls this endpoint to persist the row.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signatory_role' => ['required', 'string'],
            'signature_url' => ['required', 'string', 'max:500'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'string', 'max:36'],
        ]);
        if (! array_key_exists($data['signatory_role'], MemberRoleResolver::SIGNATORY_ROLES)) {
            throw new HttpException(422, 'invalid_role', 'Unknown signatory role.');
        }
        $callerId = $this->userId($request);
        $targetUserId = $data['user_id'] ?? $callerId;
        if ($targetUserId !== $callerId && ! $this->isAdminOrSuper($request)) {
            throw new HttpException(403, 'forbidden', 'You may only upload your own signature.');
        }
        // The target user must actually hold the role.
        if (! $this->roles->hasRole($targetUserId, $data['signatory_role']) && ! $this->isAdminOrSuper($request)) {
            throw new HttpException(403, 'forbidden', 'Target user does not hold that role.');
        }
        $this->signatureService->resolveImage($data['signature_url']);

        $row = [
            'id' => Uuid::v4(),
            'user_id' => $targetUserId,
            'signatory_role' => $data['signatory_role'],
            'signature_url' => $data['signature_url'],
            'full_name' => $this->roles->memberNameForUser($targetUserId) ?? ($data['full_name'] ?? null),
            'updated_by' => $callerId,
            'updated_at' => now(),
            'created_at' => now(),
        ];
        // Upsert by (user_id, signatory_role)
        DB::table('signatory_signatures')->upsert(
            [$row],
            ['user_id', 'signatory_role'],
            ['signature_url', 'full_name', 'updated_by', 'updated_at'],
        );
        $this->audit($request, 'SIGNATURE_UPLOADED', $targetUserId, ['role' => $data['signatory_role']]);
        $fresh = DB::table('signatory_signatures')->where('user_id', $targetUserId)->where('signatory_role', $data['signatory_role'])->first();
        return response()->json(['data' => $fresh ? (array) $fresh : null], 201);
    }

    /**
     * DELETE /api/signatures/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = DB::table('signatory_signatures')->where('id', $id)->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Signature not found.');
        }
        $callerId = $this->userId($request);
        if ($row->user_id !== $callerId && ! $this->isAdminOrSuper($request)) {
            throw new HttpException(403, 'forbidden', 'You may only remove your own signature.');
        }
        DB::table('signatory_signatures')->where('id', $id)->delete();
        $this->audit($request, 'SIGNATURE_REMOVED', $row->user_id, ['role' => $row->signatory_role]);
        return response()->json(['data' => ['removed' => true]]);
    }

    private function userId(Request $request): string
    {
        $identity = $request->attributes->get('identity');
        return (string) ($identity['user_id'] ?? '');
    }

    private function withPreview(object $row): array
    {
        $data = (array) $row;
        $data['preview_url'] = null;
        $data['preview_error'] = null;

        if (!empty($row->signature_url)) {
            try {
                $data['preview_url'] = $this->signatureService->createPreviewUrl($row->signature_url);
            } catch (HttpException $e) {
                $data['preview_error'] = $e->getMessage();
            }
        }

        return $data;
    }

    private function isAdminOrSuper(Request $request): bool
    {
        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];
        return in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
    }

    private function requireAdmin(Request $request): void
    {
        if (! $this->isAdminOrSuper($request)) {
            throw new HttpException(403, 'forbidden', 'Admin only.');
        }
    }

    private function audit(Request $request, string $action, ?string $targetUserId, array $details): void
    {
        try {
            $identity = $request->attributes->get('identity');
            DB::table('audit_logs')->insert([
                'id' => Uuid::v4(),
                'super_admin_id' => $this->userId($request),
                'action' => $action,
                'target_user_id' => $targetUserId,
                'target_member_id' => null,
                'details' => json_encode($details),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
