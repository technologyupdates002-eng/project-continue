# Events, Listeners, and Observers Audit — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Summary

The KHWWC Welfare application **does not use Laravel's event/listener system at
all**. There are:

- **0 Events** — No `app/Events/` directory. No `Event` class extends
  `Illuminate\Foundation\Events\Dispatchable` or similar.
- **0 Listeners** — No `app/Listeners/` directory. No listener registrations.
- **0 Observers** — No `app/Observers/` directory. No `Model::observe()` calls.
- **0 EventServiceProvider** — No custom event provider. The default Laravel
  `EventServiceProvider` from the framework is loaded but has no custom
  event/listener mappings.

### Search evidence

Searches across `app/`, `routes/`, and `config/` found:

| Pattern | Matches | Files |
|---------|--------|-------|
| `app/Events` directory | 0 | — |
| `app/Listeners` directory | 0 | — |
| `app/Observers` directory | 0 | — |
| `EventServiceProvider` | 0 | — |
| `event(` calls | 0 | — |
| `Event::dispatch(` calls | 0 | — |
| `Model::observe(` calls | 0 | — |
| `->dispatch(` calls | 1 | `BackupManager.php:62` (queue job, not event) |

The single `->dispatch()` call is `CreateBackupJob::dispatch($backup->id, $ip)` —
this dispatches a **queue job**, not a Laravel event.

---

## 2. The `App\Models\Event` Model is NOT a Laravel Event

There is a model named `App\Models\Event` (`app/Models/Event.php`) and a
corresponding database table `events`. This is a **data model** for welfare
events (funerals, meetings, etc.), NOT a Laravel event. It has:

- `title`, `description`, `event_type` (funeral/meeting), `departed_name`,
  `contribution_amount`, `status`, `created_by`
- No Laravel event dispatching whatsoever.

This naming could cause confusion but represents actual welfare event data.

---

## 3. Model Lifecycle Hooks (Eloquent)

Only **two models** have custom boot/creating logic:

### 3.1 `App\Models\Backup`

```php
// app/Models/Backup.php:60-72
protected static function boot()
{
    parent::boot();
    static::creating(function (self $model) {
        if (empty($model->id)) {
            $model->id = Uuid::v4();
        }
        if (empty($model->status)) {
            $model->status = 'pending';
        }
    });
}
```

- **Trigger:** `creating` (before insert)
- **Purpose:** Auto-generate UUID primary key, set default status to `pending`
- **Side effects:** None (just sets model attributes)
- **Can it run twice?** No — `creating` fires once per model creation
- **Financial impact:** None (backup metadata, not financial)
- **Notifications:** None
- **External API calls:** None
- **Queued:** No

### 3.2 `App\Models\BackupSetting`

```php
// app/Models/BackupSetting.php:28-37
protected static function boot()
{
    parent::boot();
    static::creating(function (self $model) {
        if (empty($model->id)) {
            $model->id = Uuid::v4();
        }
    });
}
```

- **Trigger:** `creating` (before insert)
- **Purpose:** Auto-generate UUID primary key
- **Side effects:** None
- **Can it run twice:** No
- **Financial impact:** None
- **Notifications:** None
- **External API calls:** None
- **Queued:** No

### 3.3 All other models

The remaining ~81 models are flat data-access models with no lifecycle hooks.
They use:
- `$guarded = []` (all columns mass-assignable)
- `$incrementing = false` (UUID primary key)
- `$keyType = 'string'`
- `$timestamps = false` (except `Backup`, `BackupSetting`, and the default `User`)

No model observers, no `booted()` methods, no `saved()`/`updated()` hooks
that modify data.

---

## 4. Events Audit — Full Inventory

Since no Laravel events exist, the only "events" in the system are:

### 4.1 Audit Log Events (via BackupAudit)

Written to the `audit_logs` table by `App\Services\Backup\BackupAudit`:

| Event Action | Trigger | Payload |
|---|---|---|
| `BACKUP_CREATED` | `BackupManager::perform()` after backup creation | backup_id, type, size_bytes, verified status (or error) |
| `BACKUP_VERIFIED` | `BackupAdminController::verify()` | backup_id, status |
| `BACKUP_DOWNLOADED` | `BackupAdminController::download()` | backup_id, type |
| `BACKUP_DELETED` | `BackupAdminController::destroy()` | backup_id, type |
| `RESTORE_STARTED` | `RestoreService::restore()` | backup_id, type, safety_backup_id |
| `RESTORE_COMPLETED` | `RestoreService::restore()` | backup_id, type, safety_backup_id, verification |
| `RESTORE_FAILED` | `RestoreService::restore()` (catch block) | backup_id, type, safety_backup_id, error |
| `SAFETY_BACKUP_CREATED` | `RestoreService::createSafetyBackup()` | backup_id, restored_from |
| `BACKUP_PRUNED` | `BackupAdminController::prune()` | backup_id |
| `BACKUP_SETTINGS_UPDATED` | `BackupAdminController::settings()` | settings array |

**These are NOT Laravel events.** They are direct database inserts via
`AuditLog::insert()`.

### 4.2 Refresh Token Lifecycle (Auth Events)

Manual DB operations, not Laravel events:

| Action | Location | Description |
|--------|----------|-------------|
| Token issuance | `AuthService::issueSession()` | Creates `auth_refresh_tokens` record |
| Token refresh | `LegacyApiController::token()` (refresh grant) | Revokes old token, issues new |
| Token revocation | `LegacyApiController::logout()` | Revokes all refresh tokens for user |

### 4.3 No General Application Events

There are NO Laravel events for:

