# Security Architecture — KHWWC Welfare

**Date:** 2026-08-25
**Auditor:** Kilo (automated audit)

---

## 1. Authentication Security

### 1.1 Current authentication system

| Aspect | Current state | Security assessment |
|--------|--------------|-------------------|
| **Protocol** | JWT (HS256, firebase/php-jwt) | ● Standard, symmetric signing |
| **Access token TTL** | 3600s (1 hour) | ○ Reasonable for access tokens |
| **Refresh token TTL** | 2592000s (30 days) | ○ Long but acceptable; rotating |
| **Password hashing** | bcrypt, 12 rounds | ● Strong |
| **JWT secret source** | `JWT_SECRET` env var with fallback default | ⚠ The fallback default
`khcww-welfare-change-this-secret-9f3a2b7c1d4e5f60` is hardcoded in `config/jwt.php` |
| **Token storage (web)** | Session (`admin_jwt`) | ○ Session regenerated on login |
| **Token storage (API)** | Bearer header (stateless) | ○ Standard |
| **Session fixation** | `AdminAuthController::login()` calls `$request->session()->regenerate()` | ● Mitigated |
| **Session cookie** | `khwwc_session`, `http_only=true`, `same_site=lax` | ○ httpOnly set, SameSite=Lax |
| **Session lifetime** | 120 minutes | ○ Standard |

### 1.2 Missing authentication security features

| Feature | Status | Notes |
|---------|--------|-------|
| Login attempt rate limiting | ✗ Missing | No throttling on `/api/auth/v1/token` or `/admin/login` |
| Account lockout | ✗ Missing | No failed attempt tracking; `auth_users.is_banned` is admin-managed only |
| Brute-force protection | ✗ Missing | No IP-based rate limiting or lockout |
| MFA / TOTP | ✗ Missing | `dashboard_security` table exists but unused |
| WebAuthn | ✗ Missing | `webauthn_credential_id` column exists but unused |
| Password reset flow | ✗ Partial | `password_resets` table exists, but no controller/service implements the flow |
| Session expiration enforcement | ○ Partial | JWT has `exp` claim; session cookie has 120min lifetime |
| Concurrent session limit | ✗ Missing | No limit on simultaneous sessions |
| Token revocation (access) | ✗ Missing | JWT access tokens cannot be revoked; only refresh tokens are revocable |
| Password strength policy | ⚠ Weak | Only enforces >= 6 characters (`AuthService`/`LegacyApiController`) |
| Password history | ✗ Missing | No previous-password checking |

### 1.3 Login endpoints

| Endpoint | Middleware | Rate limited? | Notes |
|----------|-----------|--------------|-------|
| `POST /api/auth/v1/token` | none | No | Password grant + refresh grant |
| `POST /admin/login` | `admin.guest` | No | Web login, Super Admin only |
| `POST /api/auth/v1/signup` | none | No | Open registration if `registration_config.active=1` |

---

## 2. Authorization Security

### 2.1 Role-based access control (RBAC)

| Role | Description | Super Admin UI Access | Policy read/write |
|------|-------------|----------------------|-------------------|
| `member` | Regular member | ✗ Denied | Read: own rows; Write: own rows only |
| `admin` | Administrator | ✗ Denied (needs super_admin) | Varies by table |
| `super_admin` | Supreme administrator | ✓ Allowed | Full access |
| `treasurer` | Treasury officer | ✗ Denied | Financial read/write |
| `chairperson` | Chairperson | ✗ Denied | Limited write access |
| `vice_chairperson` | Deputy chair | ✗ Denied | Limited write access |
| `secretary` | Secretary | ✗ Denied | News/events/memos write |
| `vice_secretary` | Deputy secretary | ✗ Denied | News/events write |
| `patron` | Patron | ✗ Denied | Staff only (limited) |

### 2.2 Authorization layers

| Layer | Mechanism | Enforcement |
|-------|-----------|-------------|
| Admin area | `AdminAccess` middleware | ✓ Server-side (JWT re-validation + super_admin role) |
| API | `Authenticate` middleware (`auth.jwt`) | ✓ Server-side (JWT validation) |
| Row-level | `PolicyService` | ✓ Per-table read/write matrix |
| Resource | N/A | — (no Laravel Policies used) |

### 2.3 Authorization security assessment

| Check | Status |
|-------|--------|
| Super Admin UI server-side enforced | ● Yes — `AdminAccess` middleware |
| Menu hiding is the only protection | ✗ No — server-side enforcement confirmed |
| Role hierarchy (super_admin implies admin) | ○ Yes — in `AuthService::rolesFor()` |
| Fine-grained permissions per table | ● Yes — `PolicyService::TABLES` |
| Owner-scoped access | ● Yes — `own` column + `ownWrite` flag |
| Public insert for registration | ○ Yes — `publicInsert` for `member_registrations`, `registration_fees` |

