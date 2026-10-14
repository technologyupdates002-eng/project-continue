<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupSetting;
use App\Services\Backup\BackupAudit;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupVerificationService;
use App\Services\Backup\RestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BackupAdminController extends Controller
{
    public function __construct(
        protected BackupManager $manager,
        protected BackupVerificationService $verifier,
        protected RestoreService $restore,
    ) {
    }

    /* ---------------- Pages ---------------- */

    public function page(): View
    {
        return view('admin.backups');
    }

    public function settingsPage(): View
    {
        return view('admin.settings');
    }

    /* ---------------- JSON API ---------------- */

    public function data(Request $request): JsonResponse
    {
        $this->applySettings();

        $query = Backup::query()->orderByDesc('created_at');

        if ($request->filled('include_safety') && $request->boolean('include_safety')) {
            // include all
        } else {
            $query->where('is_safety', false);
        }

        $backups = $query->get()->map(fn (Backup $b) => $this->serialize($b));

        return response()->json([
            'backups' => $backups,
            'stats' => $this->stats(),
            'settings' => $this->settingsView(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->applySettings();

        $type = strtolower((string) $request->input('type', Backup::TYPE_FULL));
        if (! in_array($type, [Backup::TYPE_FULL, Backup::TYPE_DATABASE, Backup::TYPE_STORAGE], true)) {
            return response()->json(['error' => ['message' => 'Invalid backup type.']], 422);
        }

        if ($type === Backup::TYPE_DATABASE && ! config('backup.database.enabled')) {
            return response()->json(['error' => ['message' => 'Database backups are disabled.']], 422);
        }
        if ($type === Backup::TYPE_STORAGE && ! config('backup.storage.enabled')) {
            return response()->json(['error' => ['message' => 'Storage backups are disabled.']], 422);
        }

        try {
            $backup = $this->manager->create($type, $this->adminId($request), $request->ip());
        } catch (\Throwable $e) {
            return response()->json(['error' => ['message' => $e->getMessage()]], 422);
        }

        return response()->json([
            'message' => 'Backup created and verified.',
            'backup' => $this->serialize($backup),
            'stats' => $this->stats(),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $backup = Backup::findOrFail($id);

        return response()->json(['backup' => $this->serialize($backup, true)]);
    }

    public function verify(string $id, Request $request): JsonResponse
    {
        $backup = Backup::findOrFail($id);

        if (! in_array($backup->status, [Backup::STATUS_COMPLETED, Backup::STATUS_FAILED], true)) {
            return response()->json(['error' => ['message' => 'Only completed/failed backups can be verified.']], 422);
        }

        $result = $this->verifier->verify($backup);

        BackupAudit::log(
            BackupAudit::BACKUP_VERIFIED,
            $this->adminId($request),
            ['backup_id' => $backup->id, 'status' => $result['status']],
            $request->ip()
        );

        return response()->json([
            'backup' => $this->serialize($backup, true),
            'result' => $result,
        ]);
    }

    public function download(string $id, Request $request)
    {
        $backup = Backup::findOrFail($id);

        if (! $backup->isDownloadable()) {
            abort(404, 'Backup is not available for download.');
        }

        $disk = Storage::disk($backup->disk);
        if (! $disk->exists($backup->path)) {
            abort(404, 'Backup file is missing.');
        }

        $extension = match ($backup->type) {
            Backup::TYPE_DATABASE => 'sql',
            Backup::TYPE_STORAGE => 'zip',
            default => 'zip',
        };
        $filename = 'khwwc-backup-'.$backup->id.'.'.$extension;

        BackupAudit::log(
            BackupAudit::BACKUP_DOWNLOADED,
            $this->adminId($request),
            ['backup_id' => $backup->id, 'type' => $backup->type],
            $request->ip()
        );

        return $disk->download($backup->path, $filename, [
            'Content-Type' => $backup->type === Backup::TYPE_DATABASE ? 'application/sql' : 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(string $id, Request $request): JsonResponse
    {
        $backup = Backup::findOrFail($id);

        if (in_array($backup->status, [Backup::STATUS_RUNNING, Backup::STATUS_RESTORING, Backup::STATUS_PENDING], true)) {
            return response()->json(['error' => ['message' => 'Cannot delete a backup that is currently in progress.']], 422);
        }

        // Do not delete a safety backup that is still referenced by an active restore.
        $referenced = Backup::where('safety_backup_id', $backup->id)
            ->where('status', Backup::STATUS_RESTORED)
            ->exists();
        if ($referenced) {
            return response()->json(['error' => ['message' => 'This safety backup is still required by a completed restore.']], 422);
        }

        $disk = Storage::disk($backup->disk);
        if ($disk->exists($backup->path)) {
            $disk->delete($backup->path);
        }

        $backup->status = Backup::STATUS_DELETED;
        $backup->save();

        BackupAudit::log(
            BackupAudit::BACKUP_DELETED,
            $this->adminId($request),
            ['backup_id' => $backup->id, 'type' => $backup->type],
            $request->ip()
        );

        return response()->json([
            'message' => 'Backup deleted.',
            'backup' => $this->serialize($backup),
            'stats' => $this->stats(),
        ]);
    }

    public function restore(string $id, Request $request): JsonResponse
    {
        $backup = Backup::findOrFail($id);

        $confirm = (string) $request->input('confirm_word', '');

        try {
            $result = $this->restore->restore($backup, $this->adminId($request), $request->ip(), $confirm);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => ['message' => $e->getMessage()],
                'backup' => $this->serialize($backup->fresh()),
            ], 422);
        }

        return response()->json([
            'message' => 'Restore completed.',
            'warning' => $result['warning'] ?? null,
            'safety_backup_id' => $result['safety_backup_id'],
            'verification' => $result['verification'],
            'backup' => $this->serialize($result['backup'], true),
            'stats' => $this->stats(),
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        if ($request->isMethod('put') || $request->isMethod('post')) {
            $validated = $request->validate([
                'database_enabled' => 'sometimes|boolean',
                'storage_enabled' => 'sometimes|boolean',
                'retention_keep_last' => 'nullable|integer|min:1|max:1000',
                'auto_prune' => 'sometimes|boolean',
                'automatic_backup_enabled' => 'sometimes|boolean',
                'min_free_space_mb' => 'sometimes|integer|min:10|max:100000',
            ]);

            $row = BackupSetting::current();
            $settings = array_merge($row->settings ?? [], $validated);
            $row->settings = $settings;
            $row->updated_by = $this->adminId($request);
            $row->save();

            BackupAudit::log(
                BackupAudit::BACKUP_SETTINGS_UPDATED,
                $this->adminId($request),
                ['settings' => $settings],
                $request->ip()
            );

            $this->applySettings();

            return response()->json(['settings' => $this->settingsView()]);
        }

        $this->applySettings();

        return response()->json(['settings' => $this->settingsView()]);
    }

    public function prune(Request $request): JsonResponse
    {
        $this->applySettings();

        $keep = (int) config('backup.retention.keep_last');
        if ($keep <= 0) {
            return response()->json([
                'message' => 'Manual retention only: no retention limit is configured, so nothing was auto-deleted.',
                'pruned' => 0,
            ]);
        }

        $candidates = Backup::where('status', Backup::STATUS_COMPLETED)
            ->where('is_safety', false)
            ->orderByDesc('created_at')
            ->get();

        $prunedIds = [];
        $disk = Storage::disk(config('backup.disk', 'backups'));

        foreach ($candidates->slice($keep) as $backup) {
            if ($disk->exists($backup->path)) {
                $disk->delete($backup->path);
            }
            $backup->status = Backup::STATUS_DELETED;
            $backup->save();
            $prunedIds[] = $backup->id;

            BackupAudit::log(
                BackupAudit::BACKUP_PRUNED,
                $this->adminId($request),
                ['backup_id' => $backup->id],
                $request->ip()
            );
        }

        return response()->json([
            'message' => 'Retention policy applied.',
            'pruned' => count($prunedIds),
            'pruned_ids' => $prunedIds,
            'stats' => $this->stats(),
        ]);
    }

    /* ---------------- Helpers ---------------- */

    protected function applySettings(): void
    {
        $settings = BackupSetting::current()->settings ?? [];
        if (! is_array($settings)) {
            return;
        }

        if (array_key_exists('database_enabled', $settings)) {
            config(['backup.database.enabled' => (bool) $settings['database_enabled']]);
        }
        if (array_key_exists('storage_enabled', $settings)) {
            config(['backup.storage.enabled' => (bool) $settings['storage_enabled']]);
        }
        if (array_key_exists('retention_keep_last', $settings)) {
            config(['backup.retention.keep_last' => $settings['retention_keep_last']]);
        }
        if (array_key_exists('auto_prune', $settings)) {
            config(['backup.retention.auto_prune' => (bool) $settings['auto_prune']]);
        }
        if (array_key_exists('min_free_space_mb', $settings)) {
            config(['backup.min_free_space_mb' => (int) $settings['min_free_space_mb']]);
        }
    }

    protected function settingsView(): array
    {
        return [
            'database_enabled' => (bool) config('backup.database.enabled'),
            'storage_enabled' => (bool) config('backup.storage.enabled'),
            'retention_keep_last' => config('backup.retention.keep_last') ? (int) config('backup.retention.keep_last') : null,
            'auto_prune' => (bool) config('backup.retention.auto_prune'),
            'automatic_backup_enabled' => (bool) (BackupSetting::current()->settings['automatic_backup_enabled'] ?? false),
            'min_free_space_mb' => (int) config('backup.min_free_space_mb'),
            'disk' => config('backup.disk'),
            'restore_confirmation_word' => config('backup.restore_confirmation_word'),
            'application_version' => config('backup.application_version'),
        ];
    }

    protected function stats(): array
    {
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

        return [
            'total' => (int) ($stats->total ?? 0),
            'completed' => (int) ($stats->completed ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
            'restored' => (int) ($stats->restored ?? 0),
            'verified' => (int) ($stats->verified ?? 0),
            'storage_used_bytes' => (int) ($stats->storage_used_bytes ?? 0),
            'latest_backup_id' => $latest?->id,
            'latest_verified_backup_id' => $latestVerified?->id,
            'safety_backups' => Backup::where('is_safety', true)->where('status', '!=', Backup::STATUS_DELETED)->count(),
        ];
    }

    protected function serialize(Backup $backup, bool $detailed = false): array
    {
        $data = [
            'id' => $backup->id,
            'name' => $backup->name,
            'type' => $backup->type,
            'status' => $backup->status,
            'size_bytes' => $backup->size_bytes,
            'size_human' => $this->human($backup->size_bytes),
            'checksum' => $backup->checksum,
            'verification_status' => $backup->verification_status,
            'verified_at' => $backup->verified_at?->toIso8601String(),
            'created_by' => $backup->created_by,
            'is_safety' => $backup->is_safety,
            'safety_backup_id' => $backup->safety_backup_id,
            'restored_from_backup_id' => $backup->restored_from_backup_id,
            'application_version' => $backup->application_version,
            'laravel_version' => $backup->laravel_version,
            'database_engine' => $backup->database_engine,
            'database_version' => $backup->database_version,
            'created_at' => $backup->created_at?->toIso8601String(),
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'failure_reason' => $backup->failure_reason,
            'downloadable' => $backup->isDownloadable(),
            'restorable' => $backup->isRestorable(),
        ];

        if ($detailed) {
            $data['metadata'] = $backup->metadata;
            $data['verification_details'] = $backup->verification_details;
        }

        return $data;
    }

    protected function adminId(Request $request): string
    {
        return (string) $request->attributes->get('admin_user_id', 'unknown');
    }

    protected function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }
}
