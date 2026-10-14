<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\DocumentAuditService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Admin-only document management endpoints.
 *
 * - index:      server-side paginated list with search + status + type + member filters
 * - summary:    aggregate counts for the admin dashboard
 * - audit:      recent document_audit_log entries
 * - expiring:   documents expiring within N days
 * - missing:    members missing a required document type
 * - forMember:  documents + per-type status for one member
 * - orphans:    files on disk with no DB row, or DB rows with missing files
 * - createType/updateType: manage the document_types catalog
 */
class DocumentAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->requireStaff($identity);

        $q = DB::table('documents as d')
            ->leftJoin('members as m', 'm.id', '=', 'd.member_id')
            ->leftJoin('auth_users as u', 'u.id', '=', 'd.member_id')
            ->leftJoin('document_types as dt', 'dt.code', '=', 'd.document_type_code')
            ->whereNull('d.deleted_at')
            ->select(
                'd.*',
                'm.name as member_name',
                'm.phone as member_phone',
                'u.email as member_email',
                'dt.label as document_type_label',
                'dt.category as document_type_category',
                'dt.is_required as document_type_required'
            );

        if ($status = $request->query('status')) {
            $q->where('d.status', $status);
        }
        if ($type = $request->query('document_type_code')) {
            $q->where('d.document_type_code', $type);
        }
        if ($memberId = $request->query('member_id')) {
            $q->where('d.member_id', $memberId);
        }
        if ($search = $request->query('q')) {
            $q->where(function ($w) use ($search) {
                $w->where('m.name', 'like', '%' . $search . '%')
                  ->orWhere('m.phone', 'like', '%' . $search . '%')
                  ->orWhere('u.email', 'like', '%' . $search . '%')
                  ->orWhere('d.file_name', 'like', '%' . $search . '%')
                  ->orWhere('d.notes', 'like', '%' . $search . '%')
                  ->orWhere('d.document_type_code', 'like', '%' . $search . '%');
            });
        }
        if ($from = $request->query('uploaded_from')) {
            $q->where('d.created_at', '>=', $from);
        }
        if ($to = $request->query('uploaded_to')) {
            $q->where('d.created_at', '<=', $to);
        }
        if ($expired = $request->query('expired')) {
            if ($expired === '1' || $expired === 'true') {
                $q->whereNotNull('d.expires_at')->where('d.expires_at', '<', now()->toDateString());
            }
        }
        if ($expiring = (int) $request->query('expiring_within', 0)) {
            $q->whereNotNull('d.expires_at')
              ->where('d.expires_at', '>=', now()->toDateString())
              ->where('d.expires_at', '<=', now()->addDays($expiring)->toDateString());
        }

        $total = (clone $q)->count();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $rows = $q->orderByDesc('d.created_at')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));

        $total = DB::table('documents')->whereNull('deleted_at')->count();
        $pending = DB::table('documents')->whereNull('deleted_at')->where('status', 'pending')->count();
        $approved = DB::table('documents')->whereNull('deleted_at')->where('status', 'approved')->count();
        $rejected = DB::table('documents')->whereNull('deleted_at')->where('status', 'rejected')->count();
        $expired = DB::table('documents')->whereNull('deleted_at')->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString())->count();
        $expiring30 = DB::table('documents')->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', now()->toDateString())
            ->where('expires_at', '<=', now()->addDays(30)->toDateString())
            ->count();

        $memberTotal = DB::table('members')->where('is_active', 1)->count();
        $completeCount = 0;
        $missingByType = [];

        $types = DB::table('document_types')->where('is_active', 1)->where('is_required', 1)->orderBy('sort_order')->get();
        foreach ($types as $type) {
            $code = $type->code;
            $membersWithApproved = DB::table('documents')
                ->whereNull('deleted_at')
                ->where('document_type_code', $code)
                ->where('status', 'approved')
                ->distinct()
                ->count('member_id');
            $missing = $memberTotal - $membersWithApproved;
            if ($missing > 0) {
                $missingByType[] = [
                    'code' => $code,
                    'label' => $type->label,
                    'missing_count' => $missing,
                ];
            }
        }

        $complete = DB::table('members as m')
            ->where('m.is_active', 1)
            ->whereNotExists(function ($q) use ($types) {
                $q->select(DB::raw(1))
                    ->from('document_types as dt')
                    ->where('dt.is_active', 1)
                    ->where('dt.is_required', 1)
                    ->leftJoin('documents as d', function ($j) {
                        $j->on('d.document_type_code', '=', 'dt.code')
                          ->on('d.member_id', '=', 'm.id')
                          ->whereNull('d.deleted_at')
                          ->where('d.status', 'approved');
                    })
                    ->whereNull('d.id');
            })
            ->count();
        $completeCount = $complete;

        // Storage usage.
        $storageBytes = DB::table('documents')->whereNull('deleted_at')->sum('size_bytes');
        $storageFiles = DB::table('documents')->whereNull('deleted_at')->count();

        return response()->json(['data' => [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'expired' => $expired,
            'expiring_within_30_days' => $expiring30,
            'members_total' => $memberTotal,
            'members_complete' => $completeCount,
            'members_incomplete' => $memberTotal - $completeCount,
            'missing_by_type' => $missingByType,
            'storage_bytes' => (int) $storageBytes,
            'storage_files' => $storageFiles,
            'audit_stats' => DocumentAuditService::stats(),
        ]]);
    }

    public function audit(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));
        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $entries = DocumentAuditService::recent($limit, 'documents');
        return response()->json(['data' => $entries]);
    }

    public function expiring(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));
        $days = min(365, max(1, (int) $request->query('days', 30)));
        $rows = DB::table('documents as d')
            ->leftJoin('members as m', 'm.id', '=', 'd.member_id')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.expires_at')
            ->where('d.expires_at', '<=', now()->addDays($days)->toDateString())
            ->where('d.expires_at', '>=', now()->subDays(7)->toDateString())
            ->select('d.id', 'd.member_id', 'd.document_type_code', 'd.expires_at', 'd.status', 'm.name as member_name', 'm.phone as member_phone')
            ->orderBy('d.expires_at')
            ->limit(500)
            ->get();
        return response()->json(['data' => $rows->map(fn ($r) => (array) $r)->all()]);
    }

    public function missing(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));

        $code = $request->query('document_type_code');
        if (! $code) {
            // List all required types with missing count.
            $types = DB::table('document_types')->where('is_active', 1)->where('is_required', 1)->orderBy('sort_order')->get();
            $out = [];
            $memberTotal = DB::table('members')->where('is_active', 1)->count();
            foreach ($types as $t) {
                $uploaded = DB::table('documents')->whereNull('deleted_at')
                    ->where('document_type_code', $t->code)
                    ->where('status', 'approved')
                    ->distinct()->count('member_id');
                $out[] = [
                    'code' => $t->code,
                    'label' => $t->label,
                    'missing_count' => max(0, $memberTotal - $uploaded),
                ];
            }
            return response()->json(['data' => $out]);
        }

        // List members missing this specific required type.
        $rows = DB::table('members as m')
            ->leftJoin('documents as d', function ($j) use ($code) {
                $j->on('d.member_id', '=', 'm.id')
                  ->where('d.document_type_code', '=', $code)
                  ->whereNull('d.deleted_at')
                  ->where('d.status', 'approved');
            })
            ->where('m.is_active', 1)
            ->whereNull('d.id')
            ->select('m.id as member_id', 'm.name', 'm.phone', 'm.email')
            ->limit(500)
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => (array) $r)->all()]);
    }

    public function forMember(Request $request, string $memberId): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));
        $rows = DB::table('documents')
            ->where('member_id', $memberId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
        $member = DB::table('members')->where('id', $memberId)->first();
        return response()->json([
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'member' => $member ? (array) $member : null,
        ]);
    }

    public function orphans(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));

        $dbOrphans = []; // DB rows whose file is missing on disk.
        $fileOrphans = []; // Files on disk with no DB row.

        // DB rows -> missing file.
        $rows = DB::table('documents')->whereNull('deleted_at')->select('id', 'member_id', 'file_url', 'file_name')->get();
        $storageRoot = storage_path('app/private/documents');
        foreach ($rows as $r) {
            $abs = $storageRoot . '/' . $r->file_url;
            if (! is_file($abs)) {
                $dbOrphans[] = [
                    'id' => $r->id,
                    'member_id' => $r->member_id,
                    'file_name' => $r->file_name,
                    'expected_path' => $r->file_url,
                ];
            }
        }

        // Files on disk -> not in DB.
        if (is_dir($storageRoot)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($storageRoot, \FilesystemIterator::SKIP_DOTS));
            foreach ($rii as $f) {
                if ($f->isDir()) continue;
                $rel = str_replace($storageRoot . '/', '', $f->getPathname());
                $exists = DB::table('documents')->where('file_url', $rel)->exists();
                if (! $exists) {
                    $fileOrphans[] = [
                        'path' => $rel,
                        'size_bytes' => $f->getSize(),
                        'modified' => date('c', $f->getMTime()),
                    ];
                }
            }
        }

        return response()->json([
            'data' => [
                'db_orphans' => $dbOrphans,
                'file_orphans' => $fileOrphans,
                'db_orphans_count' => count($dbOrphans),
                'file_orphans_count' => count($fileOrphans),
            ],
        ]);
    }

    public function createType(Request $request): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));
        if (! $this->canAccessSuperAdmin($request->attributes->get('identity'))) {
            throw new HttpException(403, 'forbidden', 'Admin privileges are required to manage the document type catalog.');
        }
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40|unique:document_types,code',
            'label' => 'required|string|max:120',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:40',
            'requires_expiry' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'allowed_mime' => 'nullable|array',
            'max_size_bytes' => 'nullable|integer|min:1024',
            'sort_order' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            throw new HttpException(422, 'validation_failed', $validator->errors()->first());
        }
        $payload = $validator->validated();
        $payload['allowed_mime'] = isset($payload['allowed_mime']) ? json_encode($payload['allowed_mime']) : null;
        $payload['is_active'] = true;
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        DB::table('document_types')->insert($payload);
        $row = DB::table('document_types')->where('code', $payload['code'])->first();
        return response()->json(['data' => (array) $row], 201);
    }

    public function updateType(Request $request, string $code): JsonResponse
    {
        $this->requireStaff($request->attributes->get('identity'));
        if (! $this->canAccessSuperAdmin($request->attributes->get('identity'))) {
            throw new HttpException(403, 'forbidden', 'Admin privileges are required to manage the document type catalog.');
        }
        $row = DB::table('document_types')->where('code', $code)->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Type not found.');
        }
        $validator = Validator::make($request->all(), [
            'label' => 'nullable|string|max:120',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:40',
            'requires_expiry' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'allowed_mime' => 'nullable|array',
            'max_size_bytes' => 'nullable|integer|min:1024',
            'sort_order' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            throw new HttpException(422, 'validation_failed', $validator->errors()->first());
        }
        $payload = $validator->validated();
        if (isset($payload['allowed_mime'])) {
            $payload['allowed_mime'] = json_encode($payload['allowed_mime']);
        }
        $payload['updated_at'] = now();
        DB::table('document_types')->where('code', $code)->update($payload);
        $row = DB::table('document_types')->where('code', $code)->first();
        return response()->json(['data' => (array) $row]);
    }

    private function requireStaff(array $identity): void
    {
        $roles = $identity['roles'] ?? [];
        if (! array_intersect($roles, ['admin', 'super_admin', 'treasurer', 'chairperson', 'vice_chairperson', 'secretary', 'vice_secretary', 'patron'])) {
            throw new HttpException(403, 'forbidden', 'Staff only.');
        }
    }

    private function canAccessSuperAdmin(array $identity): bool
    {
        return AuthService::canAccessSuperAdmin($identity['roles'] ?? []);
    }
}
