# Baseline Health — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)
**Purpose:** Establish a baseline of the current application state before any
changes are made. This document records the state of tests, routes,
configuration, and any known warnings or errors.

---

## 1. Environment

| Component | Value |
|-----------|-------|
| Laravel | 12.x |
| PHP | 8.4.24 (CLI) |
| Database | MariaDB 11.8.8 |
| Database name | `khwwc_local` |
| Redis | v8.0.6 installed, **NOT running** |
| App environment | `local` |
| `APP_DEBUG` | `true` |
| bcrypt rounds | 12 |

### PHP extensions loaded

```
bcmath, gd, mbstring, openssl, PDO, pdo_mysql, zip
```

**Note:** `redis` PHP extension is **NOT installed** (using `predis/predis`
client library instead for Redis configuration, though Redis is not actively used).

---

## 2. Test Baseline

### Command

```bash
php artisan test
```

### Result: **PASS** — 65 tests, 202 assertions, 0 failures, 0 errors, 0 skipped

### Test breakdown

| Suite | Tests | Assertions | Status |
|-------|-------|-----------|--------|
| Unit / ApiResourceSerializerTest | 4 | — | ✓ PASS |
| Unit / AuthServiceTest | 4 | — | ✓ PASS |
| Unit / ExampleTest | 1 | — | ✓ PASS |
| Unit / JwtServiceTest | 4 | — | ✓ PASS |
| Unit / PolicyServiceTest | 7 | — | ✓ PASS |
| Unit / PostgrestQueryBuilderTest | 7 | — | ✓ PASS |
| Feature / AuthenticationTest | 3 | — | ✓ PASS |
| Feature / BackupRestoreTest | 13 | — | ✓ PASS |
| Feature / ExampleTest | 1 | — | ✓ PASS |
| Feature / HealthTest | 3 | — | ✓ PASS |
| Feature / LegacyApiContractTest | 2 | — | ✓ PASS |
| Feature / Notifications / NotificationTest | 4 | — | ✓ PASS |
| Feature / Payments / PaymentTest | 6 | — | ✓ PASS |
| Feature / Wallets / WalletTest | 5 | — | ✓ PASS |
| **Total** | **65** | **202** | **✓ ALL PASS** |

### Test command configuration

`phpunit.xml` sets the following environment for tests:

```
APP_ENV=testing
APP_URL=http://localhost
CACHE_STORE=array
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
DB_CONNECTION=mysql
DB_DATABASE=khwwc_test
DB_USERNAME=root
DB_PASSWORD=
BCRYPT_ROUNDS=4
BROADCAST_CONNECTION=null
```

### Migration status

```bash
php artisan migrate:status
```

All 8 migrations have been run (batch 1):

| Migration | Batch | Status |
|-----------|-------|--------|
| 0001_01_01_000000_create_users_table | 1 | ✓ Ran |
| 0001_01_01_000001_create_cache_table | 1 | ✓ Ran |
| 0001_01_01_000002_create_jobs_table | 1 | ✓ Ran |
| 2026_08_24_000000_create_member_executive_roles_table | 1 | ✓ Ran |
| 2026_08_24_120000_import_full_schema | 1 | ✓ Ran |
| 2026_08_24_130000_add_timestamps_to_storage_objects | 1 | ✓ Ran |
| 2026_08_25_000000_create_backups_table | 1 | ✓ Ran |
| 2026_08_25_000100_create_backup_settings_table | 1 | ✓ Ran |

---

## 3. Routes Baseline

```bash
php artisan route:list
```

**55 routes** total.

### Web routes (7)

| Method | URI | Name | Controller |
|--------|-----|------|-----------|
| GET | `/` | dashboard | routes/web.php:9 (closure) |
| GET | `/admin/login` | admin.login | AdminAuthController@showLogin |
| POST | `/admin/login` | admin.login.post | AdminAuthController@login |
| POST | `/admin/logout` | admin.logout | AdminAuthController@logout |
| GET | `/admin` | admin.dashboard | AdminDashboardController@page |
| GET | `/admin/backups` | admin.backups | BackupAdminController@page |
| GET | `/admin/settings` | admin.settings | BackupAdminController@settingsPage |
| GET | `/admin/users` | admin.users | AdminDataController@users |
| GET | `/admin/groups` | admin.groups | AdminDataController@groups |
| GET | `/admin/transactions` | admin.transactions | AdminDataController@transactions |
| GET | `/admin/reports` | admin.reports | AdminDataController@reports |
| GET | `/admin/audit-logs` | admin.audit-logs | AdminDataController@auditLogs |

