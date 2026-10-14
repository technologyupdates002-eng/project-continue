# Backup & Restore Architecture

The KHWWC Welfare application ships with a self-contained **Super Admin Backup &
Restore** subsystem. It is built entirely on the application's existing architecture
(JWT identity via `App\Services\AuthService`, the `auth_users` / `user_roles` stores,
the existing `audit_logs` table, the Laravel filesystem and queue/scheduler) and does
**not** depend on the legacy welfare application.

## Components

| Layer | Class / File | Responsibility |
|-------|--------------|----------------|
| Model | `App\Models\Backup` | Backup metadata record (UUID PK, statuses, checksums, versions). |
| Model | `App\Models\BackupSetting` | Single persisted row of Super Admin backup settings. |
| Manager | `App\Services\Backup\BackupManager` | Orchestrates backup creation: disk-space check, record, generation, verification, audit. |
| Database | `App\Services\Backup\DatabaseBackupService` | Dumps / restores the database via `mysqldump` / `mysql` (CLI). |
| Storage | `App\Services\Backup\StorageBackupService` | Archives / restores `storage/app` uploads (private, public) as a ZIP. |
| Verify | `App\Services\Backup\BackupVerificationService` | Strict post-write verification (exists, readable, size, checksum, structure). |
| Restore | `App\Services\Backup\RestoreService` | Validation, safety backup, maintenance lock, restore, cache clear, health checks. |
| Job | `App\Jobs\CreateBackupJob` | Runs generation asynchronously (inline under `sync` queue). |
| Middleware | `App\Http\Middleware\AdminAccess` | Re-validates the session JWT and enforces the `super_admin` role. |
| Controller | `App\Http\Controllers\Admin\BackupAdminController` | JSON API + Blade pages for backups / settings. |
| Controller | `App\Http\Controllers\Admin\AdminAuthController` | Web login/logout for the Super Admin area. |
| Commands | `App\Console\Commands\BackupRunCommand`, `BackupPruneCommand` | Scheduler/cron entry points. |
| Config | `config/backup.php`, `config/filesystems.php` (`backups` disk) | Behaviour + private storage. |

## Backup archive format

* **`database`** backup → a single `dump.sql` file (MySQL/MariaDB dump).
* **`storage`** backup → a single `storage.zip` archive of `storage/app` (excluding
  cache/logs/temp and the backups directory itself).
* **`full`** backup → a `backup.zip` containing:
  * `manifest.json` (type, app/laravel/db versions, inner checksums)
  * `database/dump.sql`
  * `storage/storage.zip`

## What is and is not backed up

**Backed up**
* Full database schema + data (all business tables).
* Application uploads under `storage/app` (member files, receipts, documents, reports).

**Explicitly excluded from the database dump** (operational/self-referential tables that
must survive a restore): `backups`, `backup_settings`, `audit_logs`. Excluding them
prevents a restore from clobbering the live backup catalogue and the audit trail of the
restore operation itself.

**Explicitly excluded from the storage archive**: `vendor/`, `node_modules/`, `.git/`,
framework cache/sessions/testing/views, logs, and the `backups` directory itself.

## Storage location

Backups live on the **private** `backups` filesystem disk (`storage/app/backups`,
visibility `private`). They are **never** placed under `public/` and are only ever served
through an authenticated, role-checked Laravel download route.

## Authorization

* The Super Admin web area is gated by the `admin` middleware, which re-decodes the
  session-stored JWT and requires the `super_admin` role on every request.
* Backup/restore JSON endpoints and the download route are therefore independently
  authorized server-side — hiding the menu item is not the only protection.
* Regular members, treasurers, group admins, etc. receive `403` / a login redirect.

## Status model

`pending → running → completed → (verified | failed)` and
`completed → restoring → restored`, plus `deleted`. The UI distinguishes a backup that
was merely *created* from one that was *successfully verified* (`verification_status`).

## Queue & scheduler

* `CreateBackupJob` runs generation. With `QUEUE_CONNECTION=sync` (local/testing) it runs
  inline; in production use `database`/`redis` and run `php artisan queue:work`.
* `routes/console.php` schedules `backup:run --type=full` daily at 02:00 (only when
  automatic backups are enabled in settings) and `backup:prune` (only when a retention
  limit + auto-prune are set). Both require `php artisan schedule:run` driven by cron.
