<?php

namespace App\Services\Backup;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * High-level orchestration for backup creation and verification.
 *
 * Produces database, storage or full backups on the private backups disk,
 * records metadata, runs strict verification and writes audit events.
 * Long-running generation is dispatched to CreateBackupJob (which runs
 * inline under the sync queue used in local/testing).
 */
class BackupManager
{
    public function __construct(
        protected DatabaseBackupService $database,
        protected StorageBackupService $storage,
        protected BackupVerificationService $verifier,
    ) {
    }

    /**
     * Begin a backup. Returns the metadata record (status may be running
     * when processed asynchronously).
     */
    public function create(
        string $type,
        string $createdBy,
        ?string $ip = null,
        bool $isSafety = false,
        ?string $name = null,
        bool $dispatch = true
    ): Backup {
        $type = strtolower($type);
        $this->assertTypeEnabled($type);

        $this->ensureDiskSpace($this->estimateBytes($type));

        $backup = new Backup();
        $backup->id = \App\Support\Uuid::v4();
        $backup->name = $name ?: $this->defaultName($type, $isSafety);
        $backup->type = $type;
        $backup->disk = config('backup.disk', 'backups');
        $backup->path = $this->relativePath($type, $backup->id);
        $backup->status = Backup::STATUS_PENDING;
        $backup->created_by = $createdBy;
        $backup->is_safety = $isSafety;
        $backup->application_version = config('backup.application_version');
        $backup->laravel_version = App::version();
        $backup->database_engine = $this->database->driver();
        $backup->database_version = $this->databaseVersion();
        $backup->save();

        if ($dispatch) {
            CreateBackupJob::dispatch($backup->id, $ip);
        } else {
            $this->perform($backup, $ip);
        }

        return $backup->fresh();
    }