- ✗ User registration
- ✗ Login success/failure
- ✗ Password change
- ✗ Password reset
- ✗ Role assignment/removal
- ✗ Permission changes
- ✗ Wallet credit/debit
- ✗ Payment initiation/callback
- ✗ Withdrawal creation/approval
- ✗ File upload/download
- ✗ API rate limiting

---

## 5. Listeners Audit

**No Laravel listeners exist.** All side-effects are executed synchronously
within service/controller methods.

### 5.1 Backup Job (Queue)

| Listener | Event | Synchronous/Queued | Purpose |
|----------|-------|-------------------|---------|
| `CreateBackupJob::handle()` | `CreateBackupJob` (queue) | Synchronous under `sync` queue; async under `database`/`redis` | Generate backup file, verify, log to audit_logs |

**Failure behavior:** If `BackupManager::perform()` throws, the job catches
the exception, marks the backup as `failed`, and re-throws a
`RuntimeException`. Under the `sync` queue driver, this surfaces directly to
the caller. Under `database`/`redis`, the job would be marked as failed in
`failed_jobs` and Laravel's retry mechanism would apply.

**Retry behavior:** `CreateBackupJob` implements `ShouldQueue` but does not
set `$tries` or `$timeout` properties, so default Laravel values apply
(1 retry, 60s timeout).

### 5.2 No Other Listeners

No listener processes financial operations asynchronously. All wallet
credit/debit, payment processing, callback handling, and withdrawal logic
executes synchronously within the HTTP request lifecycle inside
`DB::transaction()` blocks.

---

## 6. Observers Audit

**No model observers exist.** There are no files in `app/Observers/` and no
`Model::observe()` registrations anywhere in the codebase.

### 6.1 Financial Model Lifecycle

All financial models (`WalletTransaction`, `Payment`, `Payout`, `Penalty`,
`Contribution`, `BookBalance`, `B2cTransaction`, `Expense`,
`FinancialReport`, etc.) are **unobserved**. Any database changes to these
tables occur directly via:

- `DB::table()->insert/update/delete` (in controllers/services)
- `Model::create/update` (in services like `WalletService`, `PaymentService`)
- The generic REST controller (`LegacyRestController`)
- Raw SQL (in `DatabaseBackupService` for dump/restore)

### 6.2 Critical financial models — observer status

| Model | Observer? | Boot hooks? | Risk |
|-------|-----------|-------------|------|
| `WalletTransaction` | No | No | Medium — no automatic balance validation |
| `Payment` | No | No | Low — insert-only |
| `Payout` | No | No | Medium — no approval workflow enforcement |
| `Contribution` | No | No | Low — insert/update only |
| `BookBalance` | No | No | Medium — no running balance auto-calculation |
| `Penalty` | No | No | Low |
| `PenaltyPaymentRecord` | No | No | Low |
| `DonationPaymentRecord` | No | No | Low |
| `OperationalPaymentRecord` | No | No | Low |
| `Expense` | No | No | Low |
| `B2cTransaction` | No | No | Low |
| `Backup` | Boot only (UUID) | `creating` hook only | None — no financial impact |
| `AuditLog` | No | No | None |
| `SystemLog` | No | No | None |
| `SystemHealth` | No | No | None |

---

## 7. Event/Listener/Observer Regression Protection

### 7.1 What triggers each existing event/listener/observer?

| What | Triggered by | What it changes | Can run twice? | Can it fail? | Money movement? | Notifications? | External APIs? | Queued? |
|------|-------------|-----------------|----------------|--------------|-----------------|----------------|----------------|---------|
| `Backup` creating hook | `Backup::save()` / `Backup::create()` | Sets UUID + status=pending | No (single event) | No | No | No | No | No |
| `BackupSetting` creating hook | `BackupSetting::create()` | Sets UUID | No | No | No | No | No | No |
| `CreateBackupJob` | `BackupManager::create()` → `dispatch()` | Creates backup file, updates Backup record, writes audit log | Job could be queued twice if dispatched twice | Yes (file/disk errors) | No | No | No (uses mysqldump/sqlite3 CLI) | Yes (under database/redis queue) |
| `BackupAudit::log()` | Various backup/restore operations | Inserts a row into `audit_logs` | Can be called multiple times (each is independent) | Low (DB insert) | No | No | No | No (synchronous) |

### 7.2 Financial workflow regression impact

**Since there are no events/listeners/observers for financial operations:**

1. **Contributions** — Created/updated directly via `DB::table()`. No
   automatic wallet credit, no audit log. No observer risk.
2. **Penalties** — Same. No automatic wallet debit, no audit log.
3. **Wallets** — `WalletService::credit()` and `debit()` execute
   synchronously within `DB::transaction()`. No events dispatched. The
   `WalletTransaction` model has no observer.
4. **Payments** — `PaymentService::processCallback()` credits wallets
   synchronously. No events dispatched.
5. **Payouts** — No automatic payment processing. No observer on
   `Payout` model.
6. **Withdrawals** — No automatic wallet debit. No observer on withdrawal
   models.

**Conclusion:** Since no events/listeners/observers exist for financial
operations, there is **zero regression risk** from adding a Security Centre
that introduces new events or observers. Any new events must be carefully
designed to not create duplicate money movement or duplicate notifications.

### 7.3 Recommended approach for Security Events

If security event logging is added (for the Security Centre), it should:

1. **NOT** use Laravel's event/listener system for financial operations —
   financial integrity is currently maintained by synchronous DB transactions.
2. Use a simple `SecurityEvent::log()` method (similar to `BackupAudit::log()`)
   that writes to an existing or new table.
3. Be synchronous for security-relevant actions to ensure no events are lost.
4. Reuse the existing `audit_logs` table where appropriate, or create a
   dedicated `security_events` table that is excluded from backups (like
   `audit_logs`).
