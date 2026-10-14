<?php

use App\Models\BackupSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Backup & Restore scheduler
|--------------------------------------------------------------------------
| Requires a cron entry such as:
|   * * * * * cd /path/to/khwwc && php artisan schedule:run >> /dev/null 2>&1
|
| The daily backup only runs when automatic backups are enabled in Super
| Admin settings; pruning only runs when a retention limit is configured.
*/
Schedule::command('backup:run --type=full')
    ->dailyAt('02:00')
    ->when(function () {
        return (bool) (BackupSetting::current()->settings['automatic_backup_enabled'] ?? false);
    })
    ->evenInMaintenanceMode();

Schedule::command('backup:prune')
    ->dailyAt('02:30')
    ->when(function () {
        return (int) config('backup.retention.keep_last') > 0
            && (bool) config('backup.retention.auto_prune');
    })
    ->evenInMaintenanceMode();

/*
|--------------------------------------------------------------------------
| Contribution Due Reminders (3-day rule)
|--------------------------------------------------------------------------
| Sends SMS to members 3 days before their contribution due date.
| Runs daily at 08:00. Idempotent via sms_logs reference columns.
*/
Schedule::command('reminders:due')
    ->dailyAt('08:00')
    ->evenInMaintenanceMode();

Schedule::command('coop:sync-statement')
    ->dailyAt('02:00')
    ->when(function () {
        return (bool) config('coop_bank.enable_real_calls', false);
    })
    ->appendOutputTo(storage_path('logs/coop-sync.log'));

Schedule::command('coop:reconcile-transfers')
    ->everyFifteenMinutes()
    ->when(function () {
        return (bool) config('coop_bank.enable_real_calls', false);
    })
    ->appendOutputTo(storage_path('logs/coop-reconcile.log'));

Schedule::command('coop:cleanup-tokens')
    ->hourly()
    ->when(function () {
        return (bool) config('coop_bank.enable_real_calls', false);
    });

// Penalties are created only after the admin's grace period (welfare_settings.penalty_grace_days),
// then members with overdue months get an SMS reminder.
Schedule::command('penalties:assess')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('reminders:overdue')->dailyAt('09:00')->withoutOverlapping();
