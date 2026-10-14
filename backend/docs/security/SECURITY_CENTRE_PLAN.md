# Security Centre Implementation Plan — KHWCC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)
**Status:** Planning — no code changes made yet

---

## 1. Executive Summary

The KHWCC Welfare application has a solid but minimal security foundation:
- Authentication works via custom JWT (`firebase/php-jwt`)
- The admin area is protected by an `admin` middleware enforcing `super_admin` role
- 65 tests pass, 8 migrations ran, 55 routes
- Redis is installed but not running or used
- The existing `audit_logs` table (used only for backup/restore events) is
  **excluded from database dumps** (see `config/backup.php`)

### Critical gaps that must be addressed before the Security Centre:

| # | Gap | Severity |
|---|-----|----------|
| 1 | `PolicyService` is defined but NOT called by `LegacyRestController` — any authenticated user can read/write any table via generic REST API | CRITICAL |
| 2 | `WalletService::credit()` and `WalletService::debit()` have NO role authorization checks — any authenticated user can move money | CRITICAL |
| 3 | JWT secret has a hardcoded fallback default `khcww-welfare-change-this-secret-9f3a2b7c1d4e5f60` in `config/jwt.php` | HIGH |
| 4 | No rate limiting on login or API endpoints — brute-force is possible | HIGH |
| 5 | No security event logging — cannot detect or investigate incidents | HIGH |
| 6 | `APP_DEBUG=true` exposes sensitive information in production | HIGH |
| 7 | No CSRF protection on web routes (Laravel `VerifyCsrfToken` may be disabled) | MEDIUM |
| 8 | Database runs as `root` with empty password | MEDIUM |
| 9 | STK/LNM callback endpoint is public with no IP allowlist or signature verification | MEDIUM |
| 10 | Redis is installed but not used for caching/sessions/queue (health check will show "down") | LOW |

### Recommendation

The Security Centre should be delivered in **four phases**:
- **Phase 0 (P0):** Fix critical security gaps (items 1–3 above)
- **Phase 1 (P1):** Basic Security Centre shell — read-only dashboard
- **Phase 2 (P2):** Active security features — session management, rate limiting
- **Phase 3 (P3):** Advanced — anomaly detection, security score automation

---

## 2. Phase 0 (P0) — Critical Security Fixes

These are non-negotiable prerequisites for the Security Centre.

### 2.1 Enable PolicyService on REST API

**Problem:** `PolicyService::TABLES` defines a complete per-table access matrix
with read/write rules per role, but the `LegacyRestController` does not call
any `PolicyService` methods.

**Plan:**
1. Add `PolicyService` calls to `LegacyRestController`:
   - `GET /rest/v1/{table}` → call `PolicyService::assertRead($table, $user)`
   - `POST/PUT/PATCH/DELETE /rest/v1/{table}` → call
     `PolicyService::assertWrite($table, $user)`
2. Add tests for row-level authorization (member cannot read members table,
   admin can, etc.)

**File references:**
- `app/Services/PolicyService.php` — the matrix to wire in
- `app/Http/Controllers/Api/LegacyRestController.php` — where to add calls

### 2.2 Add Wallet Authorization

**Problem:** `WalletService::credit()` and `WalletService::debit()` accept
any authenticated user — there is no role check.

**Plan:**
1. Add `PolicyService` check before credit/debit operations:
   - Only `treasurer`, `admin`, and `super_admin` can credit/debit wallets
   - A member can only view their own wallet balance
2. Add tests verifying that a member cannot credit another member's wallet

**File references:**
- `app/Services/WalletService.php` — lines ~30–60 (credit/debit methods)
- `app/Services/PolicyService.php` — wallet tables already in matrix

### 2.3 Rotate JWT Secret

**Problem:** `config/jwt.php` has a hardcoded fallback
`khcww-welfare-change-this-secret-9f3a2b7c1d4e5f60`.

**Plan:**
1. Generate a new random 64-character secret locally
2. Write it to `.env` as `JWT_SECRET` (do NOT use a fallback default)
3. Remove the hardcoded fallback from `config/jwt.php`
4. Add `.env` to `.gitignore` (verify it is already there)

**File references:**
- `config/jwt.php` — the `JWT_SECRET` default
- `.env` — where the secret should be set

### 2.4 (Recommended) Add Rate Limiting

**Problem:** No rate limiting exists on auth or API endpoints.

**Plan:**
1. Add `throttle:login` middleware to `/api/v1/auth/login` and
   `/api/v2/auth/login` routes (5 requests per minute)
2. Add `throttle:api` to all API routes (60 requests per minute)
3. Add tests for rate limit enforcement

**File references:**
- `routes/api.php` — route definitions
- `app/Http/Kernel.php` — middleware aliases

---

## 3. Phase 1 (P1) — Basic Security Centre

### 3.1 Navigation

Add to `resources/views/admin/layout.blade.php` sidebar:

```php
<li class="nav-item">
    <a class="nav-link" href="/admin/security">Security Centre</a>
</li>
```

### 3.2 Route

```php
// routes/web.php, inside admin middleware group
Route::get('/admin/security', [\App\Http\Controllers\Admin\SecurityController::class, 'overview'])
    ->name('admin.security.overview');
```

### 3.3 SecurityController

`app/Http/Controllers/Admin/SecurityController.php`:

