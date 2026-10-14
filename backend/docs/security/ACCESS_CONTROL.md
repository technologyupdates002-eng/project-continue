# Access Control — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Roles

Roles are stored in the `user_roles` table and are embedded in the JWT
payload at login time. The `AuthService::rolesFor()` method resolves the
active role set for a user, applying a hierarchy where `super_admin`
implicitly grants `admin`.

### Role hierarchy

```
super_admin
  └── admin (implicitly granted)
  └── all other roles (explicitly assigned)
```

If a user has no active roles, they are treated as `member`.

### Defined roles

| Role | Description | Staff set? | Can read all (staff tables)? | Can write financial tables? |
|------|-------------|-----------|------------------------------|---------------------------|
| `member` | Regular member | No | No (own rows only) | No |
| `admin` | Administrator | Yes | Yes | Yes |
| `super_admin` | Supreme administrator | Yes | Yes (all tables) | Yes (all tables) |
| `treasurer` | Treasury officer | Yes | Yes | Yes (wallet/financial tables) |
| `chairperson` | Chairperson | Yes | Yes | Limited (memos, meetings, minutes) |
| `vice_chairperson` | Deputy chair | Yes | Yes | Limited |
| `secretary` | Secretary | Yes | Yes | Limited (news, events, memos) |
| `vice_secretary` | Deputy secretary | Yes | Yes | Limited (news, events) |
| `patron` | Patron | Yes | Yes | None |

Staff set is defined in `AuthService::STAFF_SET` and `PolicyService::STAFF_SET`:
```php
['admin', 'super_admin', 'treasurer', 'chairperson', 'vice_chairperson',
 'secretary', 'vice_secretary', 'patron']
```

### Role assignment

Roles are managed in the `user_roles` table:

```sql
CREATE TABLE user_roles (
    id CHAR(36) NOT NULL PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    role VARCHAR(40) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_user_roles (user_id, role)
)
```

Roles are resolved at login/issue time and embedded in the JWT. A role
change during a session will NOT be reflected until the next login/token
refresh.

---

## 2. Super Admin Authorization

### 2.1 Enforcement layers

| Layer | Mechanism | File | Enforcement |
|-------|-----------|------|-------------|
| Route | `admin` middleware | `routes/web.php` | All `/admin/*` routes use `admin` middleware |
| Middleware | `AdminAccess` | `app/Http/Middleware/AdminAccess.php` | Re-validates session JWT, enforces `super_admin` role |
| Controller | N/A (relies on middleware) | All admin controllers | Middleware protects all actions |
| Model/Policy | N/A | — | No Laravel Policies; relies on middleware |

### 2.2 AdminAccess middleware flow

```php
// app/Http/Middleware/AdminAccess.php
1. Read `admin_jwt` from session
2. If empty → redirect to login (401 for JSON requests)
3. Decode JWT via AuthService::identity()
4. If decode fails → clear session, redirect to login
5. Check AuthService::isSuperAdmin(roles)
6. If not super_admin → abort(403)
7. Set `identity` and `admin_user_id` on request attributes
8. Allow request to proceed
```

### 2.3 Authorization test matrix

Tests exist in `tests/Feature/BackupRestoreTest.php`:

| User | Can access /admin/api/backups (JSON)? | Can access /admin/backups (page)? |
|------|--------------------------------------|----------------------------------|
| Unauthenticated | 401 | 302 redirect to /admin/login |
| Authenticated member | 403 | 403 |
| Authenticated admin | 403 | 403 |
| Authenticated super_admin | 200 | 200 |

**Note:** These tests only verify backup endpoints. Authorization for all
other admin endpoints relies on the same middleware, but no explicit tests
verify non-super-admin access denial for other admin pages.

---

## 3. API Authorization

### 3.1 Auth middleware

The `auth.jwt` middleware (`Authenticate` class) is applied to all API
endpoints that require authentication:

```php
// routes/api.php (excerpts)
Route::post('/auth/v1/logout', ...)->middleware('auth.jwt');
Route::get('/auth/v1/user', ...)->middleware('auth.jwt');
Route::match(['get','post','patch','delete','put'], '/rest/v1/{table}', ...)
    ->middleware('auth.jwt');
// ... etc
```

### 3.2 Row-level security (PolicyService)

The `PolicyService` enforces row-level authorization for the generic REST
API. It is consulted in the `LegacyRestController` flow.

**Important finding:** The `PolicyService` is only consulted via the
`PolicyService::assertWrite()` method, which is called... actually, looking
at the code, `PolicyService::assertWrite()` is NOT called in
`LegacyRestController`. Let me re-examine.

