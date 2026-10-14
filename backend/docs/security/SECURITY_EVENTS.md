# Security Events — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Existing Security Event Infrastructure

The application has one existing audit logging mechanism:

### 1.1 `audit_logs` table (schema.sql:65)

```sql
CREATE TABLE audit_logs (
    id CHAR(36) NOT NULL PRIMARY KEY,
    super_admin_id CHAR(36) NOT NULL,
    action VARCHAR(255) NOT NULL,
    target_user_id CHAR(36) DEFAULT NULL,
    target_member_id CHAR(36) DEFAULT NULL,
    details LONGTEXT DEFAULT NULL,
    ip_address VARCHAR(255) DEFAULT NULL,
    created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)
)
```

### 1.2 `BackupAudit` service (app/Services/Backup/BackupAudit.php)

The `BackupAudit::log()` method writes to the `audit_logs` table. It is the
only writer of audit events in the application.

**Events currently logged:**
```
BACKUP_CREATED
BACKUP_VERIFIED
BACKUP_DOWNLOADED
BACKUP_DELETED
RESTORE_STARTED
RESTORE_COMPLETED
RESTORE_FAILED
SAFETY_BACKUP_CREATED
BACKUP_PRUNED
BACKUP_SETTINGS_UPDATED
```

### 1.3 No general security event logging

There is **no** `SecurityEvent` model, service, or table for tracking
general security events. The only audited actions are backup/restore
operations.

### 1.4 `system_logs` table (schema.sql:946)

This table exists but is **never written to** by application code. It was
designed for the legacy PHP backend's error logging. The current Laravel
application logs errors to `storage/logs/laravel.log` (Monolog), not to
this table.

### 1.5 `member_access_logs` table (schema.sql:450)

This table exists but is **never written to** by application code. It was
designed for the legacy PHP backend's member access tracking.

---

## 2. Proposed Security Event Model

The Security Centre should introduce a centralized security event logging
strategy. To avoid duplicating the existing audit infrastructure, two
approaches are possible:

### Option A: Extend `audit_logs` (Recommended)

Reuse the existing `audit_logs` table for security events. The schema
already supports the needed fields:

- `super_admin_id` — the actor (could be `system` for automated events,
  or a user ID for manual events)
- `action` — the event type (e.g., `LOGIN_SUCCESS`, `LOGIN_FAILED`)
- `target_user_id` / `target_member_id` — the target of the action
- `details` — JSON with additional context
- `ip_address` — source IP
- `created_at` — timestamp

A `SecurityAudit::log()` helper (mirroring `BackupAudit::log()`) would
centralize security event writing.

### Option B: New `security_events` table

Create a dedicated table with the same structure or with additional
fields. This has the advantage of separation of concerns but creates a
second audit system (against the stated principle of not duplicating audit
infrastructure).

**Recommendation:** Use **Option A** (extend `audit_logs`). This follows
the existing pattern used by `BackupAudit` and avoids duplicate systems.

---

## 3. Security Event Inventory

### 3.1 Event categories

| Category | Events | Currently logged? |
|----------|--------|------------------|
| Authentication | LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT, TOKEN_REFRESHED | No |
| Password | PASSWORD_CHANGED, PASSWORD_RESET_REQUESTED, PASSWORD_RESET_COMPLETED | No |
| Account | USER_CREATED, USER_DISABLED, USER_ENABLED | No |
| Authorization | ACCESS_DENIED, PERMISSION_DENIED | No |
| Role/Permission | ROLE_CHANGED, PERMISSION_CHANGED | No |
| Session | SESSION_REVOKED, SESSIONS_REVOKED_ALL | No |
| Wallet | WALLET_CREDITED, WALLET_DEBITED | No |
| Payment | PAYMENT_INITIATED, PAYMENT_VERIFIED, PAYMENT_FAILED | No |
| Withdrawal | WITHDRAWAL_REQUESTED, WITHDRAWAL_APPROVED, WITHDRAWAL_REJECTED | No |
| Security settings | SECURITY_SETTING_CHANGED | No |
| Admin action | ADMIN_ACTION, SUPER_ADMIN_ACTION | No |
| Suspicious activity | SUSPICIOUS_ACTIVITY | No |
| API | API_AUTH_FAILURE, RATE_LIMIT_TRIGGERED | No |
| Backup/Restore | BACKUP_CREATED, BACKUP_VERIFIED, ... | Yes (via BackupAudit) |
| Scheduler | SCHEDULE_ENABLED, SCHEDULE_DISABLED, SCHEDULE_RUN | No |
| Queue | QUEUE_RETRY, FAILED_JOB_FORGOTTEN | No |

