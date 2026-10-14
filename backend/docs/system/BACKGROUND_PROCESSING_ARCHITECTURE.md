# Background Processing Architecture — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Overview

The KHWWC Welfare application uses Laravel's queue system for one operation:
asynchronous backup generation. The queue driver is `sync` in both the
local environment (`.env`) and the test environment (`phpunit.xml`), meaning
all jobs run synchronously inline.

There are **no Laravel Events, Listeners, or Observers** in the application.
All business logic (including financial operations) executes synchronously
within HTTP request lifecycles inside `DB::transaction()` blocks.

---

## 2. Queue Configuration

| Setting | Local (.env) | Testing (phpunit.xml) | Production (recommended) |
|---------|-------------|----------------------|--------------------------|
| `QUEUE_CONNECTION` | `sync` | `sync` | `database` or `redis` |
| Failed driver | `database-uuids` | `database-uuids` | `database-uuids` |
| Failed table | `failed_jobs` | `failed_jobs` | `failed_jobs` |

### config/queue.php

```php
'default' => env('QUEUE_CONNECTION', 'sync'),
'connections' => [
    'sync' => ['driver' => 'sync'],
    'database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
    'beanstalkd' => [...],
    'sqs' => [...],
],
'failed' => ['driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'), 'database' => env('DB_CONNECTION', 'sqlite'), 'table' => 'failed_jobs'],
```

**Note:** There is **no `redis` queue connection** configured in
`config/queue.php`, despite Redis being installed and configured. If
`QUEUE_CONNECTION=redis` were set in production, it would fail.

---

## 3. Jobs Inventory

### 3.1 CreateBackupJob

| Attribute | Value |
|-----------|-------|
| **Class** | `App\Jobs\CreateBackupJob` |
| **Implements** | `ShouldQueue` |
| **Dispatched by** | `BackupManager::create()` (when `$dispatch = true`, which is the default) |
| **Handled by** | `BackupManager::perform()` |
| **Queue connection** | `sync` (runs inline) |
| **Payload** | `backupId` (string), `ip` (string or null) |

### Behavior

1. Looks up the `Backup` record by ID
2. Calls `BackupManager::perform($backup, $ip)` to generate the backup file
3. If successful: backup record is updated to `completed` + `verified`
4. If failed: backup record is updated to `failed` with error reason, then
   the exception is re-thrown so the queue worker marks the job as failed

### Retry / Timeout

- `CreateBackupJob` does **not** set `$tries` or `$timeout` properties
- Under `sync` queue: exceptions surface directly to the caller (HTTP 422)
- Under `database`/`redis` queue: default Laravel retry (1 retry, 60s timeout)
  would apply. However, backup generation can take longer than 60s for large
  databases. The `$timeout` should be considered for production use.

### Failure handling

- `CreateBackupJob::handle()` catches `\Throwable`, marks the backup as
  `failed`, then re-throws as `RuntimeException` so the queue worker marks
  the job as permanently failed (goes to `failed_jobs` table)
- The `BackupManager::perform()` also has its own try/catch that marks the
  backup as `failed` and logs `BACKUP_CREATED` with `status=failed`

### 3.2 No other jobs

There are **no other queue jobs** in the application. Financial operations,
payment processing, notifications, and audit logging all execute synchronously.

---

## 4. Scheduling

The scheduler is defined in `routes/console.php`:

```php
Schedule::command('backup:run --type=full')
    ->dailyAt('02:00')
    ->when(fn() => (bool) (BackupSetting::current()->settings['automatic_backup_enabled'] ?? false))
    ->evenInMaintenanceMode();

Schedule::command('backup:prune')
    ->dailyAt('02:30')
    ->when(fn() => (int) config('backup.retention.keep_last') > 0 && (bool) config('backup.retention.auto_prune'))
    ->evenInMaintenanceMode();
```

**Cron dependency:** The scheduler requires a cron entry:
```
* * * * * cd /path/to/khwwc && php artisan schedule:run >> /dev/null 2>&1
```
**No cron entry is currently configured.** Without cron, scheduled tasks
will not run.

---

## 5. Worker Management

There are **no queue workers** configured in the application:

- No `supervisord` configuration
- No systemd service files
- No `php artisan queue:work` startup scripts
- No worker health monitoring

For production, the following would need to be set up:
```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

---

## 6. Failed Jobs

- `failed_jobs` table exists (from Laravel default migration)
- No failed job cleanup policy is configured
- No UI for viewing/retrying failed jobs (would require `queue:listen` or
  Horizon or a custom interface)

---

## 7. Queue Health for Security Centre

### 7.1 Current state (queue = sync)

Since `QUEUE_CONNECTION=sync`, there is no queue to monitor. All jobs run
inline. The Security Centre should report:

```
Queue: sync (inline, no workers needed)
Jobs: 0 pending, 0 failed
```

### 7.2 If queue is changed to database/redis

| Metric | How to obtain |
|--------|---------------|
| Pending jobs | `DB::table('jobs')->count()` (database driver) |
| Failed jobs | `DB::table('failed_jobs')->count()` |
| Recent jobs | `DB::table('failed_jobs')->orderByDesc('failed_at')->limit(10)->get()` |
| Job batches | `DB::table('job_batches')` |

### 7.3 Super Admin queue management

Safe, controlled actions:
- View pending job count
- View failed job count
- List recent failed jobs (without exposing sensitive payloads)
- Retry a specific failed job
- Forget a specific failed job

**NOT allowed:**
- Arbitrary job execution
- Running a financial job twice (idempotency must be enforced)
- Clearing the entire queue
- Executing raw shell commands
