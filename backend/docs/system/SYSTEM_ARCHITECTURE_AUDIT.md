# System Architecture Audit — KHWWC Welfare

**Project:** `/home/laban/projects/welfare/khwwc`
**Laravel version:** 12.x (framework `^12.0`)
**PHP version:** 8.2+ (runtime: PHP 8.4.24)
**Database:** MySQL / MariaDB 11.8.8
**Last updated:** 2026-08-25

---

## 1. Overview

KHWWC (Kirinyaga Health Care Workers Welfare) is a Laravel 12 application that
reimplements the backend of a legacy PHP welfare platform. The application
mirrors the legacy API contracts (Supabase/PostgREST-style REST endpoints,
JWT authentication, row-level security via a Policy engine) while adding a
new Super Admin area for backup/restore management.

The system was migrated from a legacy custom PHP backend. Most of the codebase
is a faithful port: `AuthService`, `JwtService`, `PolicyService`,
`PostgrestQueryBuilder`, and `ApiResourceSerializer` are 1:1 ports of the
legacy `Auth.php`, `Jwt.php`, `Policy.php`, `QueryBuilder.php`, and `Casts.php`
libraries.

---

## 2. Architecture Layers

### 2.1 Frontend

```
Frontend (Blade templates)
    ↓
Authentication (JWT + session)
    ↓
Middleware (auth.jwt / admin / admin.guest)
    ↓
Controllers
    ↓
Services
    ↓
Models
    ↓
Database
```

The frontend consists of:

| Path | Description |
|------|-------------|
| `resources/views/admin/*.blade.php` | Super Admin Blade pages (layout, login, dashboard, users, groups, transactions, reports, audit-logs, backups, settings) |
| `resources/views/dashboard.blade.php` | Public dashboard page (shows system health, foundation modules) |
| `resources/views/welcome.blade.php` | Default Laravel welcome page |
| `resources/views/layouts/app.blade.php` | Public layout |
| `resources/css/app.css` | Public CSS (minimal — references admin.css) |
| `resources/js/app.js` | Bare import of bootstrap.js |
| `resources/js/bootstrap.js` | Laravel bootstrap (Axios, etc.) |
| `public/css/admin.css` | Super Admin CSS |
| `public/js/admin.js` | Super Admin shared UI helpers (vanilla JS, no build step) |
| `public/js/dashboard.js` | Public dashboard JS |

**Key observation:** The admin UI is rendered entirely via Blade templates
with vanilla JavaScript (no React/Vue/Inertia). The `admin.js` file provides
a `KH` object with API helpers (`get`, `post`, `put`, `del`), modal helpers,
XSS-safe `esc()`, and status badge renderers. The Super Admin pages fetch JSON
from `/admin/api/*` endpoints and render it client-side.

### 2.2 Authentication

The authentication system is **custom JWT-based**, not Laravel Sanctum or Passport:

| Component | File | Details |
|-----------|------|---------|
| JWT service | `app/Services/JwtService.php` | HS256 JWT using `firebase/php-jwt`. Encode/decode/verify. Payload includes `iss`, `iat`, `exp`, `sub`, `email`, `roles`. |
| Auth service | `app/Services/AuthService.php` | Issue session (access token + refresh token), identity resolution from token, role derivation, phone normalization, password hashing. |
| Config | `config/jwt.php` | Reads `JWT_SECRET`, `JWT_ISSUER`, `JWT_TTL` (3600s), `REFRESH_TTL` (2592000s = 30 days). |
| API auth middleware | `app/Http/Middleware/Authenticate.php` | Reads `Authorization: Bearer <token>`, decodes JWT, sets `identity` on request. Alias `auth.jwt`. |
| Admin auth controller | `app/Http/Controllers/Admin/AdminAuthController.php` | Web login: validates credentials, issues JWT stored in session as `admin_jwt`. Logout clears session. |
| Admin middleware | `app/Http/Middleware/AdminAccess.php` | Re-validates session JWT on every request, enforces `super_admin` role. Alias `admin`. |
| Guest middleware | `app/Http/Middleware/AdminGuest.php` | Redirects already-authenticated Super Admins away from login page. Alias `admin.guest`. |
| Auth users table | `auth_users` (schema.sql:7) | UUID PK, email, phone, encrypted_password, is_banned, last_sign_in_at, raw_user_meta_data. |
| Refresh tokens | `auth_refresh_tokens` (schema.sql:23) | UUID, user_id FK, token (unique), revoked, expires_at. Rotation: old token revoked on refresh. |
| Standard users | `users` table (migrations) | Laravel default users table — **NOT used by the application**. Present from default Laravel migration. |
| Password reset | `password_resets` (schema.sql:713) | UUID PK, user_id FK, reset_token, new_password_hash, expires_at, reason. |