---

## 3. API Security

### 3.1 Authentication

| Endpoint group | Auth required? | Mechanism |
|---------------|---------------|-----------|
| `/api/health`, `/api/system/info` | ✗ No | Public health checks |
| `/api/auth/v1/token` | ✗ No | Password grant (and refresh grant) |
| `/api/auth/v1/signup` | ✗ No (if open registration) | No auth, public insert |
| `/api/auth/v1/logout` | ✓ Yes | `auth.jwt` |
| `/api/auth/v1/user` | ✓ Yes | `auth.jwt` |
| `/api/auth/v1/password` | ✓ Yes | `auth.jwt` |
| `/api/rest/v1/*` | ✓ Yes | `auth.jwt` |
| `/api/storage/v1/object/{bucket}/{path?}` (GET) | ✗ No | Public download (signed URL for auth) |
| `/api/storage/v1/object/{bucket}/{path?}` (POST/GET signed/DELETE) | ✓ Yes | `auth.jwt` |
| `/api/me` | ✓ Yes | `auth.jwt` |
| `/api/payments/*` | Mixed | `auth.jwt` except `stk-callback` |
| `/api/notifications/*` | ✓ Yes | `auth.jwt` |
| `/api/wallets/*` | ✓ Yes | `auth.jwt` |
| `/api/{table}` | ✓ Yes | `auth.jwt` |

### 3.2 API key exposure

The STK callback endpoint (`POST /api/payments/stk-callback`) is **unauthenticated**.
This is correct for M-Pesa callbacks but means anyone can POST fake callbacks.
The callback processing (`PaymentService::processCallback()`) validates that
the `CheckoutRequestID` matches a pending `penalty_payment_records` or
`donation_payment_records` entry, which prevents arbitrary wallet credits.
However, there is no IP allowlisting or signature verification for M-Pesa
callbacks.

### 3.3 API security assessment

| Check | Status | Notes |
|-------|--------|-------|
| All non-public endpoints require JWT | ● Yes | `auth.jwt` middleware |
| JWT validated on every request | ● Yes | Stateless, no session server-side |
| STK callback unauthenticated | ○ By design | But no IP allowlist or signature verification |
| SQL injection | ● Protected | Uses `DB::table()` with parameter binding |
| Mass assignment | ● Protected | `PolicyService` enforces per-table column access;
  `LegacyRestController` filters to actual table columns via `Schema::getColumnListing()` |
| Input validation | ● Some | `PaymentController`, `WalletController`, `NotificationController`
  have form request validation; `LegacyRestController` has minimal validation |
| Rate limiting | ✗ Missing | No `ThrottleRequests` middleware on any API route |
| CORS | ○ Configured | Specific localhost origins, credentials allowed, no wildcard |
| API tokens | N/A | JWT-based, not persistent API tokens |
| Response serialization | ● Yes | `ApiResourceSerializer` handles JSON/bool/datetime coercion |

---

## 4. Application Security

### 4.1 CSRF

| Check | Status |
|-------|--------|
| Web routes (admin area) | ● Laravel default CSRF token (`csrf_token()` in layout, `VerifyCsrfToken` middleware) |
| API routes | ✓ Not needed (JWT bearer auth, no cookies) |
| AJAX (admin.js) | ● Sends `X-CSRF-TOKEN` header |
| Login form | ● Has CSRF token |

### 4.2 XSS Protection

| Check | Status |
|-------|--------|
| Admin UI (Blade) | ● `{{ }}` auto-escapes; `KH.esc()` in JS |
| API responses | ● JSON responses (no HTML rendering) |
| File upload names | ● `safePath()` sanitizes; path traversal blocked |
| Error messages | ○ Generic error messages in most cases |

### 4.3 SQL Injection

| Check | Status |
|-------|--------|
| Query builder | ● Parameter binding via `DB::table()` |
| REST controller | ● Column whitelisting via `Schema::getColumnListing()` |
| Raw SQL | ○ `PostgrestQueryBuilder::apply()` uses `whereRaw` with bound parameters |
| Backup dump | ○ Uses `mysqldump` CLI (not injectable via PHP) |

### 4.4 Mass Assignment

| Check | Status |
|-------|--------|
| Models | ○ `$guarded = []` on most models (all columns mass-assignable) |
| REST controller | ● `LegacyRestController::create()` filters to actual table columns |
| PolicyService | ● Enforces write authorization per-table |

### 4.5 File Upload Security

