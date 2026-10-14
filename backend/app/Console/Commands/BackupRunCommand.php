<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupSetting;
use App\Services\Backup\BackupAudit;
use App\Services\Backup\BackupManager;
use App\Support\Uuid;
use Illuminate\Console\Command;

class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--type=full : full|database|storage}
        {--safety : mark this backup as a safety backup}';

    protected $description = 'Create a backup (intended for the scheduler / cron).';

    public function handle(BackupManager $manager): int
    {
        $type = (string) $this->option('type');
        $isSafety = (bool) $this->option('safety');

        $actor = $this->systemActor();

        try {
            $backup = $manager->create($type, $actor, null, $isSafety, dispatch: false);
        } catch (\Throwable $e) {
            $this->error("Backup failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($backup->status === Backup::STATUS_COMPLETED && $backup->verification_status === Backup::VERIFICATION_PASSED) {
            $this->info("Backup completed and verified: {$backup->id} ({$backup->size_bytes} bytes)");

            return self::SUCCESS;
        }

        $this->warn("Backup finished with status={$backup->status}, verification={$backup->verification_status}");

        return self::FAILURE;
    }

    protected function systemActor(): string
    {
        $super = \Illuminate\Support\Facades\DB::table('user_roles')
            ->where('role', 'super_admin')
            ->where('is_active', 1)
            ->value('user_id');

        if ($super) {
            return $super;
        }

        // Persisted "system" actor so audit rows remain queryable.
        $row = BackupSetting::current();
        if (! empty($row->settings['system_actor_id'])) {
            return $row->settings['system_actor_id'];
        }

        $id = Uuid::v4();
        $settings = $row->settings ?? [];
        $settings['system_actor_id'] = $id;
        $row->settings = $settings;
        $row->save();

        return $id;
    }
}