**JWT token claims:**
```json
{
  "iss": "khcww-welfare",
  "iat": 1724588267,
  "exp": 1724591867,
  "sub": "<user_uuid>",
  "email": "254700000001@welfare.local",
  "roles": ["super_admin", "admin"]
}
```

**Role hierarchy:** `super_admin` implicitly grants `admin`. No roles →
defaults to `member`. Roles are stored in `user_roles` table and resolved
at login/issue time — they are embedded in the JWT payload.

### 2.3 Authorization / Middleware

| Middleware alias | Class | Purpose |
|---|---|---|
| `auth.jwt` | `App\Http\Middleware\Authenticate` | API bearer JWT validation. Sets `identity` on request. |
| `admin` | `App\Http\Middleware\AdminAccess` | Super Admin web area. Re-validates session JWT, enforces `super_admin` role. |
| `admin.guest` | `App\Http\Middleware\AdminGuest` | Redirects authenticated Super Admins away from login page. |

**Row-level authorization (PolicyService):**

Authorization beyond the Super Admin area is handled by `App\Services\PolicyService`,
which implements a row-level security matrix ported from the legacy `Policy.php`.
The `PolicyService::TABLES` constant defines per-table read/write rules:

- `read: '*'` — any authenticated user may read all rows
- `read: 'STAFF'` — only staff (admin/super_admin/treasurer/chairperson/etc.) may read all rows; others are scoped to their own rows
- `read: [role, ...]` — only listed roles may read all rows
- `write: '*'` — any authenticated user may write all rows
- `write: [role, ...]` — only listed roles
- `own: column` — owner-scoping column (member_id/user_id)
- `ownWrite: true` — members may insert/update their own rows
- `publicInsert: true` — no authentication required for INSERT
- `staffOnlyRead: true` — non-staff are scoped to own rows

The policy matrix covers all 76+ business tables.

**Note:** Laravel Policies (`app/Policies/`) and Gates are **NOT used**.
Authorization is entirely handled by the custom `PolicyService` and the
middleware. The `User` model extends `Authenticatable` (Laravel default),
but the application does not use Laravel's built in auth facade for the
legacy API — it uses its own JWT-based system.

### 2.4 Controllers

| Controller | Namespace | Purpose |
|---|---|---|
| `Controller` | `App\Http\Controllers` | Base controller (uses `AuthorizesRequests`, `ValidatesRequests`). |
| `HealthController` | `App\Http\Controllers` | `/api/health` (no Auth), `/api/system/info` (no Auth). Reports DB, cache, queue, session, Redis status. |
| `LegacyApiController` | `App\Http\Controllers` | Auth v1: token (password grant + refresh), signup, logout, user, changePassword. |
| `LegacyRestController` | `App\Http\Controllers` | Generic REST CRUD for arbitrary tables via PostgREST-style query params. |
| `LegacyStorageController` | `App\Http\Controllers` | Storage v1: upload, download, signed URL, delete. Path traversal protection. |
| `PaymentController` | `App\Http\Controllers` | STK push, STK callback, status, bank sync, B2C transfer. |
| `WalletController` | `App\Http\Controllers` | Credit, debit, balance, transactions per wallet type. |
| `NotificationController` | `App\Http\Controllers` | Bulk SMS, push notifications. |
| `Admin\AdminAuthController` | `App\Http\Controllers\Admin` | Super Admin web login/logout. |
| `Admin\AdminDashboardController` | `App\Http\Controllers\Admin` | Dashboard page + stats (backup metrics, user/member counts). |
| `Admin\AdminDataController` | `App\Http\Controllers\Admin` | Read-only listings: users, groups, transactions, reports, audit logs. |
| `Admin\BackupAdminController` | `App\Http\Controllers\Admin` | Backup CRUD: list, create, show, verify, download, delete, restore, settings, prune. |

