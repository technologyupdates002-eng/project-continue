<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Generates a backup asynchronously. Runs inline under the `sync` queue
 * (local/testing) and on a worker under `database`/Redis in production.
 */
class CreateBackupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $backupId,
        public ?string $ip = null
    ) {
    }

    public function handle(BackupManager $manager): void
    {
        $backup = Backup::find($this->backupId);
        if (! $backup) {
            return;
        }

        try {
            $manager->perform($backup, $this->ip);
        } catch (\Throwable $e) {
            if ($backup->status !== Backup::STATUS_FAILED) {
                $backup->status = Backup::STATUS_FAILED;
                $backup->failure_reason = $e->getMessage();
                $backup->completed_at = now();
                $backup->save();
            }
            // Re-throw so the worker marks the job failed (and retries if configured).
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
