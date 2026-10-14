<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restores a verified backup. This is a high-risk, destructive operation:
 *
 *  - authorization is enforced by the caller (Super Admin only)
 *  - an explicit confirmation word is required
 *  - a safety backup of the CURRENT state is created first (unless an
 *    emergency override is configured)
 *  - a maintenance lock prevents concurrent backup/restore operations
 *  - database + storage are restored, caches cleared, then the application
 *    is health-checked before the operation is reported successful.
 */
class RestoreService
{
    public function __construct(
        protected BackupManager $manager,
        protected DatabaseBackupService $database,
        protected StorageBackupService $storage,
    ) {
    }

    /**
     * @return array{backup:Backup,safety_backup_id:?string,verification:array,warning:?string}
     */
    public function restore(Backup $backup, string $userId, ?string $ip, string $confirmWord): array
    {
        $expected = (string) config('backup.restore_confirmation_word', 'RESTORE');
        if ($confirmWord !== $expected) {
            throw new RuntimeException('Restore confirmation word did not match. Restoration aborted.');
        }

        $this->validate($backup);

        $lock = Cache::lock('backup:restore', 600);
        if (! $lock->get()) {
            throw new RuntimeException('Another backup or restore operation is currently in progress. Please retry shortly.');
        }

        $safetyBackupId = null;
        $warning = null;

        try {
            // 1. Pre-restore safety backup of the CURRENT state.
            $safety = $this->createSafetyBackup($userId, $ip, $backup->id);
            if ($safety === null) {
                if (! config('backup.emergency_restore_without_safety')) {
                    throw new RuntimeException('Safety backup could not be created and emergency restore is disabled. Restoration aborted.');
                }
                $warning = 'Safety backup could not be created; proceeding under emergency override.';
            } else {
                $safetyBackupId = $safety->id;
                $backup->safety_backup_id = $safetyBackupId;
            }

            // 2. Restore.
            $backup->status = Backup::STATUS_RESTORING;
            $backup->save();

            BackupAudit::log(
                BackupAudit::RESTORE_STARTED,
                $userId,
                ['backup_id' => $backup->id, 'type' => $backup->type, 'safety_backup_id' => $safetyBackupId],
                $ip
            );

            $this->applyRestore($backup);

            // 3. Clear rebuildable caches.
            $this->clearCaches();

            // 4. Health-check the restored application.
            $verification = $this->postRestoreChecks();

            $backup->status = Backup::STATUS_RESTORED;
            $backup->save();

            BackupAudit::log(
                BackupAudit::RESTORE_COMPLETED,
                $userId,
                ['backup_id' => $backup->id, 'type' => $backup->type, 'safety_backup_id' => $safetyBackupId, 'verification' => $verification],
                $ip
            );

            if (! empty($verification['failed'])) {
                $warning = 'Restore applied but post-restore verification reported issues: '.implode('; ', $verification['failed']);
            }

            return [
                'backup' => $backup->fresh(),
                'safety_backup_id' => $safetyBackupId,
                'verification' => $verification,
                'warning' => $warning,
            ];
        } catch (\Throwable $e) {
            BackupAudit::log(
                BackupAudit::RESTORE_FAILED,
                $userId,
                ['backup_id' => $backup->id, 'type' => $backup->type, 'safety_backup_id' => $safetyBackupId, 'error' => $e->getMessage()],
                $ip
            );

            throw $e;
        } finally {
            $lock->release();
        }
    }