| Check | Status |
|-------|--------|
| Path traversal | ● `LegacyStorageController::safePath()` blocks `..` and sanitizes |
| Storage location | ● Under `storage/app/private/` (not public) |
| File type restriction | ✗ No MIME type validation |
| File size limit | ○ `MAX_UPLOAD_MB=15` in .env (not enforced in code) |
| Public access | ○ Files served via `/storage/{bucket}/{path}` (unauthenticated GET) |

### 4.6 Command Execution Risks

| Check | Status |
|-------|--------|
| `DatabaseBackupService` | ○ Uses `shell_exec('command -v ...')` and `proc_open()` with `escapeshellarg()` |
| `which()` method | ○ Uses `shell_exec` with `escapeshellarg` |
| `run()` method | ○ Uses `proc_open` with `escapeshellarg` on all command parts |
| Input to shell | ○ Database config values (host, port, user, password) written to
  `--defaults-extra-file` with 0600 permissions, not passed as CLI args |

### 4.7 Debug Mode

| Check | Status |
|-------|--------|
| `APP_DEBUG` (local) | `true` — expected for local |
| `APP_DEBUG` (production) | Must be `false` — not checked |
| Error exposure | ○ `/api/health` returns DB error message; `/api/system/info`
  returns Redis error message |

### 4.8 Error Handling

| Check | Status |
|-------|--------|
| HTTP exceptions | ● Custom `HttpException` with error code + message |
| JWT exceptions | ● Custom `JwtException` rendered as JSON |
| General exceptions | ○ Laravel default handler |
| Error response format | ✓ Consistent: `{"error": {"code": "...", "message": "..."}}` |

---

## 5. Infrastructure Security

### 5.1 PHP configuration

| Setting | Value |
|---------|-------|
| PHP version | 8.4.24 |
| bcmath | Enabled |
| openssl | Enabled |
| pdo_mysql | Enabled |
| zip | Enabled |
| redis extension | **NOT installed** (using `predis/predis` library) |
| display_errors | Inherited from Laravel `APP_DEBUG` |

### 5.2 Laravel configuration

| Setting | Value | Security |
|---------|-------|----------|
| `APP_KEY` | Set (base64) | ● Required for encryption |
| `APP_DEBUG` | true (local) | ⚠ Must be false in production |
| `APP_URL` | http://localhost:8000 | ⚠ HTTP, not HTTPS |
| `SESSION_SECURE_COOKIE` | Not set (defaults to false) | ⚠ Cookies not marked secure |
| `SESSION_HTTP_ONLY` | true (configured in session.php) | ● |
| `SESSION_SAME_SITE` | lax | ○ |
| `BCRYPT_ROUNDS` | 12 | ● |

### 5.3 Filesystem permissions

The `backups` disk is configured with `visibility: private`.
Backup files live at `storage/app/backups/` and are never web-served.
The download route (`BackupAdminController::download()`) streams files
through Laravel, never exposing the path directly.

### 5.4 Environment secrets

The `.env` file contains:
- `APP_KEY` (base64) — application encryption key
- `JWT_SECRET` — JWT signing secret
- `DB_PASSWORD` (empty) — database password
- `COOP_CLIENT_SECRET` — Co-operative Bank API secret
- `REDIS_PASSWORD` (null) — Redis password

**Security concern:** The `.env` file is readable (`chmod 600`) and is in
`.gitignore`. However, the JWT secret has a **hardcoded fallback default**
in `config/jwt.php`.

### 5.5 Database credentials

- `DB_USERNAME=root` — using root account (security concern for production)
- `DB_PASSWORD=` (empty) — no password (security concern for production)
- `DB_HOST=127.0.0.1` — local connection

### 5.6 Redis security

- `REDIS_PASSWORD=null` — no password configured
- Redis is **not running** — no risk of exposure
- Redis is **not used** — no exposure risk

### 5.7 Web server configuration

- Default Laravel `public/.htaccess` present
- `public/index.php` is the entry point
- `APP_URL=http://localhost:8000` (HTTP, not HTTPS) — SSL/HTTPS readiness
  should be configured in production via reverse proxy

### 5.8 CORS configuration

```php
// config/cors.php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost', 'http://localhost/khwwc', ...],  // from env
'allowed_headers' => ['*'],
'exposed_headers' => ['Content-Range', 'X-Total-Count'],
'max_age' => 86400,
'supports_credentials' => true,
```

**No wildcard (`*`) origins** — specific localhost origins are configured.
This is good. In production, `CORS_ALLOWED_ORIGINS` should be set to the
actual domain(s).

---

## 6. Financial Security

### 6.1 Transaction authorization

