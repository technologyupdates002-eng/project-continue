<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Archives application storage (uploads, member files, generated reports)
 * into a ZIP artefact and restores it on demand. Source lives under
 * storage/app; cache, logs, temp and the backups directory itself are
 * excluded so backups never back up backups.
 */
class StorageBackupService
{
    protected string $storageRoot;

    public function __construct()
    {
        $this->storageRoot = rtrim(storage_path('app'), '/').'/';
    }

    /**
     * @return array{path:string,bytes:int,checksum:string,files:int}
     */
    public function archiveTo(string $zipPath): array
    {
        if (! extension_loaded('zip')) {
            throw new RuntimeException('PHP zip extension is required for storage backups.');
        }

        $dir = dirname($zipPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create storage archive at {$zipPath}");
        }

        $files = 0;
        foreach (config('backup.storage.include', []) as $subdir) {
            $files += $this->addDirectory($zip, $this->storageRoot.$subdir, $subdir);
        }

        $zip->close();

        return [
            'path' => $zipPath,
            'bytes' => filesize($zipPath),
            'checksum' => hash_file('sha256', $zipPath),
            'files' => $files,
        ];
    }

    public function restoreFrom(string $zipPath): void
    {
        if (! is_readable($zipPath)) {
            throw new RuntimeException("Storage archive is not readable: {$zipPath}");
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to open storage archive: {$zipPath}");
        }

        if (! $zip->extractTo($this->storageRoot)) {
            $zip->close();
            throw new RuntimeException("Unable to extract storage archive to {$this->storageRoot}");
        }

        $zip->close();
    }

    /**
     * @return array{valid:bool,reason:?string,entries:int}
     */
    public function verifyArchive(string $zipPath): array
    {
        if (! is_file($zipPath) || ! is_readable($zipPath)) {
            return ['valid' => false, 'reason' => 'Archive missing or unreadable.', 'entries' => 0];
        }

        if (filesize($zipPath) < 22) {
            return ['valid' => false, 'reason' => 'Archive too small to be valid.', 'entries' => 0];
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath);
        if ($status !== true) {
            return ['valid' => false, 'reason' => "Corrupt archive (code {$status}).", 'entries' => 0];
        }

        $entries = $zip->numFiles;
        $invalid = false;
        for ($i = 0; $i < $entries; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $invalid = true;
                break;
            }
        }
        $zip->close();

        if ($invalid) {
            return ['valid' => false, 'reason' => 'Archive contains unsafe entry paths.', 'entries' => 0];
        }

        return ['valid' => true, 'reason' => null, 'entries' => $entries];
    }

    protected function addDirectory(\ZipArchive $zip, string $absoluteDir, string $relativeRoot): int
    {
        if (! is_dir($absoluteDir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $absolute = $file->getPathname();
            $relative = $relativeRoot.'/'.ltrim(substr($absolute, strlen($absoluteDir)), '/');

            if ($this->isExcluded($relative, $absolute)) {
                continue;
            }

            if ($zip->addFile($absolute, $relative)) {
                $count++;
            }
        }

        return $count;
    }

    protected function isExcluded(string $relative, string $absolute): bool
    {
        // Never include the backups directory itself.
        if (str_starts_with($relative, 'backups/') || $relative === 'backups') {
            return true;
        }

        if (str_contains($relative, '/.git/') || str_ends_with($relative, '/.git')) {
            return true;
        }

        foreach (config('backup.storage.exclude', []) as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        // Skip obviously temporary / compiled artefacts.
        if (preg_match('#/(cache|logs|framework/(cache|sessions|testing|views))/#', $relative)) {
            return true;
        }

        return false;
    }
}
