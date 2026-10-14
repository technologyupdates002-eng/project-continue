# KHWWC Super Admin Frontend/API Matrix

## Scope

This inventory covers the Super Admin surface in the app frontend (`frontend/src/App.tsx` and `frontend/src/components/layout/SuperAdminLayout.tsx`) plus the API calls made by those pages. It follows the same evidence-first approach used for the Treasurer and Admin matrices: this is a source inventory of frontend route wiring and request construction, not a runtime authorization or security-signoff.

## Evidence sources

- Frontend route tree: `frontend/src/App.tsx`
- Super Admin shell/nav: `frontend/src/components/layout/SuperAdminLayout.tsx`
- Super Admin page files in `frontend/src/pages/super-admin/*`
- Laravel route registry: `khwwc/routes/api.php`
- Backend controllers referenced by those routes: `SuperAdminStatsController`, `PresenceController`, `Admin\SuperAdminController`, `RpcController`, etc.
- Production website/public domain was intentionally excluded from this app audit.

## Super Admin route inventory

| Frontend URL | Rendered component | Purpose | Source status |
|---|---|---|---|
| `/super-admin` | `SuperAdminDashboard` | System overview, member totals, financial summary, health status | Source-confirmed |
| `/super-admin/members` | `Members` | Member roster/search, admin/member operations | Reuses admin member module |
| `/super-admin/member/:memberId` | `SuperAdminMemberDetail` | Member profile + admin actions for password reset, message access | Source-confirmed |
| `/super-admin/contributions` | `Contributions` | Contribution management | Reuses admin contribution module |
| `/super-admin/import` | `ExcelImport` | Spreadsheet import | Reuses admin import module |
| `/super-admin/import-history` (also `/admin/import-history`) | `BankStatementImportHistory` | Read-only history of statement rows, member creation/matching, and current dashboard contribution status | Source-confirmed |
| `/super-admin/beneficiary-import` | `BeneficiaryImport` | Beneficiary import | Reuses admin import module |
| `/super-admin/beneficiaries` | `Beneficiaries` | Beneficiary management | Reuses admin beneficiary module |
| `/super-admin/payments` | `Payments` | Payment records / reconciliation | Reuses admin payment module |
| `/super-admin/unmatched` | `UnmatchedPayments` | Unmatched payment handling | Reuses admin module |
| `/super-admin/sms` | `BulkSms` | SMS campaign sending and history | Reuses admin module |
| `/super-admin/events` | `AdminEvents` | Event management | Reuses admin module |
| `/super-admin/documents` | `AdminDocuments` | Document review and management | Reuses admin module |
| `/super-admin/news` | `AdminNews` | News management | Reuses admin module |
| `/super-admin/minutes` | `AdminMeetingMinutes` | Meeting minutes | Reuses admin module |
| `/super-admin/beneficiary-requests` | `BeneficiaryRequests` | Beneficiary request review | Reuses admin module |
| `/super-admin/defaulters` | `Defaulters` | Overdue/defaulting view | Reuses admin module |
| `/super-admin/notifications` | `AdminNotifications` | Admin notification inbox | Reuses admin module |
| `/super-admin/settings` | `AdminSettings` | General system settings | Reuses admin module |
| `/super-admin/banking/diagnostics` | `BankDiagnostics` | Bank connectivity and diagnostics | Reuses admin module |
| `/super-admin/signatures` | `OfficeSignatures` | Signature management | Reuses admin module |
| `/super-admin/penalty-payments` | `VerifyPenaltyPayments` | Penalty payment verification | Reuses admin module |
| `/super-admin/penalty-wallet` | `PenaltyWallet` | Penalty wallet operations | Reuses admin module |
| `/super-admin/donations` | `DonationWallet` | Donation wallet operations | Reuses admin module |
| `/super-admin/donation-campaigns` | `DonationCampaigns` | Donation campaign management | Reuses admin module |
| `/super-admin/withdrawal-approval` | `WithdrawalApproval` | Review wallet withdrawal approvals | Reuses admin module |
| `/super-admin/withdrawal-receipts` | `WithdrawalReceipts` | Withdrawal receipt view | Reuses admin member/receipt view |
| `/super-admin/registration-settings` | `RegistrationSettings` | Registration setup | Reuses admin module |
| `/super-admin/registration-management` | `RegistrationManagement` | Registration list / approval | Reuses admin module |
| `/super-admin/registration-api-docs` | `RegistrationApiDocs` | API doc page | Static/doc-only |
| `/super-admin/executive-badges` | `ExecutiveBadges` | Executive badge management | Reuses admin module |
| `/super-admin/troubleshooting` | `SystemTroubleshooting` | System troubleshooting | Source-confirmed |
| `/super-admin/audit` | `AuditLogs` | Audit + access + system logs | Source-confirmed |
| `/super-admin/security` | `SecuritySettings` | Security controls UI | Local-only mock state |
| `/super-admin/passwords` | `PasswordManagement` | User password reset | Source-confirmed |
| `/super-admin/access` | `AccessControl` | Role/permission summary | Source-confirmed |
| `/super-admin/monitoring` | `SystemMonitoring` | Metrics dashboard | Source-confirmed |
| `/super-admin/system` | `SuperAdminSystem` | System Control Center | Source-confirmed |
| `/super-admin/database` | `SuperAdminDatabase` | Database health | Source-confirmed |
| `/super-admin/bank` | `SuperAdminBank` | Bank integration status | Source-confirmed |
| `/super-admin/trace` | `SuperAdminTrace` | Transaction trace search | Source-confirmed |
| `/super-admin/online-users` | `OnlineUsers` | Presence list | Source-confirmed |
| `/super-admin/login-activity` | `LoginActivity` | Authentication events | Source-confirmed |
| `/super-admin/website-management` | `WebsiteManagement` | Website admin dashboard | Reuses admin website module |
| `/super-admin/website-content` | `WebsiteContentManagement` | Website content management | Reuses admin website module |
| `/super-admin/website-contact-messages` | `WebsiteContactInbox` | Contact messages | Reuses admin website module |

