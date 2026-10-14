<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use App\Services\DocumentAuditService;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class StorageController extends Controller
{
    /**
     * Default MIME types accepted across all buckets (image-only legacy).
     * Buckets can override via the BUCKET_POLICY map.
     */
    public const DEFAULT_ALLOWED_MIME = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/webp',
    ];

    public const DEFAULT_MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2 MB

    /**
     * Per-bucket policy. Each entry overrides the default with:
     *   - allowed_mime:  array of MIME types accepted
     *   - max_size:      maximum file size in bytes
     *   - is_public:     if true, the download route serves without auth
     *   - audit:         if true, writes a row to document_audit_log on every download/delete
     */
    public const BUCKET_POLICY = [
        'signatures' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_size' => 2 * 1024 * 1024,
            'is_public' => false,
            'audit' => true,
        ],
        'documents' => [
            'allowed_mime' => [
                'image/png', 'image/jpeg', 'image/webp', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                'text/csv',
            ],
            'max_size' => 10 * 1024 * 1024, // 10 MB
            'is_public' => false,
            'audit' => true,
        ],
        'profile-images' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_size' => 3 * 1024 * 1024,
            'is_public' => true,
            'audit' => false,
        ],
        'profile-photos' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp'],
            'max_size' => 3 * 1024 * 1024,
            'is_public' => true,
            'audit' => false,
        ],
        'website-media' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
            'max_size' => 5 * 1024 * 1024,
            'is_public' => true,
            'audit' => false,
        ],
        'receipts' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
            'max_size' => 5 * 1024 * 1024,
            'is_public' => false,
            'audit' => true,
        ],
        'memos' => [
            'allowed_mime' => ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'],
            'max_size' => 5 * 1024 * 1024,
            'is_public' => false,
            'audit' => true,
        ],
    ];

    /**
     * Resolve the effective policy for a bucket. Unknown buckets use defaults
     * (image-only, 2 MB, private, audited).
     */
    public static function policyFor(string $bucket): array
    {
        $defaults = [
            'allowed_mime' => self::DEFAULT_ALLOWED_MIME,
            'max_size' => self::DEFAULT_MAX_SIZE_BYTES,
            'is_public' => false,
            'audit' => true,
        ];
        $p = self::BUCKET_POLICY[$bucket] ?? [];
        return array_merge($defaults, $p);
    }

    public function upload(Request $request, string $bucket, ?string $path = null)
    {
        if ($bucket === 'website-media') {
            $roles = $request->attributes->get('identity')['roles'] ?? [];
            abort_unless(array_intersect($roles, ['admin', 'super_admin']), 403, 'Only website administrators can upload website media.');
        }

        if (! $request->hasFile('file')) {
            throw new HttpException(400, 'no_file', 'Attach the file as multipart form field "file"');
        }

        $file = $request->file('file');
        $policy = self::policyFor($bucket);

        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($mimeType, $policy['allowed_mime'], true)) {
            throw new HttpException(415, 'unsupported_media_type', 'MIME type ' . $mimeType . ' is not allowed for bucket ' . $bucket);
        }

        $sizeBytes = (int) $file->getSize();
        if ($sizeBytes <= 0) {
            throw new HttpException(400, 'empty_file', 'Uploaded file is empty.');
        }
        if ($sizeBytes > $policy['max_size']) {
            throw new HttpException(413, 'file_too_large', 'File exceeds the maximum size of ' . round($policy['max_size'] / 1024 / 1024, 1) . ' MB.');
        }

        // Reject executables/script extensions even if MIME lies.
        $blockedExt = ['php', 'phtml', 'phar', 'sh', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'js', 'ts', 'jar', 'war', 'asp', 'aspx', 'jsp'];
        if (in_array($ext, $blockedExt, true)) {
            throw new HttpException(415, 'blocked_extension', 'Executable files are not allowed.');
        }

        // For images, also verify with getimagesize() to reject malformed files.
        if (str_starts_with($mimeType, 'image/')) {
            $info = @getimagesize($file->getRealPath());
            if ($info === false) {
                throw new HttpException(400, 'corrupted_file', 'Uploaded file is not a valid image.');
            }
        }

        $storageRoot = storage_path('app/private/' . $bucket);
        $safePath = $this->safePath($path ?? $file->getClientOriginalName());
        $targetDir = dirname($storageRoot . '/' . $safePath);

        if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
            throw new HttpException(500, 'storage_error', 'Could not create the storage directory');
        }

        $file->move($targetDir, basename($safePath));

        DB::table('storage_objects')->updateOrInsert(
                ['bucket' => $bucket, 'object_path' => $safePath],
                [
                    'id' => Uuid::v4(),
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                    'is_public' => $policy['is_public'] ? 1 : 0,
                    'owner_id' => optional($request->attributes->get('identity'))['user_id'] ?? null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

        if ($policy['audit']) {
            $this->audit('upload', $bucket, $safePath, $request);
        }

        return response()->json([
            'bucket' => $bucket,
            'path' => $safePath,
            'publicUrl' => '/api/storage/v1/object/' . $bucket . '/' . $safePath,
            'public_url' => '/api/storage/v1/object/' . $bucket . '/' . $safePath,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
        ], 201);
    }

    /**
     * Download a file.
     * - Public buckets (e.g. profile-photos): no auth required.
     * - Private buckets: must be authenticated AND the caller must own the file OR be staff.
     * - Signed URLs (signature param present + valid) bypass ownership check.
     */
    public function download(Request $request, string $bucket, ?string $path = null)
    {
        $safePath = $this->safePath($path ?? '');
        $policy = self::policyFor($bucket);

        $file = storage_path('app/private/' . $bucket . '/' . $safePath);

        if (! is_file($file)) {
            throw new HttpException(404, 'not_found', 'File not found');
        }

        $identity = $request->attributes->get('identity');
        $hasValidSignature = $request->hasValidSignature();

        if (! $policy['is_public']) {
            // Private bucket: require auth OR valid signed URL.
            if (! $identity && ! $hasValidSignature) {
                throw new HttpException(401, 'unauthorized', 'Authentication required.');
            }

            // If no signature, the caller must be staff OR the file owner.
            if ($identity && ! $hasValidSignature) {
                $this->assertCanRead($bucket, $safePath, $identity);
            }
        }

        if ($policy['audit']) {
            $this->audit('download', $bucket, $safePath, $request);
        }

        return response()->file($file, [
            'Content-Type' => $this->guessMime($file),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function signedUrl(Request $request, string $bucket, ?string $path = null)
    {
        $safePath = $this->safePath($path ?? '');
        $policy = self::policyFor($bucket);

        $identity = $request->attributes->get('identity');
        if (! $identity) {
            throw new HttpException(401, 'unauthorized', 'Authentication required.');
        }

        $file = storage_path('app/private/' . $bucket . '/' . $safePath);
        if (! is_file($file)) {
            throw new HttpException(404, 'not_found', 'File not found');
        }

        if (! $policy['is_public']) {
            $this->assertCanRead($bucket, $safePath, $identity);
        }

        $expires = (int) $request->query('expires', 300);
        $expires = max(60, min($expires, 3600));

        $signedUrl = URL::signedRoute(
            'storage.download',
            ['bucket' => $bucket, 'path' => $safePath],
            now()->addSeconds($expires)
        );

        if ($policy['audit']) {
            $this->audit('signed_url', $bucket, $safePath, $request);
        }

        return response()->json([
            'signedUrl' => $signedUrl,
            'expiresIn' => $expires,
            'bucket' => $bucket,
            'path' => $safePath,
        ]);
    }

    public function delete(Request $request, string $bucket, ?string $path = null)
    {
        $safePath = $this->safePath($path ?? '');
        $file = storage_path('app/private/' . $bucket . '/' . $safePath);

        $identity = $request->attributes->get('identity');
        if (! $identity) {
            throw new HttpException(401, 'unauthorized', 'Authentication required.');
        }

        // Only staff can delete; members cannot delete files directly.
        $roles = $identity['roles'] ?? [];
        if ($bucket === 'website-media') {
            abort_unless(array_intersect($roles, ['admin', 'super_admin']), 403, 'Only website administrators can delete website media.');
        }
        $staff = array_intersect($roles, ['admin', 'super_admin', 'secretary', 'vice_secretary']);
        if (empty($staff)) {
            // Members can delete their own uploads only via DocumentController (which also drops the DB row).
            throw new HttpException(403, 'forbidden', 'Only staff can delete files directly.');
        }

        $existed = is_file($file);
        if ($existed) {
            @unlink($file);
        }

        DB::table('storage_objects')->where('bucket', $bucket)->where('object_path', $safePath)->delete();

        $this->audit('delete', $bucket, $safePath, $request, ['existed' => $existed]);

        return response()->json(['success' => true, 'existed' => $existed]);
    }

    /**
     * Verify the identity can read this file. Members must own the file (their user_id is the
     * storage_object.owner_id OR the file_url is referenced by a documents row they own).
     */
    protected function assertCanRead(string $bucket, string $safePath, array $identity): void
    {
        $roles = $identity['roles'] ?? [];
        $isStaff = (bool) array_intersect($roles, ['admin', 'super_admin', 'treasurer', 'chairperson', 'vice_chairperson', 'secretary', 'vice_secretary', 'patron']);

        if ($isStaff) {
            return;
        }

        $userId = $identity['user_id'] ?? null;
        $memberId = $identity['member_id'] ?? null;

        // Try to match by storage_objects.owner_id.
        if ($userId) {
            $row = DB::table('storage_objects')
                ->where('bucket', $bucket)
                ->where('object_path', $safePath)
                ->first();
            if ($row && $row->owner_id === $userId) {
                return;
            }
        }

        // Try to match by documents.file_url + documents.member_id.
        if ($bucket === 'documents' && $memberId) {
            $exists = DB::table('documents')
                ->where('file_url', $safePath)
                ->where('member_id', $memberId)
                ->exists();
            if ($exists) {
                return;
            }
        }

        throw new HttpException(403, 'forbidden', 'You do not have access to this file.');
    }

    protected function audit(string $action, string $bucket, string $path, Request $request, array $extra = []): void
    {
        try {
            DocumentAuditService::log([
                'action' => $action,
                'bucket' => $bucket,
                'path' => $path,
                'request' => $request,
                'extra' => $extra,
            ]);
        } catch (\Throwable $e) {
            // Audit must never block the request.
            Log::warning('storage audit failed: ' . $e->getMessage());
        }
    }

    protected function guessMime(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }

    protected function safePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new HttpException(400, 'invalid_path', 'Invalid object path');
        }

        return preg_replace('/[^A-Za-z0-9._\-\/]/', '_', $path) ?? $path;
    }
}
