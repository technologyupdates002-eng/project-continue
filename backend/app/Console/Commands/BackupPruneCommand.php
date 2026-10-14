<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupPruneCommand extends Command
{
    protected $signature = 'backup:prune {--keep= : override retention keep-last count}';

    protected $description = 'Delete backups beyond the configured retention limit.';

    public function handle(): int
    {
        $keep = $this->option('keep') !== null
            ? (int) $this->option('keep')
            : (int) config('backup.retention.keep_last');

        if ($keep <= 0) {
            $this->warn('No retention limit configured (manual retention). Nothing deleted.');

            return self::SUCCESS;
        }

        $candidates = Backup::where('status', Backup::STATUS_COMPLETED)
            ->where('is_safety', false)
            ->orderByDesc('created_at')
            ->get();

        $pruned = 0;
        $disk = Storage::disk(config('backup.disk', 'backups'));

        foreach ($candidates->slice($keep) as $backup) {
            if ($disk->exists($backup->path)) {
                $disk->delete($backup->path);
            }
            $backup->status = Backup::STATUS_DELETED;
            $backup->save();

            BackupAudit::log(BackupAudit::BACKUP_PRUNED, 'system', ['backup_id' => $backup->id]);
            $pruned++;
        }

        $this->info("Pruned {$pruned} backup(s) (keeping last {$keep}).");

        return self::SUCCESS;
    }
}