### Admin API routes (15)

All under `admin` middleware (Super Admin only):

| Method | URI | Controller |
|--------|-----|-----------|
| GET | `/admin/api/dashboard/stats` | AdminDashboardController@stats |
| GET | `/admin/api/backups` | BackupAdminController@data |
| POST | `/admin/api/backups` | BackupAdminController@store |
| POST | `/admin/api/backups/prune` | BackupAdminController@prune |
| GET | `/admin/api/backups/settings` | BackupAdminController@settings |
| PUT | `/admin/api/backups/settings` | BackupAdminController@settings |
| GET | `/admin/api/backups/{id}` | BackupAdminController@show |
| POST | `/admin/api/backups/{id}/verify` | BackupAdminController@verify |
| GET | `/admin/api/backups/{id}/download` | BackupAdminController@download |
| DELETE | `/admin/api/backups/{id}` | BackupAdminController@destroy |
| POST | `/admin/api/backups/{id}/restore` | BackupAdminController@restore |
| GET | `/admin/api/users` | AdminDataController@usersData |
| GET | `/admin/api/groups` | AdminDataController@groupsData |
| GET | `/admin/api/transactions` | AdminDataController@transactionsData |
| GET | `/admin/api/reports` | AdminDataController@reportsData |
| GET | `/admin/api/audit-logs` | AdminDataController@auditData |

### API routes (31 — some are generic catch-all)

| Method | URI | Middleware | Controller |
|--------|-----|-----------|-----------|
| GET | `api/health` | none | HealthController@health |
| GET | `api/system/info` | none | HealthController@systemInfo |
| POST | `api/auth/v1/token` | none | LegacyApiController@token |
| POST | `api/auth/v1/signup` | none (open registration) | LegacyApiController@signup |
| POST | `api/auth/v1/logout` | auth.jwt | LegacyApiController@logout |
| GET | `api/auth/v1/user` | auth.jwt | LegacyApiController@user |
| POST | `api/auth/v1/password` | auth.jwt | LegacyApiController@changePassword |
| GET/POST/... | `api/rest/v1/{table}` | auth.jwt | LegacyRestController@handle |
| POST | `api/rest/v1/rpc/{fn}` | auth.jwt | (closure stub) |
| GET | `api/storage/v1/object/{bucket}/{path?}` | none | LegacyStorageController@download |
| GET | `api/storage/v1/object/{bucket}/{path?}/signed-url` | auth.jwt | LegacyStorageController@signedUrl |
| POST | `api/storage/v1/object/{bucket}/{path?}` | auth.jwt | LegacyStorageController@upload |
| DELETE | `api/storage/v1/object/{bucket}/{path?}` | auth.jwt | LegacyStorageController@delete |
| GET | `api/me` | auth.jwt | (closure) |
| POST | `api/payments/stk-push` | auth.jwt | PaymentController@stkPush |
| POST | `api/payments/stk-callback` | none | PaymentController@stkCallback |
| GET | `api/payments/status/{id}` | auth.jwt | PaymentController@status |
| POST | `api/payments/bank-sync` | auth.jwt | PaymentController@bankSync |
| POST | `api/payments/b2c` | auth.jwt | PaymentController@b2cTransfer |
| POST | `api/notifications/bulk-sms` | auth.jwt | NotificationController@bulkSms |
| POST | `api/notifications/push` | auth.jwt | NotificationController@push |
| POST | `api/wallets/{walletType}/credit` | auth.jwt | WalletController@credit |
| POST | `api/wallets/{walletType}/debit` | auth.jwt | WalletController@debit |
| GET | `api/wallets/{walletType}/balance` | auth.jwt | WalletController@balance |
| GET | `api/wallets/{walletType}/transactions` | auth.jwt | WalletController@transactions |
| GET/POST/... | `api/{table}` | auth.jwt | LegacyRestController@handle (whitelisted tables) |
| GET | `up` | none | vendor health check |

**Note:** There are two generic REST controller routes (`api/rest/v1/{table}`
and `api/{table}`) that handle CRUD for arbitrary tables. The second one
whitelists specific tables: `members|contributions|donation-campaigns|donation-
withdrawals|member-access-logs|operational-withdrawals|organization-settings|
payments|penalties|penalty-payment-records|penalty-wallet|penalty-withdrawals|
signatory-signatures|system-logs|user-roles`.