## Role and shell note

The `SuperAdminLayout` includes an extensive nav with sections for:
- Dashboard & overview
- Member management
- Financial operations
- System administration
- Security & access
- Configuration
- Public website management

The shell wraps the content in `DashboardLock area="super_admin"`, which indicates the Super Admin dashboard is protected by the dashboard lock pattern.

## Page/action → API matrix

### 1) Super Admin dashboard — `/super-admin`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Super Admin stats summary | GET | `/api/super-admin/stats` | Loads dashboard health/redis/queue/database/backups/members/contributions data | `SuperAdminDashboard.tsx` |
| All members listing | GET | `/rest/v1/members` | Counts members, active/inactive, recent signups | `SuperAdminDashboard.tsx` |
| All contributions listing | GET | `/rest/v1/contributions` | Sums contribution totals and counts | `SuperAdminDashboard.tsx` |
| Live online presence count | GET | `/api/admin/presence/online-users` | Hook `useOnlineMembers` / presence data | `hooks/usePresence.ts` |
| Dashboard search/filter | Local-only | None | Search-by-name/phone/email on already fetched members | `SuperAdminDashboard.tsx` |
| Member detail navigation | Local router link | None | `Link to={`/super-admin/member/${member.id}`}` | `SuperAdminDashboard.tsx` |

### 2) Member detail — `/super-admin/member/:memberId`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Fetch all members to locate selected member | GET | `/rest/v1/members` | Broad fetch; then UI filters for `memberId` client-side | `SuperAdminMemberDetail.tsx` |
| Access log history | GET | `/rest/v1/member_access_logs` | Loads member access / admin actions | `SuperAdminMemberDetail.tsx` |
| Create password reset record | POST | `/rest/v1/password_resets` | Adds a reset record with `user_id`, `reset_token`, `reset_by`, etc. | `SuperAdminMemberDetail.tsx` |
| Log super-admin action | POST | `/rest/v1/member_access_logs` | Adds a log entry with `access_type: "reset_password"` | `SuperAdminMemberDetail.tsx` |
| Member chat/conversation data | GET | `/rest/v1/conversation_participants` + `/rest/v1/messages` | Pulls chat context for the selected member | `SuperAdminMemberDetail.tsx` |
| Reset password action | POST | `/functions/v1/rpc/admin_reset_password` | Used in `PasswordManagement`, not in this detail screen | `PasswordManagement.tsx` |

### 3) Password Management — `/super-admin/passwords`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load member list for user lookup | GET | `/rest/v1/members` | Searchable user table | `PasswordManagement.tsx` |
| Reset a user's password | POST | `/functions/v1/rpc/admin_reset_password` | Sends `{ new_password, target_user_id }` | `PasswordManagement.tsx` |
| Invalidate local query cache | Local | None | `queryClient.invalidateQueries` after success | `PasswordManagement.tsx` |
| Generate random password | Local-only | None | Generates random string in browser | `PasswordManagement.tsx` |