| Operation | Authorization | Notes |
|-----------|--------------|-------|
| Wallet credit | `auth.jwt` | Any authenticated user can credit any wallet |
| Wallet debit | `auth.jwt` | Any authenticated user can debit any wallet |
| Payment (STK push) | `auth.jwt` + `PaymentService::assertCanWrite()` | Staff or self only |
| Payment (callback) | None (public) | Validates `CheckoutRequestID` match |
| Payment (bank sync) | `auth.jwt` + `PaymentService::assertIsStaff()` | Staff only |
| Payment (B2C transfer) | `auth.jwt` + `PaymentService::assertIsStaff()` | Staff only |
| Payment (status) | `auth.jwt` | Any authenticated user |

### 6.2 Wallet authorization

**CRITICAL FINDING:** The `WalletController` credit and debit endpoints
require only JWT authentication (`auth.jwt` middleware). **There is no
role-based authorization check** — any authenticated user (including a
regular member) can credit or debit any wallet, including other members'
wallets. The `PolicyService` is NOT consulted for wallet operations.

The `member_id` parameter in the credit/debit request is not validated
against the authenticated user's identity. This means any authenticated
user could call:
- `POST /api/wallets/penalty/debit` for any `member_id`
- `POST /api/wallets/donation/credit` for any `member_id`

This is a **significant financial security gap**.

### 6.3 Ledger integrity

| Check | Status |
|-------|--------|
| Credit/debit in DB transactions | ● Yes — `WalletService::credit/debit` use `DB::transaction()` |
| Running balance tracked | ● Yes — `running_balance` column in `wallet_transactions` |
| Balance validation before debit | ● Yes — `WalletService::debit()` checks for sufficient funds |
| Idempotency on payments | ○ Partial — STK callback checks `status === 'pending'` before updating |
| Duplicate payment protection | ○ Partial — `mpesa_receipt` not checked for uniqueness in code |

### 6.4 Withdrawals

The withdrawal models exist (`penalty_withdrawals`, `donation_withdrawals`,
`operational_withdrawals`) with multi-signatory approval tables, but there
are **no controllers or services** for withdrawal management. Withdrawals
are only accessible via the generic REST API (`PolicyService` allows
`treasurer` and `admin` write).

### 6.5 Disbursements

The `payouts` table exists for welfare payouts, but there are **no
controllers or services** for payout management. Payouts are only accessible
via the generic REST API.

### 6.6 Payment callbacks

| Check | Status |
|-------|--------|
| Callback unauthenticated | ○ By design (M-Pesa) |
| CheckoutRequestID validation | ● Checks existing pending records |
| Duplicate callback protection | ○ Status check prevents re-processing (`status === 'pending'`) |
| Idempotency key | ✗ Not implemented |
| IP allowlisting | ✗ Not implemented |

### 6.7 Audit trails

| Check | Status |
|-------|--------|
| Backup/restore actions | ● Fully audited via `BackupAudit` → `audit_logs` |
| Financial actions | ✗ Not audited (no wallet transaction audit, no payment audit) |
| User management | ✗ Not audited |
| Role changes | ✗ Not audited |
| Configuration changes | ✗ Not audited (except backup settings) |

---

## 7. Security Assessment Summary

| Category | Rating | Notes |
|----------|--------|-------|
| Authentication | ○ Moderate | JWT HS256, bcrypt 12 rounds, but no MFA, no rate limiting |
| Authorization | ● Strong | Multi-layer (middleware + PolicyService row-level security) |
| API security | ○ Moderate | JWT auth, but no rate limiting, no CSRF needed |
| Application security | ○ Moderate | CSRF/XSS/SQLi protected, but `APP_DEBUG=true`, weak password policy |
| Infrastructure | ⚠ Weak | `APP_DEBUG=true`, HTTP (not HTTPS), root DB user, empty DB password |
| Financial security | ⚠ Weak | **Wallet credit/debit not role-authorized** — any authenticated user can move money |
| Session management | ○ Moderate | Session regeneration on login, but no server-side session tracking |
| Secret management | ○ Moderate | `.env` is gitignored, but JWT secret has hardcoded fallback |

### Critical security gaps

1. **No Super Admin-only wallet authorization** — any authenticated user can
   credit/debit wallets (CRITICAL for a financial application)
2. **JWT secret has hardcoded fallback default** in `config/jwt.php`
3. **APP_DEBUG=true** in the environment (acceptable for local, but a risk
   if deployed to production)
4. **No rate limiting** on authentication or any API endpoints
5. **No login attempt tracking** or brute-force protection
6. **No security event logging** outside of backup operations
7. **No MFA/WebAuthn** implementation (infrastructure partially exists)
8. **No password reset flow** (table exists but no controller/service)