    /**
     * Perform the actual backup generation. Safe to call from a queue worker
     * or synchronously (safety backups).
     */
    public function perform(Backup $backup, ?string $ip = null): void
    {
        try {
            $backup->status = Backup::STATUS_RUNNING;
            $backup->started_at = now();
            $backup->save();

            $disk = Storage::disk($backup->disk);
            $absolute = $disk->path($backup->path);

            $dir = dirname($absolute);
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }

            $metadata = [];
            $checksum = null;
            $size = 0;

            if ($backup->type === Backup::TYPE_DATABASE) {
                $result = $this->database->dumpToFile($absolute);
                $size = $result['bytes'];
                $checksum = $result['checksum'];
                $metadata['tables'] = $result['tables'];
                $metadata['db_bytes'] = $size;
            } elseif ($backup->type === Backup::TYPE_STORAGE) {
                $result = $this->storage->archiveTo($absolute);
                $size = $result['bytes'];
                $checksum = $result['checksum'];
                $metadata['storage_files'] = $result['files'];
                $metadata['storage_bytes'] = $size;
            } else {
                // full
                $built = $this->assembleFull($backup, $absolute);
                $size = $built['bytes'];
                $checksum = $built['checksum'];
                $metadata = $built['metadata'];
            }

            $backup->size_bytes = $size;
            $backup->checksum = $checksum;
            $backup->checksum_algo = 'sha256';
            $backup->metadata = $metadata;
            $backup->status = Backup::STATUS_COMPLETED;
            $backup->completed_at = now();
            $backup->save();

            $this->verifier->verify($backup);

            BackupAudit::log(
                BackupAudit::BACKUP_CREATED,
                $backup->created_by,
                ['backup_id' => $backup->id, 'type' => $backup->type, 'size_bytes' => $size, 'verified' => $backup->verification_status],
                $ip
            );
        } catch (\Throwable $e) {
            $backup->status = Backup::STATUS_FAILED;
            $backup->failure_reason = $e->getMessage();
            $backup->completed_at = now();
            $backup->save();

            BackupAudit::log(
                BackupAudit::BACKUP_CREATED,
                $backup->created_by,
                ['backup_id' => $backup->id, 'type' => $backup->type, 'status' => 'failed', 'error' => $e->getMessage()],
                $ip
            );

            throw $e;
        }
    }

    protected function assembleFull(Backup $backup, string $absoluteZip): array
    {
        $tmp = storage_path('app/backups/_build_'.$backup->id);
        if (is_dir($tmp)) {
            $this->rmdir($tmp);
        }
        mkdir($tmp.'/database', 0750, true);
        mkdir($tmp.'/storage', 0750, true);

        $db = $this->database->dumpToFile($tmp.'/database/dump.sql');
        $store = $this->storage->archiveTo($tmp.'/storage/storage.zip');

        $manifest = [
            'type' => Backup::TYPE_FULL,
            'application_version' => $backup->application_version,
            'laravel_version' => $backup->laravel_version,
            'database_engine' => $backup->database_engine,
            'database_version' => $backup->database_version,
            'database' => ['checksum' => $db['checksum'], 'bytes' => $db['bytes'], 'tables' => $db['tables']],
            'storage' => ['checksum' => $store['checksum'], 'bytes' => $store['bytes'], 'files' => $store['files']],
            'created_at' => now()->toIso8601String(),
        ];
        file_put_contents($tmp.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $this->zipDir($tmp, $absoluteZip);

        $bytes = filesize($absoluteZip);
        $checksum = hash_file('sha256', $absoluteZip);

        $this->rmdir($tmp);

        return [
            'bytes' => $bytes,
            'checksum' => $checksum,
            'metadata' => [
                'tables' => $db['tables'],
                'storage_files' => $store['files'],
                'db_bytes' => $db['bytes'],
                'storage_bytes' => $store['bytes'],
            ],
        ];
    }

    protected function zipDir(string $source, string $destination): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create full backup archive: {$destination}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($source)), '/');
            $zip->addFile($file->getPathname(), $relative);
        }
        $zip->close();
    }

    public function estimateBytes(string $type): int
    {
        $estimate = 10 * 1024 * 1024; // base DB overhead

        if ($type === Backup::TYPE_STORAGE || $type === Backup::TYPE_FULL) {
            $estimate += $this->storageSizeEstimate();
        }

        return $estimate;
    }

    protected function storageSizeEstimate(): int
    {
        $total = 0;
        $root = rtrim(storage_path('app'), '/').'/';
        foreach (config('backup.storage.include', []) as $subdir) {
            $dir = $root.$subdir;
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($this->isExcludedStorage($subdir.'/'.ltrim(substr($file->getPathname(), strlen($dir)), '/'))) {
                    continue;
                }
                $total += $file->getSize();
            }
        }

        return (int) ($total * 1.2);
    }

    protected function isExcludedStorage(string $relative): bool
    {
        if (str_starts_with($relative, 'backups/')) {
            return true;
        }
        foreach (config('backup.storage.exclude', []) as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return (bool) preg_match('#/(cache|logs|framework/(cache|sessions|testing|views))/#', $relative);
    }

    protected function ensureDiskSpace(int $estimate): void
    {
        $dir = Storage::disk(config('backup.disk', 'backups'))->path('');
        $free = (int) disk_free_space($dir);
        $required = $estimate + ((int) config('backup.min_free_space_mb', 200) * 1024 * 1024);

        if ($free < $required) {
            throw new RuntimeException(
                'Insufficient disk space for backup. Free: '.round($free / 1048576, 1)
                .' MB, required: '.round($required / 1048576, 1).' MB.'
            );
        }
    }

    protected function assertTypeEnabled(string $type): void
    {
        if ($type === Backup::TYPE_DATABASE && ! config('backup.database.enabled')) {
            throw new RuntimeException('Database backups are disabled.');
        }
        if ($type === Backup::TYPE_STORAGE && ! config('backup.storage.enabled')) {
            throw new RuntimeException('Storage backups are disabled.');
        }
        if (! in_array($type, [Backup::TYPE_FULL, Backup::TYPE_DATABASE, Backup::TYPE_STORAGE], true)) {
            throw new RuntimeException("Unsupported backup type: {$type}");
        }
    }

    protected function defaultName(string $type, bool $isSafety): string
    {
        $label = match ($type) {
            Backup::TYPE_DATABASE => 'Database',
            Backup::TYPE_STORAGE => 'Storage',
            default => 'Full',
        };

        $prefix = $isSafety ? 'Safety ' : '';

        return "{$prefix}{$label} Backup ".now()->format('Y-m-d H:i:s');
    }

    protected function relativePath(string $type, string $id): string
    {
        $folder = now()->format('Y/m/d').'/'.$id;
        $file = match ($type) {
            Backup::TYPE_DATABASE => 'dump.sql',
            Backup::TYPE_STORAGE => 'storage.zip',
            default => 'backup.zip',
        };

        return $folder.'/'.$file;
    }

    protected function databaseVersion(): string
    {
        try {
            return DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return 'unknown';
        }
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