### 4) Access Control — `/super-admin/access`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load member roster | GET | `/rest/v1/members` | Used for user count + listing | `AccessControl.tsx` |
| Load user roles | GET | `/rest/v1/user_roles` | Used for role counts (`super_admin`, `admin`, `chairperson`, etc.) | `AccessControl.tsx` |
| Permission matrix | Local rendering | None | Display-only summary of system roles and permissions | `AccessControl.tsx` |

### 5) Security Settings — `/super-admin/security`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load security settings | Local mock | None | Fetch is mocked in-memory with default values | `SecuritySettings.tsx` |
| Save settings | Local mock | None | `await new Promise(...)` then resolves, no real API request | `SecuritySettings.tsx` |
| Toggle 2FA / password expiry | Local state only | None | UI-only state; no persisted backend call | `SecuritySettings.tsx` |

### 6) Audit Logs and activity surfaces — `/super-admin/audit`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load audit entries | GET | `/rest/v1/audit_logs` | Full audit log table | `AuditLogs.tsx` |
| Load member access logs | GET | `/rest/v1/member_access_logs` | Access events and reasons | `AuditLogs.tsx` |
| Load system logs | GET | `/rest/v1/system_logs` | Errors/warnings and operational events | `AuditLogs.tsx` |
| Export logs | Local CSV generation | None | Browser-generated CSV export | `AuditLogs.tsx` |
| Presence summary cards | GET | `/api/admin/presence/online-users` | Uses same presence hook | `AuditLogs.tsx` |

### 7) Online Users — `/super-admin/online-users`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Fetch presence summary | GET | `/api/admin/presence/online-users` | Returns `data`, `online_count`, `offline_count`, `total_count` | `hooks/usePresence.ts` |
| Refresh presence | Local | None | `refetch()` called from button | `OnlineUsers.tsx` |

### 8) Login Activity — `/super-admin/login-activity`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Fetch login history | GET | `/api/admin/presence/login-activity` with filters | Search, role, success, date, pagination | `hooks/usePresence.ts`, `LoginActivity.tsx` |
| Refresh data | Local refetch | Same endpoint | Loader and toast-based refresh | `LoginActivity.tsx` |
| Filter by role/event/outcome/date | Local UI state | None | Adds query params before calling API | `LoginActivity.tsx` |

### 9) System Monitoring — `/super-admin/monitoring`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| System health metrics | GET | `/rest/v1/system_health` | Polls health metrics for monitoring cards | `SystemMonitoring.tsx` |
| Member data counts | GET | `/rest/v1/members` | Used to build database summary stats | `SystemMonitoring.tsx` |
| System message count | GET | `/rest/v1/messages` | Used in DB summary | `SystemMonitoring.tsx` |

### 10) Bank Statement Import History — `/super-admin/import-history`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load paginated statement import rows | GET | `/api/admin/bank-statement-imports` | Displays statement file, linked member, member creation/match outcome, contribution amount/period, and current paid/outstanding dashboard totals; supports search, result filtering, and pagination | `BankStatementImportHistory.tsx` |

The endpoint reads the existing `member_import_batches` and `member_import_rows` audit tables. It is read-only and intentionally excludes the saved default password and raw statement payload from its response.
| Contribution count | GET | `/rest/v1/contributions` | Used in DB summary | `SystemMonitoring.tsx` |
| Payment count | GET | `/rest/v1/payments` | Used in DB summary | `SystemMonitoring.tsx` |

### 10) System Control Center — `/super-admin/system`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| System health overview | GET | `/admin/api/system/health` | `health.application`, `health.database`, etc. | `SuperAdminSystem.tsx` |
| Queue overview | GET | `/admin/api/system/queue` | Reads queue summary | `SuperAdminSystem.tsx` |
| Failed queue jobs | GET | `/admin/api/system/queue/failed` | Loads retryable failures | `SuperAdminSystem.tsx` |
| Retry failed job | POST | `/admin/api/system/queue/failed/{jobId}/retry` | Triggered by action button | `SuperAdminSystem.tsx` |
| Delete failed job | DELETE | `/admin/api/system/queue/failed/{jobId}` | Triggered by action button | `SuperAdminSystem.tsx` |
| Retry all failed jobs | POST | `/admin/api/system/queue/failed/retry-all` | Bulk action | `SuperAdminSystem.tsx` |
| Flush failed jobs | DELETE | `/admin/api/system/queue/failed/flush` | Bulk deletion | `SuperAdminSystem.tsx` |
| Cache overview | GET | `/admin/api/system/cache` | Reads cache health/status | `SuperAdminSystem.tsx` |
| Clear cache | POST | `/admin/api/system/cache/clear` | Bulk cache clear | `SuperAdminSystem.tsx` |
| Flush Redis | POST | `/admin/api/system/cache/redis/flush` | Redis flush action | `SuperAdminSystem.tsx` |
| Financial overview | GET | `/admin/api/system/financial` | Financial monitoring status | `SuperAdminSystem.tsx` |

