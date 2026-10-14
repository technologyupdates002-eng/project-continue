# Scheduled Tasks Inventory — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Scheduler Configuration

The scheduler is defined in `routes/console.php` (Laravel 12 uses this file
instead of `app/Console/Kernel.php`). There is no custom Console Kernel class.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

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
```

### Cron dependency

The scheduler requires a cron entry to run:

```
* * * * * cd /path/to/khwwc && php artisan schedule:run >> /dev/null 2>&1
```

**No cron entry is configured.** This is documented in `routes/console.php`
as a comment but is not set up in the current environment. Without cron,
neither scheduled task will execute.

---

## 2. Scheduled Task Inventory

### Task 1: Daily Full Backup

| Attribute | Value |
|-----------|-------|
| **Name** | Daily automatic backup |
| **Command** | `backup:run --type=full` |
| **Handler** | `App\Console\Commands\BackupRunCommand` |
| **Frequency** | Daily at 02:00 |
| **Purpose** | Create a full backup (database + storage) of the welfare platform |
| **Condition** | `BackupSetting::current()->settings['automatic_backup_enabled']` must be `true` |
| **Additional flags** | `evenInMaintenanceMode()` |

#### Details

- **Dependencies:** `BackupManager` service (which depends on `DatabaseBackupService`,
  `StorageBackupService`, `BackupVerificationService`)
- **Database impact:** Creates a `backups` table record, excludes `backups`,
  `backup_settings`, `audit_logs` from the dump
- **Queue impact:** Runs synchronously (creates `BackupRecord` with
  `dispatch: false`), so no queue dependency
- **External API impact:** Uses `mysqldump` CLI binary (MySQL/MariaDB) or
  `sqlite3` CLI binary; no external API calls
- **Failure behavior:** Catches exceptions, logs error, returns `FAILURE`
- **Retry behavior:** Laravel scheduler does not auto-retry; the
  `backup:run` command handles failure by marking the backup record as
  `failed` and logging `BACKUP_CREATED` with status `failed`
- **Last run:** N/A (scheduler not actively running via cron)
- **Status:** Configured but not executing (no cron entry)

#### System actor

The `BackupRunCommand` detects a "system actor" for audit logging:
1. First finds the first active `super_admin` user
2. Falls back to a persisted `system_actor_id` in `BackupSetting`
3. If none exists, creates a UUID and persists it

---

### Task 2: Daily Backup Prune

| Attribute | Value |
|-----------|-------|
| **Name** | Daily backup prune |
| **Command** | `backup:prune` |
| **Handler** | `App\Console\Commands\BackupPruneCommand` |
| **Frequency** | Daily at 02:30 |
| **Purpose** | Delete backups beyond the configured retention limit |
| **Condition** | `backup.retention.keep_last > 0` AND `backup.retention.auto_prune` must be `true` |
| **Additional flags** | `evenInMaintenanceMode()` |

#### Details

- **Dependencies:** `Backup` model, `Storage` facade (backups disk),
  `BackupAudit` service
- **Database impact:** Updates `backups` table records to `status=deleted`;
  deletes backup files from the `backups` disk; logs `BACKUP_PRUNED` to
  `audit_logs`
- **Queue impact:** None (synchronous)
- **External API impact:** None
- **Failure behavior:** If no retention is configured (`keep_last <= 0`),
  logs a warning and returns `SUCCESS` (nothing deleted)
- **Retry behavior:** Not applicable — runs daily
- **Last run:** N/A (scheduler not actively running via cron)
- **Status:** Configured but not executing (no cron entry)

#### Important behaviors

- Only prunes backups with `status=completed` and `is_safety=false`
- Keeps the most recent N backups (ordered by `created_at` DESC)
- Safety backups are never pruned by this command
- Can be run manually with `--keep=N` override

---

## 3. Artisan Commands (Custom)

| Command | Class | Description |
|---------|-------|-------------|
| `inspire` | Laravel default | Display an inspiring quote |
| `backup:run` | `BackupRunCommand` | Create a backup (full/database/storage). Used by scheduler. Accepts `--type=full` and `--safety` flags. System actor is auto-detected. |
| `backup:prune` | `BackupPruneCommand` | Delete backups beyond retention limit. Accepts `--keep=N` override. |

**Note:** The backup commands can be run manually:
```bash
php artisan backup:run --type=full
php artisan backup:prune --keep=10
```

---

## 4. Scheduler Safety Analysis

### 4.1 Safe for Super Admin management?

| Task | Can Super Admin safely enable/disable? | Can Super Admin run now? | Risk |
|------|--------------------------------------|----------------------|------|
| Daily full backup | Yes (via `BackupSetting` `automatic_backup_enabled`) | Yes (via "Create Full Backup" button) | Low — runs synchronously, has disk space check, verification |
| Daily backup prune | Yes (via `BackupSetting` `retention_keep_last` + `auto_prune`) | Yes (via "Run prune now" button in settings) | Low — only prunes completed non-safety backups |

### 4.2 Risks

| Risk | Mitigation |
|------|-----------|
| Backup runs during business hours and impacts performance | Scheduled for 02:00 (off-peak); `ensureDiskSpace()` check prevents filling disk |
| Pruning deletes needed backups | Only deletes beyond retention; safety backups never pruned; confirmation in UI |
| Restore during maintenance | `evenInMaintenanceMode()` allows both tasks to run during maintenance |
| Concurrent backup/restore | `RestoreService` uses `Cache::lock('backup:restore', 600)` to prevent concurrent restores; no lock for backup creation (but backup creation is idempotent) |

### 4.3 What is NOT exposed to Super Admin

- No ability to schedule arbitrary commands
- No arbitrary shell execution
- No raw SQL execution interface
- No scheduler configuration changes (frequency/timing)
- No ability to modify the `routes/console.php` schedule definitions

---

## 5. Recommended Super Admin Scheduler Controls

For the Security Centre / System Health area, the following Super Admin
controls would be appropriate and safe:

1. **View scheduled tasks** — list the 2 scheduled tasks with their frequency,
   conditions, and last run status
2. **Run now** — manually trigger `backup:run` or `backup:prune` (already
   implemented via the Backups & Settings pages)
3. **Enable/disable automatic backups** — already implemented via
   `BackupSetting` settings
4. **Configure retention** — already implemented via settings
5. **View scheduler health** — check whether the cron entry is running
   (via `php artisan schedule:run` timestamp or a sentinel file)

**What should NOT be done:**
- Do not allow changing the scheduled frequency/timing
- Do not allow adding arbitrary commands to the schedule
- Do not expose raw shell command execution
