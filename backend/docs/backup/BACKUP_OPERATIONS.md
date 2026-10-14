# Backup Operations

How a Super Admin creates, inspects, downloads and deletes backups from the KHWWC
Super Admin interface.

## Access

1. Sign in at `/admin/login` with a Super Admin account.
2. The **Side Navigation** lists **Backups & Restore** (and Dashboard, Users, Groups,
   Transactions, Reports, Audit Logs, Settings).

## Create a backup

* Open **Backups & Restore**.
* Use **Create Full Backup** (database + storage), **DB only**, or **Storage only**.
* The request is confirmed in the browser, dispatched for generation, and the new row
  appears in the list. Generation, checksumming and strict verification happen
  automatically; the row shows `completed` **and** `verified` when healthy.
* A `BACKUP_CREATED` audit event is written.

## Inspection

* Click **View** on any row to see metadata: id, type, status, verification status,
  size, SHA-256 checksum, recorded app/laravel/DB versions, and (in detailed view) the
  storage/manifest details.

## Download (secure)

* Click **Download**. The file is streamed from the private `backups` disk through an
  authenticated route (`/admin/api/backups/{id}/download`) with
  `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. The URL is
  never a public web path.
* A `BACKUP_DOWNLOADED` audit event is written.

## Delete

* Click **Delete** (requires confirmation). The physical archive is removed from the
  private disk, the record is marked `deleted`, and a `BACKUP_DELETED` audit event is
  written.
* Deletion is blocked while a backup is `running` / `restoring` / `pending`, and while a
  safety backup is still referenced by a completed restore.

## Verification rules

A backup is only marked **verified** when **all** hold:

1. The file exists on the private disk.
2. It is readable.
3. Its size is reasonable (≥ 64 bytes).
4. Its SHA-256 checksum matches the recorded checksum.
5. Structurally:
   * `database` → the SQL dump parses and contains `CREATE TABLE` / `INSERT INTO`.
   * `storage` / `full` → the ZIP opens, every entry path is safe (no `..`), and the
     inner database dump + storage archive validate.

## Settings (`/admin/settings`)

* Database backups enabled / Storage backups enabled.
* Retention — keep last *N* backups (blank = manual only; nothing is auto-deleted).
* Automatic pruning (scheduled `backup:prune`).
* Automatic daily backup (scheduled `backup:run`).
* Minimum free disk space (MB) required before a backup.
* A **Run prune now** action applies the retention policy immediately.

Changing settings writes a `BACKUP_SETTINGS_UPDATED` audit event.

## Disk space

Before generating a backup the manager estimates its size (DB dump + storage archive) and
refuses to start if free space is below `min_free_space_mb` + the estimate, returning a
clear error instead of filling the disk.
