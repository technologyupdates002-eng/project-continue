<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\DocumentAuditService;
use App\Services\MemberRoleResolver;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Member Documents — primary controller.
 *
 * Owns the full lifecycle:
 *   - upload:    member uploads a file → row created + audit + storage object updated
 *   - replace:   member uploads a new version → old row soft-deleted, new row references it
 *   - update:    edit metadata (notes, expiry) — no file change
 *   - show:      fetch a single row
 *   - destroy:   soft-delete (member owner) or hard-delete (staff)
 *   - approve/reject: staff-only, transitions status with review metadata
 *   - myDocuments: scoped to the caller
 *   - mySummary: counts by status for the caller's profile completion widget
 *   - myTypes:   list of types + per-type upload status (uploaded/missing/expired/expiring)
 *   - types:     full catalog of document types
 */
class DocumentController extends Controller
{
    private const MEMBER_UPLOAD_TYPES = ['national_id', 'passport'];

    public function index(Request $request): JsonResponse
    {
        // Members can only see their own; staff can see all.
        $identity = $request->attributes->get('identity');
        $isStaff = $this->isStaff($identity);

        $q = DB::table('documents')->whereNull('deleted_at');
        if (! $isStaff) {
            $q->where('member_id', $identity['member_id'] ?? '');
        }

        $this->applyFilters($q, $request);

        $total = (clone $q)->count();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $rows = $q->orderByDesc('created_at')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->shape($r))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }
        $this->assertCanRead($row, $request);

        DocumentAuditService::log([
            'action' => 'view',
            'bucket' => 'documents',
            'path' => $row->file_url,
            'request' => $request,
            'extra' => ['document_id' => $id],
        ]);

        return response()->json(['data' => $this->shape($row, true)]);
    }

    public function types(Request $request): JsonResponse
    {
        $rows = DB::table('document_types')->where('is_active', 1)->orderBy('sort_order')->get();
        return response()->json(['data' => $rows->map(fn ($r) => (array) $r)->all()]);
    }

    public function myDocuments(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $memberId = $identity['member_id'] ?? null;
        if (! $memberId) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('documents')
            ->where('member_id', $memberId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->shape($r, true))->all()]);
    }

    public function mySummary(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $memberId = $identity['member_id'] ?? null;
        if (! $memberId) {
            return response()->json(['data' => $this->emptySummary()]);
        }

        $types = DB::table('document_types')->where('is_active', 1)->orderBy('sort_order')->get();
        $uploaded = DB::table('documents')
            ->where('member_id', $memberId)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('document_type_code');

        $required = 0;
        $uploadedCount = 0;
        $approvedCount = 0;
        $pendingCount = 0;
        $rejectedCount = 0;
        $expiredCount = 0;
        $expiringSoonCount = 0;
        $perType = [];

        foreach ($types as $t) {
            $code = $t->code;
            $typeRows = $uploaded->get($code, collect());
            $latest = $typeRows->sortByDesc('created_at')->first();
            $isUploaded = $latest !== null;
            $isApproved = $isUploaded && $latest->status === 'approved';
            $isExpired = $latest && $latest->expires_at && strtotime($latest->expires_at) < time();
            $isExpiring = $latest && $latest->expires_at && strtotime($latest->expires_at) >= time() && strtotime($latest->expires_at) <= time() + 30 * 86400;

            if ($t->is_required) {
                $required++;
                if ($isApproved) {
                    $uploadedCount++;
                }
            }
            if ($isUploaded) {
                $latestStatus = $latest->status;
                if ($latestStatus === 'pending') $pendingCount++;
                if ($latestStatus === 'approved') $approvedCount++;
                if ($latestStatus === 'rejected') $rejectedCount++;
            }
            if ($isExpired) $expiredCount++;
            if ($isExpiring) $expiringSoonCount++;

            $perType[] = [
                'code' => $code,
                'label' => $t->label,
                'category' => $t->category,
                'is_required' => (bool) $t->is_required,
                'is_uploaded' => $isUploaded,
                'is_approved' => $isApproved,
                'is_expired' => (bool) $isExpired,
                'is_expiring_soon' => (bool) $isExpiring,
                'document' => $isUploaded ? $this->shape($latest, true) : null,
            ];
        }

        $completion = $required > 0 ? round(($uploadedCount / $required) * 100) : 100;

        return response()->json(['data' => [
            'completion_percent' => $completion,
            'required_count' => $required,
            'uploaded_count' => $uploadedCount,
            'pending_count' => $pendingCount,
            'approved_count' => $approvedCount,
            'rejected_count' => $rejectedCount,
            'expired_count' => $expiredCount,
            'expiring_soon_count' => $expiringSoonCount,
            'types' => $perType,
        ]]);
    }

    public function myTypes(Request $request): JsonResponse
    {
        return $this->mySummary($request);
    }

    public function upload(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $memberId = $identity['member_id'] ?? null;
        if (! $memberId) {
            throw new HttpException(403, 'forbidden', 'Member profile required.');
        }

        $validator = Validator::make($request->all(), [
            'document_type_code' => 'required|string|max:40',
            'file' => 'required|file',
            'notes' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            throw new HttpException(422, 'validation_failed', $validator->errors()->first());
        }

        $type = DB::table('document_types')->where('code', $request->input('document_type_code'))->where('is_active', 1)->first();
        if (! $type) {
            throw new HttpException(422, 'invalid_type', 'Unknown or inactive document type.');
        }
        if (in_array($request->input('document_type_code'), self::MEMBER_UPLOAD_TYPES, true) === false) {
            throw new HttpException(422, 'invalid_type', 'Only national ID and passport documents may be uploaded.');
        }

        $file = $request->file('file');
        $this->validateAgainstType($file, $type);

        $id = (string) Uuid::v4();
        $userId = $identity['user_id'] ?? null;
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $safeName = $id . '.' . $ext;
        $bucket = 'documents';
        $relativePath = $memberId . '/' . $safeName;
        $fullPath = $memberId . '/' . $safeName;
        $storageRoot = storage_path('app/private/' . $bucket);
        $targetDir = $storageRoot . '/' . $memberId;
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new HttpException(500, 'storage_error', 'Could not create storage directory.');
        }
        // Capture metadata before move (after move, getSize() may fail).
        $originalName = $file->getClientOriginalName();
        $mime = $file->getClientMimeType();
        $size = (int) $file->getSize();
        $file->move($targetDir, $safeName);

        $status = $type->requires_approval ? 'pending' : 'approved';

        DB::table('documents')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'file_name' => $originalName,
            'file_type' => $type->category,
            'file_url' => $fullPath,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'document_type_code' => $type->code,
            'uploaded_by' => $userId,
            'status' => $status,
            'notes' => $request->input('notes'),
            'expires_at' => $request->input('expires_at'),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $memberName = DB::table('members')->where('id', $memberId)->value('name') ?: 'A member';
        app(\App\Services\AdminNotificationService::class)->notifyAdmins(
            'New document uploaded',
            "{$memberName} uploaded {$originalName} for review.",
            'document_upload',
            '/admin/documents',
            'document-upload:' . $id
        );

        if (DB::getSchemaBuilder()->hasTable('storage_objects')) {
            DB::table('storage_objects')->updateOrInsert(
                ['bucket' => $bucket, 'object_path' => $fullPath],
                [
                    'id' => (string) Uuid::v4(),
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'is_public' => 0,
                    'owner_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DocumentAuditService::log([
            'action' => 'upload',
            'bucket' => $bucket,
            'path' => $fullPath,
            'request' => $request,
            'extra' => ['document_id' => $id, 'document_type' => $type->code, 'size_bytes' => $size],
        ]);

        $row = DB::table('documents')->where('id', $id)->first();
        return response()->json(['data' => $this->shape($row, true)], 201);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }
        $identity = $request->attributes->get('identity');
        $this->assertCanWrite($row, $identity);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'notes' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            throw new HttpException(422, 'validation_failed', $validator->errors()->first());
        }

        $type = DB::table('document_types')->where('code', $row->document_type_code)->first();
        if (in_array($row->document_type_code, self::MEMBER_UPLOAD_TYPES, true) === false) {
            throw new HttpException(422, 'invalid_type', 'Only national ID and passport documents may be replaced.');
        }
        if (! $type) {
            $type = (object) ['category' => $row->file_type, 'requires_approval' => true, 'requires_expiry' => false, 'allowed_mime' => null, 'max_size_bytes' => 10 * 1024 * 1024];
        }
        $file = $request->file('file');
        $this->validateAgainstType($file, $type);

        // Soft-delete the old row and point a new row at the new file.
        $oldFileUrl = $row->file_url;
        $oldVersion = (int) ($row->version ?? 1);
        $newId = (string) Uuid::v4();
        $memberId = $row->member_id;
        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $safeName = $newId . '.' . $ext;
        $bucket = 'documents';
        $newPath = $memberId . '/' . $safeName;

        $storageRoot = storage_path('app/private/' . $bucket);
        $targetDir = $storageRoot . '/' . $memberId;
        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new HttpException(500, 'storage_error', 'Could not create storage directory.');
        }
        // Capture metadata before move.
        $originalName = $file->getClientOriginalName();
        $mime = $file->getClientMimeType();
        $size = (int) $file->getSize();
        $file->move($targetDir, $safeName);

        DB::table('documents')->where('id', $id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $status = $type->requires_approval ? 'pending' : 'approved';

        DB::table('documents')->insert([
            'id' => $newId,
            'member_id' => $memberId,
            'file_name' => $originalName,
            'file_type' => $type->category,
            'file_url' => $newPath,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'document_type_code' => $row->document_type_code,
            'uploaded_by' => $identity['user_id'] ?? null,
            'status' => $status,
            'notes' => $request->input('notes') ?? $row->notes,
            'expires_at' => $request->input('expires_at') ?? $row->expires_at,
            'version' => $oldVersion + 1,
            'replaces_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Remove the old physical file.
        $oldAbs = $storageRoot . '/' . $oldFileUrl;
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }

        DocumentAuditService::log([
            'action' => 'replace',
            'bucket' => $bucket,
            'path' => $newPath,
            'request' => $request,
            'extra' => ['document_id' => $newId, 'replaces_id' => $id, 'version' => $oldVersion + 1],
        ]);

        $row = DB::table('documents')->where('id', $newId)->first();
        return response()->json(['data' => $this->shape($row, true)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }
        $identity = $request->attributes->get('identity');
        $this->assertCanWrite($row, $identity);

        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:1000',
            'expires_at' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            throw new HttpException(422, 'validation_failed', $validator->errors()->first());
        }

        $patch = [];
        if ($request->has('notes')) $patch['notes'] = $request->input('notes');
        if ($request->has('expires_at')) $patch['expires_at'] = $request->input('expires_at');
        $patch['updated_at'] = now();

        DB::table('documents')->where('id', $id)->update($patch);

        DocumentAuditService::log([
            'action' => 'update',
            'bucket' => 'documents',
            'path' => $row->file_url,
            'request' => $request,
            'extra' => ['document_id' => $id, 'patch' => array_keys($patch)],
        ]);

        $row = DB::table('documents')->where('id', $id)->first();
        return response()->json(['data' => $this->shape($row, true)]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }
        $identity = $request->attributes->get('identity');
        $this->assertCanWrite($row, $identity);

        $isStaff = $this->isStaff($identity);
        $hardDelete = $isStaff && $request->boolean('hard_delete');

        if ($hardDelete) {
            // Staff may hard-delete (with audit) — also unlink the file.
            $abs = storage_path('app/private/documents/' . $row->file_url);
            if (is_file($abs)) {
                @unlink($abs);
            }
            DB::table('documents')->where('id', $id)->delete();
        } else {
            DB::table('documents')->where('id', $id)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DocumentAuditService::log([
            'action' => $hardDelete ? 'hard_delete' : 'delete',
            'bucket' => 'documents',
            'path' => $row->file_url,
            'request' => $request,
            'extra' => ['document_id' => $id, 'hard' => $hardDelete],
        ]);

        return response()->json(['success' => true]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! $this->isStaff($identity)) {
            throw new HttpException(403, 'forbidden', 'Only staff can approve documents.');
        }
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }
        if ($row->status === 'approved') {
            return response()->json(['data' => $this->shape($row, true)]);
        }

        DB::table('documents')->where('id', $id)->update([
            'status' => 'approved',
            'reviewed_by' => $identity['user_id'] ?? null,
            'reviewed_at' => now(),
            'review_notes' => $request->input('notes'),
            'updated_at' => now(),
        ]);
        $row = DB::table('documents')->where('id', $id)->first();

        DocumentAuditService::log([
            'action' => 'approve',
            'bucket' => 'documents',
            'path' => $row->file_url,
            'request' => $request,
            'extra' => ['document_id' => $id, 'notes' => $request->input('notes')],
        ]);

        return response()->json(['data' => $this->shape($row, true)]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! $this->isStaff($identity)) {
            throw new HttpException(403, 'forbidden', 'Only staff can reject documents.');
        }
        $row = DB::table('documents')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }

        $notes = $request->input('notes');
        if (! $notes) {
            throw new HttpException(422, 'notes_required', 'A reason is required to reject a document.');
        }

        DB::table('documents')->where('id', $id)->update([
            'status' => 'rejected',
            'reviewed_by' => $identity['user_id'] ?? null,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'updated_at' => now(),
        ]);
        $row = DB::table('documents')->where('id', $id)->first();

        DocumentAuditService::log([
            'action' => 'reject',
            'bucket' => 'documents',
            'path' => $row->file_url,
            'request' => $request,
            'extra' => ['document_id' => $id, 'notes' => $notes],
        ]);

        return response()->json(['data' => $this->shape($row, true)]);
    }

    // -- Helpers ---------------------------------------------------------------------

    private function applyFilters($q, Request $request): void
    {
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($type = $request->query('document_type_code')) {
            $q->where('document_type_code', $type);
        }
        if ($memberId = $request->query('member_id')) {
            $q->where('member_id', $memberId);
        }
        if ($qText = $request->query('q')) {
            $q->where(function ($w) use ($qText) {
                $w->where('file_name', 'like', '%' . $qText . '%')
                  ->orWhere('notes', 'like', '%' . $qText . '%')
                  ->orWhere('document_type_code', 'like', '%' . $qText . '%');
            });
        }
        if ($from = $request->query('uploaded_from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('uploaded_to')) {
            $q->where('created_at', '<=', $to);
        }
    }

    private function validateAgainstType($file, $type): void
    {
        // Reject executables/script extensions even if MIME lies.
        $blockedExt = ['php', 'phtml', 'phar', 'sh', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'js', 'ts', 'jar', 'war', 'asp', 'aspx', 'jsp'];
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        if (in_array($ext, $blockedExt, true)) {
            throw new HttpException(415, 'blocked_extension', 'Executable files are not allowed.');
        }

        $maxSize = (int) ($type->max_size_bytes ?? 10 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            throw new HttpException(413, 'file_too_large', 'File exceeds ' . round($maxSize / 1024 / 1024, 1) . ' MB.');
        }
        $allowed = [];
        if (! empty($type->allowed_mime)) {
            $decoded = is_string($type->allowed_mime) ? json_decode($type->allowed_mime, true) : $type->allowed_mime;
            $allowed = is_array($decoded) ? $decoded : [];
        }
        if (! empty($allowed) && ! in_array($file->getClientMimeType(), $allowed, true)) {
            throw new HttpException(415, 'unsupported_media_type', 'MIME ' . $file->getClientMimeType() . ' not allowed for ' . ($type->code ?? 'this type'));
        }
        // For images, also verify the file is a real image (rejects corrupted uploads).
        if (str_starts_with((string) $file->getClientMimeType(), 'image/')) {
            $info = @getimagesize($file->getRealPath());
            if ($info === false) {
                throw new HttpException(400, 'corrupted_file', 'Uploaded file is not a valid image.');
            }
        }
    }

    private function isStaff(array $identity): bool
    {
        $roles = $identity['roles'] ?? [];
        return (bool) array_intersect($roles, ['admin', 'super_admin', 'treasurer', 'chairperson', 'vice_chairperson', 'secretary', 'vice_secretary', 'patron']);
    }

    private function assertCanRead($row, Request $request): void
    {
        $identity = $request->attributes->get('identity');
        if ($this->isStaff($identity)) {
            return;
        }
        if (($identity['member_id'] ?? null) === $row->member_id) {
            return;
        }
        throw new HttpException(403, 'forbidden', 'You can only access your own documents.');
    }

    private function assertCanWrite($row, array $identity): void
    {
        if ($this->isStaff($identity)) {
            return;
        }
        if (($identity['member_id'] ?? null) === $row->member_id) {
            return;
        }
        throw new HttpException(403, 'forbidden', 'You can only modify your own documents.');
    }

    private function shape($row, bool $includeUrl = false): array
    {
        $arr = (array) $row;
        if ($includeUrl && ! empty($row->file_url)) {
            $arr['download_url'] = URL::signedRoute('storage.download', [
                'bucket' => 'documents',
                'path' => $row->file_url,
            ], now()->addMinutes(15));
        }
        return $arr;
    }

    private function emptySummary(): array
    {
        return [
            'completion_percent' => 0,
            'required_count' => 0,
            'uploaded_count' => 0,
            'pending_count' => 0,
            'approved_count' => 0,
            'rejected_count' => 0,
            'expired_count' => 0,
            'expiring_soon_count' => 0,
            'types' => [],
        ];
    }
}
