<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Verifies a backup artefact after it is written.
 *
 * Verification is strict: a backup is only marked "verified" when the file
 * exists, is readable, has a reasonable size, matches the recorded checksum
 * and (where applicable) the inner database dump / storage archive are
 * structurally valid. The UI distinguishes "created" from "verified".
 */
class BackupVerificationService
{
    public function __construct(
        protected DatabaseBackupService $database,
        protected StorageBackupService $storage,
    ) {
    }

    /**
     * Verify a backup record in place and persist the outcome.
     *
     * @return array{status:string,details:array}
     */
    public function verify(Backup $backup): array
    {
        $disk = Storage::disk($backup->disk);
        $absolute = $disk->path($backup->path);

        $details = [];

        if (! $disk->exists($backup->path)) {
            return $this->fail($backup, 'Backup file is missing.', $details);
        }

        if (! is_readable($absolute)) {
            return $this->fail($backup, 'Backup file is not readable.', $details);
        }

        $size = filesize($absolute);
        if ($size < 64) {
            return $this->fail($backup, 'Backup file is suspiciously small.', $details);
        }

        $checksum = hash_file($backup->checksum_algo ?: 'sha256', $absolute);
        if (! empty($backup->checksum) && ! hash_equals($backup->checksum, $checksum)) {
            return $this->fail($backup, 'Checksum mismatch — file may be corrupted.', $details);
        }
        $details['checksum'] = $checksum;
        $details['size_bytes'] = $size;

        if ($backup->type === Backup::TYPE_DATABASE) {
            $db = $this->database->verifyDump($absolute);
            $details['database'] = $db;
            if (! $db['valid']) {
                return $this->fail($backup, 'Database dump failed structural validation: '.$db['reason'], $details);
            }
        } elseif ($backup->type === Backup::TYPE_STORAGE) {
            $st = $this->storage->verifyArchive($absolute);
            $details['storage'] = $st;
            if (! $st['valid']) {
                return $this->fail($backup, 'Storage archive failed integrity validation: '.$st['reason'], $details);
            }
        } elseif ($backup->type === Backup::TYPE_FULL) {
            $inner = $this->verifyFullArchive($absolute);
            $details = array_merge($details, $inner['details']);
            if (! $inner['valid']) {
                return $this->fail($backup, $inner['reason'], $details);
            }
        } else {
            return $this->fail($backup, 'Unknown backup type: '.$backup->type, $details);
        }

        $backup->verification_status = Backup::VERIFICATION_PASSED;
        $backup->verified_at = now();
        $backup->verification_details = $details;
        $backup->save();

        return ['status' => Backup::VERIFICATION_PASSED, 'details' => $details];
    }

    /**
     * Verify a backup file without a DB record (used to reject invalid
     * restore sources before any destructive action).
     */
    public function verifyFile(string $diskName, string $path, string $type): array
    {
        $disk = Storage::disk($diskName);
        $absolute = $disk->path($path);

        if (! $disk->exists($path) || ! is_readable($absolute)) {
            throw new RuntimeException('Backup file is missing or unreadable.');
        }

        if ($type === Backup::TYPE_DATABASE) {
            return ['valid' => $this->database->verifyDump($absolute)['valid'], 'reason' => null];
        }

        if ($type === Backup::TYPE_STORAGE) {
            return ['valid' => $this->storage->verifyArchive($absolute)['valid'], 'reason' => null];
        }

        return $this->verifyFullArchive($absolute);
    }

    protected function verifyFullArchive(string $absolute): array
    {
        $st = $this->storage->verifyArchive($absolute);
        if (! $st['valid']) {
            return ['valid' => false, 'reason' => 'Top-level archive invalid: '.$st['reason'], 'details' => ['storage' => $st]];
        }

        $tmp = $this->extractInner($absolute);
        try {
            $dump = $tmp.'/database/dump.sql';
            $store = $tmp.'/storage/storage.zip';

            if (! is_file($dump)) {
                return ['valid' => false, 'reason' => 'Full backup is missing database/dump.sql.', 'details' => []];
            }
            $db = $this->database->verifyDump($dump);
            if (! $db['valid']) {
                return ['valid' => false, 'reason' => 'Inner database dump invalid: '.$db['reason'], 'details' => ['database' => $db]];
            }

            $storageValid = true;
            $reason = null;
            if (is_file($store)) {
                $inner = $this->storage->verifyArchive($store);
                $storageValid = $inner['valid'];
                $reason = $inner['reason'];
            }

            return [
                'valid' => $storageValid,
                'reason' => $reason,
                'details' => ['database' => $db, 'storage' => $inner ?? null, 'entries' => $st['entries']],
            ];
        } finally {
            $this->rmdir($tmp);
        }
    }

    protected function extractInner(string $absolute): string
    {
        $tmp = storage_path('app/backups/_verify_'.uniqid('', true));
        mkdir($tmp, 0750, true);

        $zip = new \ZipArchive();
        $zip->open($absolute);
        $zip->extractTo($tmp);
        $zip->close();

        return $tmp;
    }

    protected function fail(Backup $backup, string $reason, array $details): array
    {
        $backup->verification_status = Backup::VERIFICATION_FAILED;
        $backup->verification_details = $details;
        $backup->failure_reason = $reason;
        $backup->save();

        return ['status' => Backup::VERIFICATION_FAILED, 'details' => $details, 'reason' => $reason];
    }

    protected function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