### Console commands

```bash
php artisan list
```

Custom commands:
- `backup:run` — Create a backup (scheduler/cron entry point)
- `backup:prune` — Delete backups beyond retention
- `inspire` — Laravel default

### Scheduled tasks

```bash
php artisan schedule:list
```

| Command | Frequency | Condition |
|---------|-----------|-----------|
| `backup:run --type=full` | daily at 02:00 | `automatic_backup_enabled` must be `true` in `BackupSetting` |
| `backup:prune` | daily at 02:30 | `backup.retention.keep_last > 0` AND `backup.retention.auto_prune` must be `true` |

Both tasks run `evenInMaintenanceMode()`.

---

## 4. Configuration

### Key configuration values

| Config | Value | Source |
|--------|-------|--------|
| DB_CONNECTION | `mysql` | .env |
| DB_HOST | `127.0.0.1` | .env |
| DB_DATABASE | `khwwc_local` | .env |
| CACHE_STORE | `file` | .env |
| QUEUE_CONNECTION | `sync` | .env |
| SESSION_DRIVER | `file` | .env |
| SESSION_LIFETIME | `120` (minutes) | .env |
| REDIS_CLIENT | `predis` | .env |
| REDIS_HOST | `127.0.0.1` | .env |
| REDIS_PORT | `6379` | .env |
| REDIS_CACHE_DB | `1` | .env (unused — cache is file) |
| REDIS_QUEUE_DB | `2` | .env (unused — queue is sync) |
| JWT_SECRET | `khcww-welfare-change-this-secret-9f3a2b7c1d4e5f60` | .env |
| JWT_TTL | `3600` (1 hour) | .env |
| REFRESH_TTL | `2592000` (30 days) | .env |
| APP_DEBUG | `true` | .env |
| BCRYPT_ROUNDS | `12` | .env |
| LOG_CHANNEL | `stack` (→ single) | .env |
| LOG_LEVEL | `debug` | .env |
| BACKUP_DISK | `backups` | .env (default) |
| CORS_ALLOWED_ORIGINS | comma-separated localhost origins | .env |

### Configuration issues / warnings

1. **Redis configured but not running:** The `.env` sets `REDIS_CACHE_DB=1`
   and `REDIS_QUEUE_DB=2`, but `CACHE_STORE=file` and `QUEUE_CONNECTION=sync`.
   Redis is configured but not used. `HealthController::redisStatus()` will
   report "error" when called.

2. **JWT secret fallback:** `config/jwt.php` has a default fallback secret
   `khcww-welfare-change-this-secret-9f3a2b7c1d4e5f60`. While `.env` sets
   `JWT_SECRET`, if the env var is missing, the fallback is insecure.

3. **No Redis cache store in config:** `config/cache.php` does not define a
   `redis` store. If `CACHE_STORE=redis` were set, it would fail. The redis
   store must be added to use Redis for caching.

4. **APP_DEBUG=true:** Correct for local development but must be `false`
   in production.

5. **No rate limiting configured:** Laravel's `api` middleware group does not
   include throttling. No `RateLimiter::for('api')` call exists in
   `AppServiceProvider`.

---

## 5. Current Warnings and Errors

### Laravel log analysis

The `storage/logs/laravel.log` file is ~12MB and contains test-generated
exceptions. The most common entries are:

1. **`App\Exceptions\HttpException: Insufficient balance`** — Generated by
   `WalletService::debit()` when a test attempts to debit more than the
   wallet balance. This is **expected behavior** (the test
   `WalletTest::test_wallet_debit_fails_on_insufficient_balance` deliberately
   tests this path). The exception is properly caught and returned as a JSON
   422 response.

2. **`App\Exceptions\HttpException: Staff access required`** — Generated by
   `PaymentService::assertIsStaff()` when a non-staff user attempts bank sync.
   This is **expected behavior** (the test `PaymentTest::test_bank_sync_
   requires_staff` deliberately tests this).

3. No unhandled exceptions or fatal errors in the log.

### Known behavior

- The test suite uses `DatabaseMigrations` (in `BackupRestoreTest`) and
  `RefreshDatabase` (in `LegacyApiContractTest`). Other tests do not use
  either — they appear to run against the shared `khwwc_local` database.
  This could cause test pollution. The `phpunit.xml` sets
  `DB_DATABASE=khwwc_test`, but the `.env` in the project root sets
  `DB_DATABASE=khwwc_local`. The tests pass because the seeder creates
  known data and tests that don't use `DatabaseMigrations` operate on
  fresh data created within each test method.