```php
class SecurityController extends Controller
{
    public function overview()
    {
        return view('admin.security.overview', [
            'activeSessions' => AuthLog::activeSessions(),
            'recentSecurityEvents' => AuditLog::recentSecurityEvents(),
            'securityScore' => SecurityScore::calculate(),
            'redisStatus' => $this->redisStatus(),
            'queueStatus' => $this->queueStatus(),
            'schedulerStatus' => $this->schedulerStatus(),
        ]);
    }
}
```

### 3.4 Required data sources

| Data | Source |
|------|--------|
| Active sessions | `sessions` table (`sessions` driver for session, or DB session storage) |
| Recent security events | `audit_logs` table (filtered by security event actions) |
| Security score | Calculated from config, .env, and live checks |
| Redis status | `HealthController::redisStatus()` or direct Redis ping |
| Queue status | `queue:work --once` health check or `queue:monitor` |
| Scheduler status | `schedule:list` (verify tasks are defined) |
| Backup status | `BackupAudit` log entries (last backup time, status) |

**Important note on sessions:** The application currently uses
`SESSION_DRIVER=file`. Active sessions cannot be queried from the database
without switching to `database` or `redis` driver. The Security Centre's
session management features require this migration.

### 3.5 Tests

`tests/Feature/SecurityCentreTest.php`:

| Test | Expected |
|------|----------|
| Unauthenticated → /admin/security | 302 → login |
| Member → /admin/security | 403 |
| Admin → /admin/security | 403 |
| Super admin → /admin/security | 200 |

---

## 4. Phase 2 (P2) — Active Security Features

### 4.1 Security Event Logging

Implement `SecurityAudit::log()` pattern (mirroring `BackupAudit`) to write
security events to `audit_logs` table.

**Logging points:**
- `app/Http/Middleware/Authenticate.php` — log `API_AUTH_FAILURE` on failure
- `app/Http/Middleware/AdminAccess.php` — log `ACCESS_DENIED` on failure
- `app/Http/Controllers/Api/LegacyAuthController.php` — log login success/failure
- `app/Http/Controllers/Admin/AdminAuthController.php` — log admin login
- `app/Services/WalletService.php` — log credit/debit (after P0 fixes)
- `app/Services/PaymentService.php` — log payment initiated/verified

### 4.2 Session Management

**Requires:** `SESSION_DRIVER=database` (or `redis`)

**Plan:**
1. Create `sessions` table migration (Laravel standard `create_sessions_table`)
2. Set `SESSION_DRIVER=database`
3. Add `SecurityController::sessions()` to list active sessions
4. Add `SecurityController::revokeSession($id)` to revoke a specific session
5. Add `SecurityController::revokeAllSessions()` to revoke all sessions
   for a user (except current)

**Security events logged:**
- `SESSION_REVOKED` — when a session is revoked
- `SESSIONS_REVOKED_ALL` — when all sessions for a user are revoked

### 4.3 Rate Limiting Dashboard

Show rate-limited IPs and endpoints (from Monolog or future `security_events`
table).

### 4.4 Security Events Page

A `/admin/security/events` page listing all security-related `audit_logs`
entries, filterable by event type, date range, actor, and IP.

---

## 5. Phase 3 (P3) — Advanced Features

### 5.1 Anomaly Detection

- Login attempt correlation (location, timing)
- Unusual wallet activity detection
- Brute-force detection (already partially addressed by rate limiting)

### 5.2 Security Score Automation

A `SecurityScore` service that runs the checklist from
`docs/security/SECURITY_SCORE.md` and stores the result. The score is
calculated on-demand (not on every page load) and cached for 60 seconds.

### 5.3 Security Settings

A settings page where the Super Admin can:
- Enable/disable two-factor authentication requirement for Super Admins
- Set session lifetime
- Configure rate limit thresholds
- Enable/disable account lockout after N failed attempts

### 5.4 Security Health Checks

Scheduled daily checks that write results to `audit_logs`:
- `SCHEDULE_ENABLED` — verify scheduler has cron entry
- `BACKUP_COMPLETED` — verify last backup was within 24h
- `SSL_CERTIFICATE_STATUS` — verify SSL cert validity (if HTTPS is in use)

---

## 6. Implementation Timeline

| Phase | Tasks | Estimated Effort |
|-------|-------|-----------------|
| P0 | Enable PolicyService, add wallet auth, rotate JWT secret, add rate limiting | 2-3 days |
| P1 | Security Centre shell (navigation, route, controller, view, tests) | 1-2 days |
| P2 | Security event logging, session management, events page | 3-4 days |
| P3 | Anomaly detection, score automation, settings, health checks | 4-5 days |
| **Total** | | **10-14 days** |

### Priority ordering within P0

| Priority | Task | Reason |
|----------|------|--------|
| 1 | Rotate JWT secret | Trivially easy, highest impact |
| 2 | Enable PolicyService on REST API | Prevents unauthorized data access |
| 3 | Add Wallet authorization | Prevents unauthorized financial operations |
| 4 | Add rate limiting | Reduces brute-force attack surface |

---

## 7. Security Event Logging Integration Plan (Cross-Reference)

The full security event inventory is defined in
`docs/security/SECURITY_EVENTS.md`. The Security Centre's Security Events
page will query the `audit_logs` table for all security event types defined
there.

The existing `BackupAudit::log()` method already writes to `audit_logs`
for backup/restore events. The new `SecurityAudit::log()` will use the same
table and method signature, ensuring a single audit trail for all
security-relevant operations.

**Note:** The `audit_logs` table is excluded from database dumps in
`config/backup.php`. Security event logs from the Security Centre will be
preserved across restores as a side effect.