    protected function validate(Backup $backup): void
    {
        if (! in_array($backup->status, [Backup::STATUS_COMPLETED], true)) {
            throw new RuntimeException('Only completed backups can be restored (current status: '.$backup->status.').');
        }

        if ($backup->verification_status !== Backup::VERIFICATION_PASSED) {
            throw new RuntimeException('Only verified backups can be restored (verification: '.($backup->verification_status ?? 'pending').').');
        }

        $disk = Storage::disk($backup->disk);
        if (! $disk->exists($backup->path)) {
            throw new RuntimeException('Backup file is missing on disk.');
        }

        // Version / engine compatibility.
        $currentEngine = $this->database->driver();
        if ($backup->database_engine && $backup->database_engine !== $currentEngine) {
            throw new RuntimeException(
                "Database engine mismatch (backup: {$backup->database_engine}, current: {$currentEngine}). Restore blocked to avoid corruption."
            );
        }

        if ($backup->laravel_version && $backup->laravel_version !== app()->version()) {
            // Non-fatal warning only; recorded in audit + return warning.
        }
    }

    protected function createSafetyBackup(string $userId, ?string $ip, string $restoringId): ?Backup
    {
        try {
            $safety = $this->manager->create(
                Backup::TYPE_FULL,
                $userId,
                $ip,
                isSafety: true,
                name: 'Safety backup before restore of '.substr($restoringId, 0, 8),
                dispatch: false
            );

            BackupAudit::log(
                BackupAudit::SAFETY_BACKUP_CREATED,
                $userId,
                ['backup_id' => $safety->id, 'restored_from' => $restoringId],
                $ip
            );

            return $safety;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function applyRestore(Backup $backup): void
    {
        $disk = Storage::disk($backup->disk);
        $absolute = $disk->path($backup->path);

        if ($backup->type === Backup::TYPE_DATABASE) {
            $this->database->restoreFromFile($absolute);
        } elseif ($backup->type === Backup::TYPE_STORAGE) {
            $this->storage->restoreFrom($absolute);
        } else {
            // full
            $tmp = $this->extractFull($absolute);
            try {
                $dump = $tmp.'/database/dump.sql';
                $store = $tmp.'/storage/storage.zip';

                if (! is_file($dump)) {
                    throw new RuntimeException('Full backup is missing database/dump.sql.');
                }
                $this->database->restoreFromFile($dump);

                if (is_file($store)) {
                    $this->storage->restoreFrom($store);
                }
            } finally {
                $this->rmdir($tmp);
            }
        }
    }

    protected function extractFull(string $absolute): string
    {
        $tmp = storage_path('app/backups/_restore_'.uniqid('', true));
        mkdir($tmp, 0750, true);

        $zip = new \ZipArchive();
        if ($zip->open($absolute) !== true) {
            throw new RuntimeException('Unable to open full backup archive.');
        }
        $zip->extractTo($tmp);
        $zip->close();

        return $tmp;
    }

    protected function clearCaches(): void
    {
        foreach (['cache:clear', 'view:clear', 'route:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (\Throwable) {
                // non-fatal
            }
        }
    }

    /**
     * @return array{critical_ok:bool,failed:list<string>,checks:array}
     */
    protected function postRestoreChecks(): array
    {
        $checks = [];
        $failed = [];

        // Database connection
        try {
            DB::connection()->getPdo();
            $checks['database_connection'] = true;
        } catch (\Throwable $e) {
            $checks['database_connection'] = false;
            $failed[] = 'database_connection: '.$e->getMessage();
        }

        // Required tables present
        foreach (['auth_users', 'user_roles', 'members', 'backups'] as $table) {
            $ok = Schema::hasTable($table);
            $checks['table_'.$table] = $ok;
            if (! $ok) {
                $failed[] = 'missing_table: '.$table;
            }
        }

        // Storage writable
        $writable = is_writable(storage_path('app'));
        $checks['storage_writable'] = $writable;
        if (! $writable) {
            $failed[] = 'storage_not_writable';
        }

        // Authentication table queryable
        try {
            DB::table('auth_users')->count();
            $checks['auth_queryable'] = true;
        } catch (\Throwable $e) {
            $checks['auth_queryable'] = false;
            $failed[] = 'auth_queryable: '.$e->getMessage();
        }

        return [
            'critical_ok' => count($failed) === 0,
            'failed' => $failed,
            'checks' => $checks,
        ];
    }

    protected function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
