# Disaster Recovery

Step-by-step recovery using the KHWWC Super Admin Backup & Restore subsystem.

## 0. Prerequisites

* A Super Admin account.
* Backups present on the **private** `backups` disk (`storage/app/backups/...`).
* (Production) a running queue worker if `QUEUE_CONNECTION` is not `sync`:
  `php artisan queue:work`.
* (Production) the scheduler for automatic backups:
  `* * * * * cd /path/to/khwwc && php artisan schedule:run >> /dev/null 2>&1`.

## 1. Routine recovery (recommended path)

1. Sign in as Super Admin → **Backups & Restore**.
2. Confirm the backup you want is `completed` and `verified` (green badge).
3. Optionally **Download** it for an off-box copy.
4. Click **Restore**, read the **WARNING**, and type `RESTORE`.
5. The system automatically creates a **safety backup** of the current state first, then
   restores the database and storage, clears caches, and runs health checks.
6. The backup record becomes `restored`; the safety backup remains available.

If anything looks wrong afterwards, restore the **safety backup** the same way.

## 2. Restoring when the app is partially broken

If the web UI is unavailable but the database/server is reachable:

```bash
cd /path/to/khwwc
# Database-only recovery:
mysql -u <user> -p <database> < storage/app/backups/YYYY/MM/DD/<id>/dump.sql
# Full recovery:
#   unzip storage/app/backups/YYYY/MM/DD/<id>/backup.zip
#   mysql -u <user> -p <database> < database/dump.sql
#   unzip storage/storage.zip -d storage/app
php artisan cache:clear && php artisan view:clear && php artisan route:clear
```

## 3. Recovery when the disk is corrupted / server lost

1. Provision a new server with the same Laravel app and `DB_CONNECTION` (MySQL/MariaDB).
2. Restore the most recent `full` backup archive (database dump + storage zip) from your
   off-box copy or the private disk.
3. Verify `config/backup.php` points `disk` at the correct private location.
4. Re-run migrations only if the schema is missing, then import the data dump.

## 4. Verification after recovery

* Open `/api/health` and `/api/system/info` — both should report `ok`.
* Sign in and open **Backups & Restore** → create a fresh backup; it should verify.
* Open **Audit Logs** and confirm `RESTORE_COMPLETED` / `SAFETY_BACKUP_CREATED` events
  exist for the operation.

## 5. Failure handling

* **Insufficient disk space** → backup aborts with a clear message before writing.
* **Invalid / corrupt backup** → verification fails (`failed`); it cannot be restored.
* **Engine mismatch** (e.g. SQLite backup on MySQL) → restore blocked.
* **Safety backup could not be created** → restore aborted unless emergency mode is on.
* **Restore partially fails** → `RESTORE_FAILED` is audited; the pre-restore safety
  backup remains available for a second attempt.

## Operational checklist (production)

- [ ] Backups stored on a private disk, never `public/`.
- [ ] Super Admin authorization enforced (not just hidden menu).
- [ ] Audit logging enabled and reviewed.
- [ ] Backup integrity verification enabled.
- [ ] Restore validation + safety backup enabled.
- [ ] Database + required storage backed up.
- [ ] Disk-space check configured (`min_free_space_mb`).
- [ ] Queue worker running (if async).
- [ ] Scheduler + cron running (for automatic backups / pruning).
- [ ] Retention policy defined (manual or `keep_last`).
- [ ] Download security (`attachment` + `nosniff`) confirmed.
- [ ] Recovery procedure documented and drilled.