**No repository pattern** is used. Controllers interact directly with
`DB::table()` and Eloquent models. No service repository layer exists.

### 2.5 Services

| Service | File | Responsibilities |
|---|---|---|
| `AuthService` | `app/Services/AuthService.php` | JWT session issuance, identity resolution, role derivation, password hash/verify, phone normalization. |
| `JwtService` | `app/Services/JwtService.php` | HS256 JWT encode/decode/verify/buildPayload. |
| `PolicyService` | `app/Services/PolicyService.php` | Row-level security matrix, read/write assertions. |
| `WalletService` | `app/Services/WalletService.php` | Wallet credit/debit (within DB transactions), balance, transaction history. Three wallet types: penalty, donation, operational. |
| `PaymentService` | `app/Services/PaymentService.php` | STK push initiation, callback processing, payment status, bank sync, B2C transfer. |
| `NotificationService` | `app/Services/NotificationService.php` | Bulk SMS (simulated), push notifications (simulated). |
| `MockPaymentProvider` | `app/Services/MockPaymentProvider.php` | Simulated M-Pesa STK push, bank transactions, B2C transfers. No real API integration. |
| `Backup\BackupManager` | `app/Services/Backup/BackupManager.php` | Orchestrates backup creation, verification, audit. Dispatches `CreateBackupJob`. |
| `Backup\BackupVerificationService` | `app/Services/Backup/BackupVerificationService.php` | Post-write verification: file exists, readable, size, checksum, structural validity. |
| `Backup\DatabaseBackupService` | `app/Services/Backup/DatabaseBackupService.php` | mysqldump/mysql CLI dump/restore with `--defaults-extra-file` credential protection. |
| `Backup\StorageBackupService` | `app/Services/Backup/StorageBackupService.php` | ZIP archive/extract of `storage/app` with exclusion rules. |
| `Backup\RestoreService` | `app/Services/Backup/RestoreService.php` | Restore orchestration: validation, safety backup, maintenance lock, cache clear, health checks. |
| `Backup\BackupAudit` | `app/Services/Backup/BackupAudit.php` | Writes backup/restore events to `audit_logs` table. |

### 2.6 Models

**83 model classes** exist in `app/Models/`. They fall into two categories:

1. **Flat data-access models** (~80 models): UUID PK, `$incrementing = false`,
   `$keyType = 'string'`, `$timestamps = false`, `$guarded = []`. No
   relationships, no scopes, no accessors. These are direct ports from the
   legacy schema and are used primarily by the generic REST controller.
   Examples: `AuthUser`, `Member`, `UserRole`, `AuditLog`, `Contribution`,
   `Payment`, `Payout`, `Penalty`, `WalletTransaction`, `BookBalance`, etc.

2. **Rich models** (3 models):
   - `Backup` — UUID PK with `creating` hook (auto UUID), status constants,
     `completed`/`notSafety` scopes, `isDownloadable()`/`isRestorable()` methods.
   - `BackupSetting` — UUID PK with `creating` hook, `current()` singleton accessor.
   - `User` — Laravel default `Authenticatable` (NOT used by the application's
     auth system; present from the default Laravel migration).

3. **The `Event` model** (`app/Models/Event.php`) — this is a data model for
   welfare events (funerals, meetings), **NOT** a Laravel event. There are
   no Laravel Events/Events dispatch anywhere in the codebase.

**No model relationships** (hasMany, belongsTo, etc.) are defined. The
generic REST controller uses raw `DB::table()` queries with the PostgREST
query builder.

### 2.7 Database

- **Engine:** MySQL / MariaDB 11.8.8
- **Configuration:** `config/database.php` — single `mysql` connection + `sqlite` fallback
- **Connection:** `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=khwwc_local`
- **Charset:** `utf8mb4`, `utf8mb4_unicode_ci`, strict mode enabled
- **Schema import:** The main schema is imported via the
  `2026_08_24_120000_import_full_schema` migration, which loads raw SQL from
  `database/schema.sql` (49KB, ~75 tables).
  This is a one-time import that does not support rollback.
- **Migrations:** 8 migrations total (3 Laravel default + 1 member_executive_roles + 1 full schema import + 1 timestamps + 2 backup tables)
- **Tables:** ~80 tables including all legacy welfare tables (members, contributions,
  penalties, wallets, payments, withdrawals, expenses, financial_reports, audit_logs,
  system_logs, system_health, etc.)

