# System Health Architecture — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Existing Health Infrastructure

### 1.1 HealthController (unauthenticated)

`app/Http/Controllers/HealthController.php` provides two endpoints:

| Endpoint | Middleware | Purpose |
|----------|-----------|---------|
| `GET /api/health` | none | Lightweight health check (database connectivity) |
| `GET /system/info` | none | System information probe |

#### /api/health response

```json
{
    "status": "ok",
    "service": "khcww-welfare-api",
    "database": "connected",
    "time": "2026-08-25T08:17:47+00:00"
}
```

Returns HTTP 200 if database is connected, HTTP 503 if degraded.

#### /system/info response

```json
{
    "status": "ok",
    "service": "khcww-welfare-api",
    "time": "2026-08-25T08:17:47+00:00",
    "checks": {
        "php_version": "8.4.24",
        "laravel_version": "12.x",
        "database": "khwwc_local",
        "cache_store": "file",
        "queue_connection": "sync",
        "session_driver": "file",
        "storage_writable": true,
        "redis": "error: Connection refused"
    }
}
```

**Note:** The `/system/info` endpoint calls `redisStatus()` which attempts
`Redis::ping()`. Since Redis is not running, this returns `"error:
Connection refused"`.

### 1.2 system_health table

The `system_health` table exists in the schema:

```sql
CREATE TABLE system_health (
    id CHAR(36) NOT NULL PRIMARY KEY,
    metric_name VARCHAR(255) NOT NULL,
    metric_value DECIMAL(14,2) DEFAULT NULL,
    status VARCHAR(255) DEFAULT NULL,
    details LONGTEXT DEFAULT NULL,
    checked_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_system_health_status (status)
)
```

**This table is NEVER WRITTEN TO by any code.** It is defined in the schema
but no service, controller, job, or command populates it. It is listed in
`PolicyService::TABLES` with `read: ['super_admin']`, `write: ['super_admin']`,
`staffOnlyRead: true` — meaning only Super Admin can read/write it, but no
code writes to it.

### 1.3 system_logs table

The `system_logs` table exists:

```sql
CREATE TABLE system_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    log_level VARCHAR(255) NOT NULL,
    component VARCHAR(255) DEFAULT NULL,
    message TEXT NOT NULL,
    error_details LONGTEXT DEFAULT NULL,
    user_id CHAR(36) DEFAULT NULL,
    request_path VARCHAR(255) DEFAULT NULL,
    status_code INT DEFAULT NULL,
    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    resolved TINYINT(1) DEFAULT 0,
    resolved_by CHAR(36) DEFAULT NULL,
    resolved_at DATETIME(3) DEFAULT NULL
)
```

**This table is NEVER WRITTEN TO** by application code. It is a legacy table
that exists for the old PHP backend's logging. The current Laravel application
logs to `storage/logs/laravel.log` (Monolog), not to this table.

### 1.4 Laravel log

- **Channel:** `stack` → `single`
- **File:** `storage/logs/laravel.log` (~12MB)
- **Level:** `debug`
- **Format:** Standard Laravel/Monolog format
- **Retention:** No retention policy configured (default: single file, no rotation)

---

## 2. DashboardSecurity Table

```sql
CREATE TABLE dashboard_security (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    pin_hash TEXT DEFAULT NULL,
    webauthn_credential_id TEXT DEFAULT NULL,
    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)
)
```

**This table exists for WebAuthn/MFA support but is NEVER referenced by any
application code.** It is listed in `PolicyService::TABLES` with
`read: [], write: []` (no one can read or write it via the REST API —
`staffOnlyRead: true` makes even staff scoped to own rows).

---

## 3. System Health for Security Centre

### 3.1 Components to monitor

| Component | Current status | How to check |
|-----------|---------------|-------------|
| Database | Configurable (MySQL/MariaDB) | `DB::connection()->getPdo()` |
| Redis | **Not running**, not used | `Redis::ping()` |
| Cache | File-based (local) | `Cache::store()->get('health_test')` |
| Queue | Sync (inline) | Check `jobs` table, `failed_jobs` table |
| Storage write | Writable | `is_writable(storage_path('app'))` |
| Session | File-based | Session table count or config |
| Scheduler | Configured, cron not set up | Check last run timestamp |
| Backups | System exists | Query `backups` table for last status |

### 3.2 Health indicators

| Status | Meaning |
|--------|---------|
| **Healthy** (●) | Component is running and functional |
| **Degraded** (●) | Component is partially functional or degraded |
| **Unavailable** (●) | Component is not running or not functional |

### 3.3 Current health assessment

| Component | Status | Details |
|-----------|--------|---------|
| Database | ○ Healthy | MySQL/MariaDB 11.8.8, connection verified |
| Redis | ⚠ Unavailable | Installed but not running; not used by application |
| Cache | ○ Healthy | File-based, working |
| Queue | ○ Healthy | Sync driver, all jobs run inline |
| Storage | ○ Healthy | Writeable |
| Session | ○ Healthy | File-based, working |
| Scheduler | ⚠ Degraded | Configured but no cron entry |
| Backups | ○ Healthy | System exists, tested, all backup tests pass |
| Audit logs | ○ Healthy | Writing to `audit_logs` table via `BackupAudit` |

---

## 4. Recommended Unified System Health Area

The Security Centre should include a System Health section that consolidates:

```
Security Centre
├── Security Overview
├── Login Activity
├── Active Sessions
├── Security Events
├── Audit Logs
├── Redis Health
├── Queue Health
├── Scheduler Health
└── System Health
```

Each sub-section should report real metrics, not fabricated data.