Upon re-reading `LegacyRestController.php`, the `PolicyService` is NOT
actually called anywhere in the controller. The `PolicyService` class
exists with its full matrix, but the REST controller (`LegacyRestController`)
does not import or call any `PolicyService` methods. The authorization
is only at the JWT level (`auth.jwt` middleware).

This means:
- **Any authenticated user** can read/write **any table** via the generic
  REST API (`/api/rest/v1/{table}` and `/api/{table}`)
- The `PolicyService` matrix is defined but **not enforced** for the
  generic REST API
- Only the `admin` middleware enforces `super_admin` for the admin area
- Wallet operations have their own authorization in `PaymentService`
  (but NOT in `WalletService`)

This is a **critical security gap**: the PolicyService matrix is defined but
not wired into the REST controller.

### 3.3 PolicyService table matrix summary

The `PolicyService::TABLES` constant defines per-table read/write rules.
Key financial tables:

| Table | Read | Write |
|-------|------|-------|
| `members` | `*` (all authenticated) | admin, super_admin |
| `user_roles` | `*` | admin, super_admin |
| `wallet_transactions` | STAFF | admin, super_admin, treasurer |
| `contributions` | STAFF | admin, super_admin, treasurer |
| `penalties` | STAFF | admin, super_admin, treasurer |
| `payments` | STAFF | admin, super_admin, treasurer |
| `payouts` | STAFF | admin, super_admin, treasurer |
| `bank_transactions` | STAFF | admin, super_admin, treasurer |
| `donation_wallet` | `*` | admin, super_admin, treasurer |
| `penalty_wallet` | `*` | admin, super_admin, treasurer |
| `operational_wallet` | `*` | admin, super_admin, treasurer |
| `audit_logs` | super_admin | super_admin, admin |
| `system_logs` | super_admin | super_admin, admin |
| `system_health` | super_admin | super_admin |
| `coop_tokens` | super_admin | super_admin |
| `password_resets` | super_admin | super_admin |

---

## 4. Super Admin Area Authorization

### 4.1 Current admin navigation

```
KHWWC — Super Admin
├── Management
│   ├── Dashboard (/admin)
│   ├── Users (/admin/users)
│   ├── Groups (/admin/groups)
│   ├── Transactions (/admin/transactions)
│   ├── Reports (/admin/reports)
│   └── Audit Logs (/admin/audit-logs)
└── System
    ├── Backups & Restore (/admin/backups)
    └── Settings (/admin/settings)
```

All routes are under the `admin` middleware group:
```php
// routes/web.php:26
Route::middleware(['admin'])->group(function () {
    // All admin routes
});
```

### 4.2 Proposed Security Centre navigation

```
Super Admin
├── Dashboard
├── Users
├── Groups
├── Transactions
├── Reports
├── Security Centre          ← NEW
│   ├── Security Overview
│   ├── Active Sessions
│   ├── Login Activity
│   ├── Security Events
│   ├── Audit Logs
│   ├── Redis Health
│   ├── Queue Health
│   ├── Scheduler Health
│   └── System Health
├── Backups & Restore
└── Settings
```

---

## 5. Policy Enforcement Summary

| Area | Enforcement | Gap |
|------|------------|-----|
| Admin web area | `admin` middleware (JWT + super_admin) | ✓ Fully enforced |
| API authentication | `auth.jwt` middleware (JWT validation) | ✓ Fully enforced |
| API row-level security | `PolicyService` (defined but NOT called by REST controller) | ⚠ **CRITICAL GAP** |
| Wallet authorization | `PaymentService` (STAFF or self for payments) / `WalletService` (no auth check) | ⚠ **Wallet debit/credit not role-checked** |
| File upload | `auth.jwt` + path traversal protection | ○ No file type validation |
| STK callback | Public (no auth) | ○ No IP allowlist or signature verification |

---

## 6. Test Matrix for Security Centre

The Security Centre must be tested with all actual roles:

| Role | Access to Security Centre | Expected result |
|------|--------------------------|-----------------|
| Unauthenticated | GET /admin/security | 302 redirect to /admin/login |
| member | GET /admin/security | 403 |
| admin | GET /admin/security | 403 |
| treasurer | GET /admin/security | 403 |
| chairperson | GET /admin/security | 403 |
| secretary | GET /admin/security | 403 |
| super_admin | GET /admin/security | 200 |

All tests use session-based JWT (same pattern as `BackupRestoreTest`).