### 3.2 Detailed event definitions

| Event | Trigger | Payload | Sensitive? |
|-------|---------|---------|-----------|
| `LOGIN_SUCCESS` | `LegacyApiController::token()` or `AdminAuthController::login()` successful | user_id, ip, device/user_agent | No |
| `LOGIN_FAILED` | Failed login attempt | email/phone, ip, reason | No (no password in payload) |
| `LOGOUT` | `LegacyApiController::logout()` or `AdminAuthController::logout()` | user_id, ip | No |
| `TOKEN_REFRESHED` | `LegacyApiController::token()` refresh grant | user_id, ip | No |
| `USER_CREATED` | `LegacyApiController::signup()` | user_id, member_id, ip | No |
| `USER_DISABLED` | User banned or deactivated | target_user_id, admin_id, ip | No |
| `PASSWORD_CHANGED` | `LegacyApiController::changePassword()` | user_id, ip | No (no passwords) |
| `ROLE_CHANGED` | Role assignment via REST API | target_user_id, role, admin_id, ip | No |
| `SESSION_REVOKED` | Future: session revocation from Security Centre | target_user_id, admin_id, ip | No |
| `ACCESS_DENIED` | `AdminAccess` middleware denies access | user_id (if any), ip, path | No |
| `API_AUTH_FAILURE` | `Authenticate` middleware fails | ip, path, reason | No |
| `WALLET_CREDITED` | `WalletService::credit()` | wallet_type, member_id, amount, source, ip | No |
| `WALLET_DEBITED` | `WalletService::debit()` | wallet_type, member_id, amount, reason, ip | No |
| `PAYMENT_INITIATED` | `PaymentService::initiateStkPush()` | payment_type, member_id, amount, ip | No |
| `PAYMENT_VERIFIED` | `PaymentService::processCallback()` | checkout_request_id, status, ip | No |
| `WITHDRAWAL_APPROVED` | Future withdrawal approval | withdrawal_id, approver_id, ip | No |
| `SUSPICIOUS_ACTIVITY` | Detected anomaly | type, user_id, ip, details | No |
| `RATE_LIMIT_TRIGGERED` | Future rate limiting | ip, path, limit | No |
| `SECURITY_SETTING_CHANGED` | Security configuration change | setting_name, admin_id, ip | No |
| `SUPER_ADMIN_ACTION` | High-risk admin action | action, admin_id, target, ip, details | No |

### 3.3 Event payload design

All event payloads must:
- Be JSON-encoded strings (matching the existing `audit_logs.details` column)
- **Never** contain passwords, tokens, PINs, or other secrets
- **Never** contain full JWT tokens
- Include only non-sensitive identifiers and metadata

Example payload for `LOGIN_FAILED`:
```json
{
    "email": "user@example.com",
    "reason": "invalid_credentials"
}
```

Example payload for `WALLET_DEBITED`:
```json
{
    "wallet_type": "penalty",
    "amount": 500.00,
    "reason": "withdrawal"
}
```

---

## 4. Security Event Logging Strategy

### 4.1 Logging points

Security events should be logged at:

1. **Authentication middleware** (`Authenticate.php`) — `API_AUTH_FAILURE`
2. **Admin access middleware** (`AdminAccess.php`) — `ACCESS_DENIED` when
   non-super-admin attempts admin access
