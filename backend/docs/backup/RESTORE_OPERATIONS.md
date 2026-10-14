# Restore Operations

Restoring is a **high-risk**, destructive operation and is protected at every step.

## Pre-conditions (all enforced server-side)

* Caller must be a **Super Admin** (the `admin` middleware rejects everyone else).
* The backup must be `completed` **and** `verification_status = passed`.
* The backup file must exist on the private disk.
* Database engine compatibility is checked: a backup taken on a different DB engine
  (e.g. SQLite vs MySQL) is blocked to avoid corruption. A Laravel version difference is
  surfaced as a warning.

## Confirmation

The UI shows an explicit **WARNING** and requires the Super Admin to type the confirmation
word (default `RESTORE`, configurable via `backup.restore_confirmation_word`). A plain
"Are you sure?" is not accepted.

## Workflow

1. **Confirm** by typing `RESTORE`.
2. **Pre-restore safety backup** — a full backup of the *current* state is created
   first (it is flagged `is_safety`). If it cannot be created, the restore is aborted
   **unless** `backup.emergency_restore_without_safety` is explicitly enabled.
3. **Maintenance / protected state** — a cache lock (`backup:restore`) prevents
   concurrent backup/restore operations for the duration.
4. **Restore database** — the dump is streamed into `mysql` with foreign-key checks
   disabled, inside a single transaction-safe import.
5. **Restore storage** — the storage archive is extracted back into `storage/app`.
6. **Clear caches** — `cache:clear`, `view:clear`, `route:clear`.
7. **Post-restore verification** — health checks run (DB connection, required tables
   `auth_users`/`user_roles`/`members`/`backups`, storage writable, auth queryable).
8. **Audit** — `RESTORE_STARTED`, `SAFETY_BACKUP_CREATED`, `RESTORE_COMPLETED` (or
   `RESTORE_FAILED`) are recorded with the acting user, backup id and IP.

The restored backup record is marked `restored`; the safety backup remains `completed` and
is itself restorable as a recovery path.

## Important behaviour: backup subsystem tables

The `backups`, `backup_settings` and `audit_logs` tables are **excluded from the
database dump**. This is deliberate — restoring them would overwrite the very records
created by the restore (the safety backup catalogue and the restore audit trail). Their
live state is always preserved.

## CLI restore (operators)

Although the UI is the primary path, an operator can also restore manually:

```bash
# Locate the archive under storage/app/backups/...
# Database only:
mysql -u <user> -p <database> < 2026/08/25/<id>/dump.sql
# Full: unzip backup.zip, then import database/dump.sql and unzip storage/storage.zip into storage/app
```

Prefer the Super Admin UI, which performs validation, safety backup and verification.