### 11) Database Health — `/super-admin/database`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Fetch database health | GET | `/admin/api/system/database` | Reads connection, table count, latency, sizes | `SuperAdminDatabase.tsx` |

### 12) Bank Monitoring — `/super-admin/bank`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Fetch bank status | GET | `/admin/api/system/bank` | Reads STK results and last backup metadata | `SuperAdminBank.tsx` |

### 13) Transaction Trace — `/super-admin/trace`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Search transactions | GET | `/admin/api/system/trace?query=...` | Searches by phone, member name, or reference | `SuperAdminTrace.tsx` |
| Present results | Local render | None | Shows matched member, contributions, STK transfers | `SuperAdminTrace.tsx` |

### 14) System Troubleshooting — `/super-admin/troubleshooting`

| UI action/data | Method | API path | Frontend behavior | Evidence |
|---|---:|---|---|---|
| Load system logs | GET | `/rest/v1/system_logs` | Monitoring and unresolved errors | `SystemTroubleshooting.tsx` |
| Load system health | GET | `/rest/v1/system_health` | Health markers and status cards | `SystemTroubleshooting.tsx` |
| Load DB stats with table counts | GET | `/rest/v1/members`, `/rest/v1/messages`, `/rest/v1/conversations` | Counts object summaries | `SystemTroubleshooting.tsx` |
| Resolve a system issue | PATCH | `/rest/v1/system_logs?id=eq.selectedError.id` | Sets `resolved: true`, `resolved_by`, `resolved_at` | `SystemTroubleshooting.tsx` |
| Refresh health | Local refetch | Same endpoints | Simple data refresh / status update | `SystemTroubleshooting.tsx` |

## Backend route mapping for the unique Super Admin APIs

The unique Super Admin-facing endpoints are visible in `khwwc/routes/api.php`:

- `/api/super-admin/stats` -> `SuperAdminStatsController@dashboard`
- `/api/super-admin/health` -> `SuperAdminStatsController@health`
- `/api/admin/api/system/health` -> `Admin\SuperAdminController@systemHealth`
- `/api/admin/api/system/queue` -> `Admin\SuperAdminController@queueOverview`
- `/api/admin/api/system/queue/failed` -> `Admin\SuperAdminController@queueFailed`
- `/api/admin/api/system/cache` -> `Admin\SuperAdminController@cacheOverview`
- `/api/admin/api/system/financial` -> `Admin\SuperAdminController@financialOverview`
- `/api/admin/api/system/database` -> `Admin\SuperAdminController@databaseHealth`
- `/api/admin/api/system/bank` -> `Admin\SuperAdminController@bankMonitoring`
- `/api/admin/api/system/trace` -> `Admin\SuperAdminController@transactionTrace`
- `/api/admin/presence/online-users` -> `PresenceController@onlineUsers`
- `/api/admin/presence/members` -> `PresenceController@memberStatus`
- `/api/admin/presence/login-activity` -> `PresenceController@loginActivity`

## Important source observations

- The Super Admin shell and route tree are robust and large, but some pages are UI-heavy wrappers around shared admin modules rather than fully unique backend surfaces.
- `SecuritySettings.tsx` is essentially a local mock form; it does not persist to a backend table in the current source.
- `PasswordManagement.tsx` is one of the few pages that clearly invokes a backend RPC endpoint (`/functions/v1/rpc/admin_reset_password`).
- `AuditLogs.tsx` and `SystemTroubleshooting.tsx` hit generic `rest/v1` tables rather than custom Super Admin-specific controllers.
- `DashboardLock` wraps the Super Admin area, which means any real lock enforcement is implemented outside the page itself rather than in the route.
- Some features are presented as admin/system monitoring but are not clearly backed by a dedicated controller contract in the route file; they are still source-level observations from the frontend and the generic REST route layer.

## Overall status

The Super Admin matrix is complete as a frontend/API inventory from the source tree:
- Route coverage: complete for `/super-admin` pages in `App.tsx`
- Page-to-request mapping: complete for every major Super Admin page reviewed
- Backend route mapping: complete for the Super Admin-specific system and presence endpoints
- Runtime/authorization sign-off: not claimed; this remains a source-based inventory only