3. **Auth controller** (`LegacyApiController`) — `LOGIN_SUCCESS`,
   `LOGIN_FAILED`, `LOGOUT`, `TOKEN_REFRESHED`, `USER_CREATED`,
   `PASSWORD_CHANGED`
4. **Admin auth controller** (`AdminAuthController`) — `LOGIN_SUCCESS`,
   `LOGIN_FAILED`, `LOGOUT`
5. **Wallet service** (`WalletService`) — `WALLET_CREDITED`, `WALLET_DEBITED`
6. **Payment service** (`PaymentService`) — `PAYMENT_INITIATED`,
   `PAYMENT_VERIFIED`, `PAYMENT_FAILED`
7. **Policy service** (`PolicyService`) — `PERMISSION_DENIED`
8. **Security centre** (future) — `SUPER_ADMIN_ACTION`,
   `SESSION_REVOKED`, `SECURITY_SETTING_CHANGED`,
   `SCHEDULE_ENABLED`, `SCHEDULE_DISABLED`, `SCHEDULE_RUN`,
   `QUEUE_RETRY`, `FAILED_JOB_FORGOTTEN`

### 4.2 Implementation approach

A `SecurityAudit` service (mirroring `BackupAudit`) would be created:

```php
class SecurityAudit
{
    public const LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    // ... etc

    public static function log(string $action, ?string $userId, array $details = [],
        ?string $ipAddress = null, ?string $memberId = null): void
    {
        AuditLog::insert([
            'id' => Uuid::v4(),
            'super_admin_id' => $userId,
            'action' => $action,
            'target_user_id' => $details['target_user_id'] ?? null,
            'target_member_id' => $details['target_member_id'] ?? null,
            'details' => json_encode(array_diff_key($details, ...)),
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }
}
```

**Important:** The `audit_logs` table is excluded from database dumps (see
`config/backup.php` → `'ignore_tables' => ['backups', 'backup_settings',
'audit_logs']`). The new security events writing to this table will be
preserved across restores. This must be documented.

### 4.3 Event volume considerations

| Event | Estimated daily volume | Retention recommendation |
|-------|----------------------|--------------------------|
| LOGIN_SUCCESS | ~100-1000 | 90 days |
| LOGIN_FAILED | ~0-100 (if brute-force) | 30 days |
| API_AUTH_FAILURE | ~0-100 | 30 days |
| WALLET_CREDITED/DEBITED | ~10-100 | 365 days (financial) |
| PAYMENT_INITIATED/VERIFIED | ~10-100 | 365 days (financial) |
| ACCESS_DENIED | ~0-10 | 30 days |
| SUPER_ADMIN_ACTION | ~1-10 | 365 days |

---

## 5. Integration with Security Centre

The Security Centre's **Security Events** page should display:

```
Time | Event | Actor | Target | IP | Details
```

With filtering by:
- Event type (dropdown of all security event actions)
- Date range
- Actor (Super Admin ID)
- IP address

### 5.1 Existing audit logs page

The current `/admin/audit-logs` page already lists `audit_logs` entries with
filtering by action and backup-only toggle. The Security Centre's Security
Events page should extend this with:
- All security event types (not just backup events)
- Better formatting of details JSON
- Event-type-specific columns

### 5.2 Login activity page

The Security Centre's Login Activity page should query `audit_logs` for
`LOGIN_SUCCESS` and `LOGIN_FAILED` events, showing:
```
Time | User | Status | IP | Method
```

### 5.3 Admin activity page

The Security Centre's Admin Activity page should query `audit_logs` for
high-risk actions:
- `ROLE_CHANGED`, `PERMISSION_CHANGED`
- `WALLET_CREDITED`, `WALLET_DEBITED`
- `WITHDRAWAL_APPROVED`, `WITHDRAWAL_REJECTED`
- `SECURITY_SETTING_CHANGED`
- `BACKUP_CREATED`, `RESTORE_COMPLETED`
- `USER_DISABLED`