**Financial tables:**
- `contributions` — member monthly contributions
- `penalties` — late payment penalties
- `penalty_payments` / `penalty_payment_records` — penalty payment tracking
- `donation_payment_records` — donation payment records
- `operational_payment_records` — operational payment records
- `payments` — raw bank SMS payments (matched/unmatched)
- `unmatched_payments` — unmatched payment records
- `payouts` — member payouts (funeral benefits, etc.)
- `penalty_wallet`, `donation_wallet`, `operational_wallet` — wallet aggregates
- `wallet_transactions` — transaction ledger (all wallet movements)
- `book_balance` — manual bookkeeping entries
- `bank_transactions` — bank statement transactions
- `b2c_transactions` — B2C transfer records
- `expenses` — operational expenses
- `financial_reports` — generated financial reports
- `withdrawal_signatories`, `penalty_withdrawal_signatories`,
  `donation_withdrawal_signatories`, `operational_withdrawal_signatories` —
  multi-signatory withdrawal approval
- `withdrawal_receipts` — generated withdrawal receipts

### 2.8 Background Processing

```
Events → (none — no Laravel Events)
Listeners → (none — no Laravel Listeners)
Jobs → (1) CreateBackupJob — async backup generation
Queue → sync (local/testing), database/redis (production config)
Redis → configured but NOT running; NOT used for cache/queue/session
```

- **Queue driver:** `sync` (local `.env` and `phpunit.xml`). The
  `QUEUE_CONNECTION` can be set to `database` or `redis` in production.
