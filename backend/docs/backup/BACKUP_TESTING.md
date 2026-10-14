# Backup & Restore Testing

The subsystem is covered by `tests/Feature/BackupRestoreTest.php` (13 tests, 46
assertions), all passing.

## Test matrix

| Test | Covers |
|------|-------|
| `unauthenticated_json_is_rejected` | No session → `401` on JSON API. |
| `unauthenticated_page_redirects_to_login` | No session → `302` to login. |
| `non_super_admin_is_forbidden` | Member role → `403`. |
| `create_full_backup_then_verify` | Full backup → `completed` + `verified`; `BACKUP_CREATED` audit. |
| `database_and_storage_only_backups` | Database-only and storage-only backups verify. |
| `backup_list_and_stats` | List + stats reflect real records. |
| `download_is_secure_and_streamed` | Download is an authenticated attachment; file not under `public/`. |
| `delete_backup_removes_file_and_records` | Delete removes file + marks `deleted`; `BACKUP_DELETED` audit. |
| `full_restore_cycle_reverts_data` | Backup → modify → restore reverts data; safety backup created; audit logged. |
| `restore_requires_confirmation_word` | Wrong word → `422`. |
| `restore_rejects_unverified_backup` | Non-verified/running backup → `422`. |
| `corrupt_backup_fails_verification` | Corrupt archive → `verification_status = failed`. |
| `prune_respects_retention` | Retention keeps only the last *N*. |

## How to run

```bash
php artisan test --filter BackupRestoreTest
```

## Notes on the test environment

* Tests run against an isolated MySQL database (`khwwc_test`) configured in `phpunit.xml`.
* The private `backups` disk is pointed at a throwaway temp directory per test so no
  production files are touched.
* Tests use `DatabaseMigrations` (not `RefreshDatabase`) because the restore step issues
  external `DROP TABLE` statements via the `mysql` CLI; an open test transaction would
  hold metadata locks and deadlock the restore. `DatabaseMigrations` migrates the schema
  fresh per test without a wrapping transaction.
* `mysqldump` / `mysql` must be available on `PATH` and the DB connection must allow the
  CLI client (the test DB uses `root` with no password; in CI set
  `BACKUP_DB_SSL` / client paths as needed).

## Manual local restore test (no data destruction)

1. Create an isolated database and seed it.
2. Insert a uniquely-named marker row (e.g. a member `ZZ_RESTORE_<ts>`).
3. Create a **full** backup via the UI/API.
4. Delete the marker row.
5. Restore the backup, typing `RESTORE`.
6. Assert the marker row is present again and the safety backup exists.