- **No migration warnings or errors.** All migrations ran successfully.

- **No PHP deprecation notices** in the test output.

---

## 6. Current Queues

| Queue connection | Value |
|-------------------|-------|
| Default (`QUEUE_CONNECTION`) | `sync` |
| Failed job driver | `database-uuids` |
| Failed job table | `failed_jobs` |

**Queue health:** Since `QUEUE_CONNECTION=sync`, all jobs run synchronously
inline. There are no queue workers, no pending jobs, no failed jobs. The
`jobs` and `job_batches` tables exist (from Laravel default migration) but
are empty.

There is **one job** in the codebase: `CreateBackupJob`, which implements
`ShouldQueue` but runs synchronously when `QUEUE_CONNECTION=sync`.

---

## 7. Current Scheduled Tasks

As documented in section 3 above. The Laravel scheduler is configured but
there is **no cron entry** documented or set up. The `routes/console.php`
file includes a comment showing the required cron entry:

```
* * * * * cd /path/to/khwwc && php artisan schedule:run >> /dev/null 2>&1
```

Without this cron entry, the scheduled backup and prune tasks will **never
run**.

---

## 8. Current Redis Status

| Metric | Value |
|--------|-------|
| Redis installed | Yes (v8.0.6) |
| Redis running | **No** |
| Redis CLI | v8.0.6 |
| REDIS_HOST | 127.0.0.1 |
| REDIS_PORT | 6379 |
| Redis used for cache | No (cache = file) |
| Redis used for queue | No (queue = sync) |
| Redis used for sessions | No (session = file) |
| Redis used for rate limiting | No |
| Redis used for locks | No (Cache::lock uses file cache) |
| Redis used for broadcasting | No (BROADCAST_CONNECTION=log) |

Redis is **installed but not running and not used** by the application.

---

## 9. Current Authentication

| Aspect | Detail |
|--------|--------|
| Authentication type | Custom JWT (HS256, firebase/php-jwt) |
| Token TTL | 3600 seconds (1 hour) |
| Refresh token TTL | 2592000 seconds (30 days) |
| Password hashing | bcrypt (PASSWORD_BCRYPT, 12 rounds) |
| Session storage | File-based (local), array (testing) |
| Session lifetime | 120 minutes |
| Session cookie | `khwwc_session`, http_only, same_site=lax |
| CSRF protection | Yes (web routes) |
| API token revocation | Refresh tokens can be revoked; JWT access tokens cannot be revoked short of key rotation |
| MFA / WebAuthn | Not implemented (tables exist but unused) |
| Account lockout | Not implemented |
| Rate limiting | Not implemented |
| Password reset | `password_resets` table exists but flow not implemented |

---

## 10. Current Authorization

| Aspect | Detail |
|--------|--------|
| Authorization type | Role-based (RBAC) + row-level security |
| Roles defined | `member`, `admin`, `super_admin`, `treasurer`, `chairperson`, `vice_chairperson`, `secretary`, `vice_secretary`, `patron` |
| Role hierarchy | `super_admin` implies `admin`; no roles → `member` |
| Role storage | `user_roles` table (user_id, role, is_active) |
| RBAC enforcement | JWT middleware + AdminAccess middleware + PolicyService matrix |
| Row-level security | PolicyService::TABLES matrix (read/write/own scoping per table) |
| Laravel Policies | Not used — custom PolicyService only |
| Laravel Gates | Not used |
| Super Admin-only tables | `audit_logs`, `member_access_logs`, `system_logs`, `system_health`, `password_resets`, `coop_tokens` |

---

## 11. Test Command Summary

```bash
# Run all tests
php artisan test

# Run specific suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run specific test class
php artisan test --filter=BackupRestoreTest

# Route list
php artisan route:list

# Migration status
php artisan migrate:status

# Scheduled tasks
php artisan schedule:list
```

---

## 12. No Regressions Detected

All 65 existing tests pass with 202 assertions. The application is in a
healthy, working state. The baseline for regression protection is:

- **65 tests, 202 assertions — all green**
- **8 migrations — all ran successfully**
- **55 routes — all registered**
- **2 scheduled tasks — configured (but no cron entry set up)**
- **0 events, 0 listeners, 0 observers, 1 job**

No changes have been made during this audit. The baseline is clean.