- **Jobs table:** Created by default Laravel migration (supports `database` driver.
- **Failed jobs table:** `failed_jobs` table exists.
- **Job:** `CreateBackupJob` — dispatched by `BackupManager::create()` when
  `$dispatch = true` (default). Under `sync` queue, runs inline. Has retry
  failure handling (re-throws to mark job as failed).
- **No queue workers configured** in the application — production would need
  `php artisan queue:work` running via supervisor/systemd.

### 2.9 Events, Listeners, Observers

**NONE exist.** The application does not use Laravel's event system at all.

- No `app/Events/` directory
- No `app/Listeners/` directory
- No `app/Observers/` directory
- No `EventServiceProvider`
- No `event()` or `Event::dispatch()` calls (only `CreateBackupJob::dispatch()`
  which uses the queue system, not events)
- No `Model::observe()` calls
- No `booted()` methods using events
- No `creating`/`created`/`updating`/`updated` model lifecycle hooks (except
  `Backup` model's `creating` hook for UUID generation, which is not an event
  listener but an Eloquent model hook)

**Financial side-effects:** All financial logic (wallet credit/debit, payment
processing, callback handling) is executed **synchronously** within controller
action methods, wrapped in `DB::transaction()` blocks. There are no queued
listeners for financial operations.

### 2.10 Scheduled Tasks (Scheduler)

Defined in `routes/console.php` (Laravel 12 uses `routes/console.php`
instead of `app/Console/Kernel.php`):

| Task | Frequency | Condition | Command |
|------|-----------|-----------|---------|
| Daily full backup | 02:00 daily | `BackupSetting::current()->settings['automatic_backup_enabled']` must be `true` | `backup:run --type=full` (via `BackupRunCommand`) |
| Daily backup prune | 02:30 daily | `backup.retention.keep_last > 0` AND `backup.retention.auto_prune` must be `true` | `backup:prune` (via `BackupPruneCommand`) |

Both tasks run `evenInMaintenanceMode()`.

**Custom artisan commands:**
- `backup:run` — `BackupRunCommand` — creates a backup, optionally marked as safety. Detects "system actor" (first super_admin or persisted system UUID).
- `backup:prune` — `BackupPruneCommand` — deletes backups beyond retention limit. Can accept `--keep=N` override.

### 2.11 Redis

- **Installed:** Redis server v8.0.6, redis-cli v8.0.6
- **Running:** **NO** — Redis server is not currently running
- **Configuration in .env:**
  - `REDIS_CLIENT=predis`
  - `REDIS_HOST=127.0.0.1`
  - `REDIS_PORT=6379`
  - `REDIS_PASSWORD=null`
  - `REDIS_CACHE_DB=1`
  - `REDIS_QUEUE_DB=2`
- **Configuration in config/database.php:**
  - `redis.default` — host, port, database, password
  - `REDIS_DB` defaults to `0`
- **Actual Redis usage:** **NONE** — Redis is configured in `.env` and `config/database.php` but is NOT used:
  - `CACHE_STORE=file` (not redis)
  - `QUEUE_CONNECTION=sync` (not redis)
  - `SESSION_DRIVER=file` (not redis)
  - No Redis rate limiting (Laravel does not enable the `cache` rate limiter by default)
  - No Redis broadcasting
  - No Redis locks (the `Cache::lock()` in `RestoreService` uses file cache)
- The `HealthController::redisStatus()` method calls `Redis::ping()` and
  reports the status, but since Redis is not running, this will report an error.

### 2.12 Cache

- **Driver:** `file` (local `.env`), `array` (testing `phpunit.xml`)
- **Prefix:** `khwwc_` (from `config/cache.php`)
- **Stores configured:** `array`, `file`, `database` (but `redis` store is NOT
  configured despite Redis being in the dependencies)
- **Note:** The `config/cache.php` file does not define a `redis` cache store,
  even though `predis/predis` is in composer.json and Redis is configured in
  `config/database.php`. If Redis were enabled as cache, the cache config
  would need a redis store entry.

### 2.13 Sessions

- **Driver:** `file` (local `.env`), `array` (testing `phpunit.xml`)
- **Lifetime:** 120 minutes
- **Cookie:** `khwwc_session`, `http_only`, `same_site=lax`
- **Table:** `sessions` (Laravel default, from migration 0001_01_01_000000)
- **Super Admin sessions:** The admin web login stores a JWT in the session
  (`admin_jwt`). The `AdminAccess` middleware re-validates this JWT on every
  request — there is no server-side session tracking of login activity.

### 2.14 Storage

- **Default disk:** `local` (root: `storage/app`)
- **Public disk:** `public` (root: `storage/app/public`, web-accessible via `php artisan storage:link`)
- **S3 disk:** configured but credentials empty
- **Backups disk:** `backups` (root: `storage/app/backups`, visibility: `private`) —
  only defined in `config/filesystems.php`
- **Directories in `storage/app/`:** `backups/`, `private/`, `public/`
- **Storage object metadata:** tracked in `storage_objects` table (bucket, object_path,
  mime_type, size_bytes, is_public, owner_id)

### 2.15 Logging

- **Default channel:** `stack` (stacks `single`)
- **Single channel:** `storage/logs/laravel.log`, level: `debug`
- **Log size:** ~12MB in the current installation
- **No Monolog processors** for audit/security event separation
- **No dedicated security logging channel**

### 2.16 Audit Logging

**Existing audit infrastructure:**

| Table | Description |
|-------|-------------|
| `audit_logs` (schema.sql:65) | Super Admin actions: `super_admin_id`, `action`, `target_user_id`, `target_member_id`, `details` (JSON), `ip_address`, `created_at`. |
| `system_logs` (schema.sql:946) | Application system logs: `log_level`, `component`, `message`, `error_details`, `user_id`, `request_path`, `status_code`, `resolved`, `resolved_by`, `resolved_at`. |
| `member_access_logs` (schema.sql:450) | Member access tracking: `super_admin_id`, `member_id`, `access_type`, `reason`. |
| `sms_logs` (schema.sql:924) | SMS send logs: `recipient_phone`, `message`, `status`, `provider_ref`. |

**Audit logging mechanism:**

The `BackupAudit` service writes to the `audit_logs` table for all backup and
restore operations:
- `BACKUP_CREATED`, `BACKUP_VERIFIED`, `BACKUP_DOWNLOADED`, `BACKUP_DELETED`
- `RESTORE_STARTED`, `RESTORE_COMPLETED`, `RESTORE_FAILED`
- `SAFETY_BACKUP_CREATED`, `BACKUP_PRUNED`, `BACKUP_SETTINGS_UPDATED`

The `audit_logs` table is excluded from database dumps (to prevent a restore from
clobbering the audit trail), and safety backups are created before restores.

**No general application event logging** exists — only the backup subsystem logs
to `audit_logs`. There is no logging of:
- API authentication events (login success/failure)
- Password changes
- Role/permission changes
- Wallet transactions
- Payment processing
- User management actions

### 2.17 API

**Routes:** Defined in `routes/api.php`

| Prefix | Middleware | Endpoints |
|--------|-----------|-----------|
| (none) | none | `GET /api/health`, `GET /api/system/info` |
| `/api/auth/v1` | mixed | `POST /token` (no Auth), `POST /signup` (no Auth), `POST /logout` (auth.jwt), `GET /user` (auth.jwt), `POST /password` (auth.jwt) |
| `/api/rest/v1` | auth.jwt | `MATCH /{table}` (generic CRUD), `POST /rpc/{fn}` (stub) |
| `/api/storage/v1` | mixed | `GET /object/{bucket}/{path?}` (no Auth), `GET /object/.../signed-url` (auth.jwt), `POST /object/...` (auth.jwt), `DELETE /object/...` (auth.jwt) |
| `/api` | none | `GET /me` (auth.jwt) |
| `/api/payments` | mixed | `POST /stk-push` (auth.jwt), `POST /stk-callback` (no Auth), `GET /status/{id}` (auth.jwt), `POST /bank-sync` (auth.jwt), `POST /b2c` (auth.jwt) |
| `/api/notifications` | auth.jwt | `POST /bulk-sms`, `POST /push` |
| `/api/wallets/{walletType}` | auth.jwt | `POST /credit`, `POST /debit`, `GET /balance`, `GET /transactions` |
| `/api/{table}` | auth.jwt | Generic CRUD for whitelisted tables |

**Total API routes:** ~22 distinct endpoints (plus the generic REST routes)

**API design:** PostgREST-compatible (Supabase-style). Query parameters
include `select`, `order`, `limit`, `offset`, `count=exact`, `or=(...)`,
and column filters like `status=eq.paid`.

**CORS:** `config/cors.php` — allows credentials, specific localhost origins
from `CORS_ALLOWED_ORIGINS`, no wildcard. Preflight supported.

### 2.18 Admin Interface

**Super Admin area:** Protected by `admin` middleware (JWT session re-validation
+ super_admin role enforcement). Available at `/admin/*`.

| Route | Controller | Purpose |
|-------|-----------|---------|
| `/admin/login` | `AdminAuthController@showLogin` | Login page |
| `POST /admin/login` | `AdminAuthController@login` | Login handler |
| `POST /admin/logout` | `AdminAuthController@logout` | Logout handler |
| `/admin` | `AdminDashboardController@page` | Dashboard page |
| `/admin/backups` | `BackupAdminController@page` | Backups page |
| `/admin/settings` | `BackupAdminController@settingsPage` | Settings page |
| `/admin/users` | `AdminDataController@users` | Users page |
| `/admin/groups` | `AdminDataController@groups` | Groups page |
| `/admin/transactions` | `AdminDataController@transactions` | Transactions page |
| `/admin/reports` | `AdminDataController@reports` | Reports page |
| `/admin/audit-logs` | `AdminDataController@auditLogs` | Audit logs page |
| `/admin/api/*` | JSON APIs | Data endpoints for the above pages |

**Sidebar navigation:**
```
KHWWC — Super Admin
├── Management
│   ├── Dashboard
│   ├── Users
│   ├── Groups
│   ├── Transactions
│   ├── Reports
│   └── Audit Logs
└── System
    ├── Backups & Restore
    └── Settings
```

The sidebar is defined in `resources/views/admin/layout.blade.php` as static
HTML with Blade route-active state. There is no dynamic menu system.

### 2.19 Dependencies

**composer.json require:**
| Package | Version | Purpose |
|---------|---------|---------|
| `firebase/php-jwt` | ^7.1 | JWT encode/decode (HS256) |
| `laravel/framework` | ^12.0 | Laravel framework |
| `laravel/tinker` | ^2.10.1 | REPL |
| `predis/predis` | ^3.6 | Redis client |

**composer.json require-dev:**
| Package | Version | Purpose |
|---------|---------|---------|
| `fakerphp/faker` | ^1.23 | Test data generation |
| `laravel/pail` | ^1.2.2 | Log inspection |
| `laravel/pint` | ^1.24 | Code formatting |
| `laravel/sail` | ^1.41 | Docker dev environment |
| `mockery/mockery` | ^1.6 | Test mocking |
| `nunomaduro/collision` | ^8.6 | Error rendering |
| `phpunit/phpunit` | ^11.5.50 | Testing |

**Note:** `ramsey/uuid` is used by `app/Support/Uuid.php` but is NOT listed in
`composer.json` explicitly — it is a transitive dependency of Laravel framework.

**Notable: No additional security, monitoring, or admin-panel packages.**
The application uses only Laravel framework + JWT + Redis client.

---

## 3. Data Flow

### 3.1 API Authentication Flow

1. Client POSTs `email`+`password` (or `phone`) to `/api/auth/v1/token`
2. `LegacyApiController::token()` looks up `auth_users` by email
3. Verifies password via `AuthService::verifyPassword()` (bcrypt)
4. Issues session via `AuthService::issueSession()`:
   - JWT access token (HS256, TTL from `JWT_TTL`)
   - Random refresh token (stored in `auth_refresh_tokens`, TTL from `REFRESH_TTL`)
   - Roles embedded in JWT payload
5. Client uses access token as `Authorization: Bearer <token>`
6. `Authenticate` middleware (`auth.jwt`) decodes JWT → sets `identity` on request
7. `PolicyService` enforces row-level security per-table in the REST controller

### 3.2 Super Admin Authentication Flow

1. User visits `/admin/login`
2. `AdminAuthController::login()` validates credentials via `AuthService`
3. Verifies `super_admin` role via `AuthService::isSuperAdmin()`
4. Issues JWT and stores in session (`admin_jwt`)
5. Regenerates session ID (session fixation protection)
6. `AdminAccess` middleware re-validates JWT on every request
7. Non-super-admin users are rejected with 403

### 3.3 Backup Flow

1. Super Admin clicks "Create Full Backup" in the UI
2. `BackupAdminController::store()` → `BackupManager::create()`
3. Checks disk space, creates `Backup` record (status=pending)
4. Dispatches `CreateBackupJob` (runs synchronously under `sync` queue)
5. `CreateBackupJob::handle()` → `BackupManager::perform()`:
   - Sets status=running, starts timing
   - Generates database dump (mysqldump) and/or storage ZIP
   - Records checksum (SHA256), size, metadata
   - Runs verification (`BackupVerificationService::verify()`)
   - Logs `BACKUP_CREATED` to `audit_logs`
6. UI shows backup as completed + verified

### 3.4 Payment / Wallet Flow

1. Client POSTs to `/api/payments/stk-push` with amount, phone, payment type
2. `PaymentController::stkPush()` validates input, delegates to `PaymentService::initiateStkPush()`
3. `PaymentService` checks authorization (staff or self), creates pending payment record
4. `MockPaymentProvider::simulateStkPush()` — stores pending state (no real API)
5. Later, M-Pesa callback POSTs to `/api/payments/stk-callback` (no Auth)
6. `PaymentController::stkCallback()` → `PaymentService::processCallback()`:
   - Updates payment record status to `verified` or `failed`
   - If verified: credits donation wallet via `WalletService` (or inline credit logic)
7. Wallet credit/debit: `/api/wallets/{type}/credit` and `/debit`
   - `WalletService::credit/debit` — wraps in `DB::transaction`, updates wallet aggregate + creates `WalletTransaction`

### 3.5 Scheduled Backup Flow

1. Cron runs `php artisan schedule:run` every minute
2. Laravel evaluates schedule conditions:
   - 02:00: `BackupRunCommand` runs only if `automatic_backup_enabled` is true
   - 02:30: `BackupPruneCommand` runs only if retention > 0 and auto_prune is true
3. `BackupRunCommand` detects "system actor" (first super_admin or persisted UUID)
4. Creates backup synchronously (`dispatch: false`)
5. Logs to `audit_logs` with system actor

---

## 4. Summary Statistics

| Metric | Count |
|--------|-------|
| Models | 83 |
| Controllers | 13 |
| Services | 11 (including 6 backup subservices) |
| Middleware | 3 (auth.jwt, admin, admin.guest) |
| Routes (total) | 55 |
| API routes | ~22 (+ generic REST) |
| Admin routes | 20 |
| Console commands | 3 (inspire, backup:run, backup:prune) |
| Scheduled tasks | 2 |
| Jobs | 1 (CreateBackupJob) |
| Events | 0 |
| Listeners | 0 |
| Observers | 0 |
| Migrations | 8 |
| Unit tests | 18 |
| Feature tests | 47 |
| Total tests | 65 (202 assertions) |
| Database tables | ~80 |
| Config files | 11 |

---

## 5. Gaps and Missing Components

### 5.1 Security Centre (MISSING)

There is **no Security Centre** in the application. The Super Admin navigation
contains: Dashboard, Users, Groups, Transactions, Reports, Audit Logs,
Backups & Restore, Settings. A dedicated Security Centre section does not exist.

### 5.2 Login Security (MISSING)

- No login attempt throttling/rate limiting
- No failed login tracking (no `login_attempts` or similar table)
- No account lockout after repeated failures
- No brute-force protection
- No MFA/WebAuthn enforcement (the `dashboard_security` table exists but
  is never referenced by any code)
- No password strength enforcement (only ">= 6 characters")

### 5.3 Active Session Management (MISSING)

- No server-side session tracking for API tokens
- JWT is stateless; no revocation list (except refresh tokens)
- No device/browser detection
- No "active sessions" view for Super Admin
- Session revocation not possible (JWT tokens cannot be revoked short of
  changing the signing key)

### 5.4 Security Event Logging (PARTIAL)

- `audit_logs` table exists and is used by BackupAudit only
- No general security event logging (login success/failure, role changes,
  password changes, wallet operations)
- No `SecurityEvent` model or service

### 5.5 Rate Limiting (MISSING)

- No rate limiting middleware on any API routes
- No Laravel rate limiter configuration for API
- No brute-force protection on login endpoints

### 5.6 Redis (CONFIGURED BUT UNUSED)

- Redis server software installed but NOT running
- Configured in `.env` for cache/queue/session DBs but NOT used
- `HealthController::redisStatus()` calls `Redis::ping()` which will fail
- Cache/queue/session all use file/array drivers

### 5.7 Queue Monitoring (MINIMAL)

- Queue is `sync` — no separate workers
- No queue monitoring interface
- No failed job management UI
- `jobs` and `failed_jobs` tables exist (from default Laravel migration)

### 5.8 System Health Monitoring (PARTIAL)

- `system_health` table exists but is never written to by any code
- `HealthController` provides basic health check but no persistent metrics
- No Redis health monitoring (Redis not running)
- No scheduler health monitoring

---

## 6. Technical Debt and Observations

1. **`config/cache.php` has no Redis store** — despite `predis/predis` being
   installed and Redis being configured in `config/database.php`. If Redis
   is enabled for caching, a `redis` cache store entry is needed.

2. **Migration `2026_08_24_120000_import_full_schema`** loads SQL from a
   relative path (`database/schema.sql`). This file is part of the Laravel

3. **The `users` table** (Laravel default) exists but is never used.
   Authentication uses `auth_users` instead.

4. **`MockPaymentProvider`** simulates all payment operations. There is no
   real M-Pesa API integration in the codebase. STK push callbacks are
   simulated in-memory (no persistence of pending state across requests).

5. **No `.env.production`** file — production environment configuration
   is not present. The `.env.example` uses SQLite and different cache/queue
   settings than the actual `.env`.

6. **`APP_DEBUG=true`** in the local `.env` — this is correct for local
   development but would expose stack traces in production if not changed.

7. **JWT secret is a fallback default** in `config/jwt.php` — if `JWT_SECRET`
   is not set in `.env`, a known default is used. This is a security risk
   in production.

8. **No password reset implementation** — the `password_resets` table exists
   and is listed in `PolicyService::TABLES`, but there is no controller or
   service handling password reset flow (only `changePassword` in
   `LegacyApiController`).

9. **The `Event` model** name conflicts conceptually — it is a data model
   for welfare events, not a Laravel event. This could cause confusion.

10. **CSRF protection:** The admin area uses Laravel's built-in CSRF
    protection (`csrf_token()` in the layout, `VerifyCsrfToken` middleware
    for web routes). API routes do NOT have CSRF protection (correct for
    JWT-based auth).

11. **File upload security:** `LegacyStorageController` has path traversal
    protection (`safePath()`), but the storage paths are predictable
    (`/storage/{bucket}/{path}`) and the download endpoint for public
    storage is not authenticated.
