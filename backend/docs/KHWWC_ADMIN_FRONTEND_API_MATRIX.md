# KHWWC Admin Frontend/API Matrix

## Scope

This inventory covers the Admin-role frontend routes in `frontend/src/App.tsx`, Admin navigation in `frontend/src/components/layout/AdminLayout.tsx`, and the API calls/actions made by their rendered pages. It includes the admin dashboard, regular `/admin/*` pages, and admin's member-view/impersonation routes. Treasurer-specific screens are excluded; Admin users also have access to those routes, which are documented separately in [KHWWC_TREASURER_FRONTEND_API_MATRIX.md](./KHWWC_TREASURER_FRONTEND_API_MATRIX.md). Super Admin routes rendered with `SuperAdminLayout` are likewise outside this role-specific page matrix.

This describes frontend request construction and local UI behavior. It does not certify that every request succeeds, that response payloads are valid, or that backend authorization is correct.

For the backend-calculated Defaulters flow, penalty assessment, member STK settlement, waiver, and distinction between canonical and legacy penalty-payment records, see [KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md](./KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md).

## Client path conventions

- `apiRequest('/rest/v1/...')` calls `/api/rest/v1/...`.
- `apiRequest('/api/...')` calls that `/api/...` path directly.
- Other `apiRequest` paths (for example `/banking/...`, `/notifications/...`, `/functions/...`) are prefixed with the configured API base, `/api` by default.
- `laravelApi` translates ordinary paths into `/api/rest/v1/...`; paths under its special prefixes such as `/functions/` remain under `/api/functions/...`.
- API helpers such as `membersApi`, `eventsApi`, `newsApi`, and `websiteApi` ultimately use the shared API client.
- Browser navigation, local filtering, form handling, and client-side exports are identified as having no direct API request.

## Admin route inventory

### Admin dashboard and core operations

| Frontend URL | Rendered component | Main frontend function |
|---|---|---|
| `/admin` | `AdminDashboard` | Summary cards, recent activity, latest news |
| `/admin/members` | `Members` | Search/manage members, roles, beneficiaries |
| `/admin/members/:memberId` | `MemberDetail` | Member profile, contribution/penalty status, SMS history |
| `/admin/members/:memberId/view-as-member` | `MemberDetailAsImpersonate` | Start member-view session/navigation |
| `/admin/members/:memberId/view-as-member/dashboard` | `MemberDashboard` in `ImpersonateRouter` | Member dashboard in admin view-as context |
| `/admin/members/:memberId/view-as-member/events` | `MemberEvents` in `ImpersonateRouter` | Member events in admin view-as context |
| `/admin/members/:memberId/view-as-member/documents` | `MemberDocuments` in `ImpersonateRouter` | Member documents in admin view-as context |
| `/admin/members/:memberId/view-as-member/downloads` | `MemberDownloads` in `ImpersonateRouter` | Member downloads in admin view-as context |
| `/admin/members/:memberId/view-as-member/withdrawal-receipts` | `WithdrawalReceipts` in `ImpersonateRouter` | Member receipt view in admin view-as context |
| `/admin/members/:memberId/view-as-member/news` | `MemberNews` in `ImpersonateRouter` | Member news view in admin view-as context |
| `/admin/members/:memberId/view-as-member/beneficiaries` | `MemberBeneficiaries` in `ImpersonateRouter` | Member beneficiary view in admin view-as context |
| `/admin/members/:memberId/view-as-member/notifications` | `MemberNotifications` in `ImpersonateRouter` | Member notifications in admin view-as context |
| `/admin/members/:memberId/view-as-member/profile` | `MemberProfile` in `ImpersonateRouter` | Member profile in admin view-as context |
| `/admin/members/:memberId/view-as-member/pay-penalty` | `PayPenalty` in `ImpersonateRouter` | Member penalty view in admin view-as context |
| `/admin/members/:memberId/view-as-member/donate` | `Donate` in `ImpersonateRouter` | Member donation view in admin view-as context |
| `/admin/members/:memberId/view-as-member/executive/:roleName` | `ExecutiveDashboard` in `ImpersonateRouter` | Executive member view in admin view-as context |
| `/admin/contributions` | `Contributions` | Contribution listing and reconciliation |
| `/admin/import` | `ExcelImport` | Spreadsheet bulk import |
| `/admin/beneficiary-import` | `BeneficiaryImport` | Spreadsheet beneficiary import |
| `/admin/beneficiaries` | `Beneficiaries` | Beneficiary dashboard/actions |
| `/admin/payments` | `Payments` | Bank payment listing |
| `/admin/unmatched` | `UnmatchedPayments` | Assign and reconcile unmatched payments |
| `/admin/sms` | `BulkSms` | Select recipients, preview, send, inspect history |
| `/admin/events` | `AdminEvents` (`pages/admin/Events`) | Manage welfare events |
| `/admin/documents` | `AdminDocuments` (`pages/admin/Documents`) | Review, filter, audit, approve/reject documents |
| `/admin/news` | `AdminNews` (`pages/admin/News`) | Create, edit, publish, delete news |
| `/admin/beneficiary-requests` | `BeneficiaryRequests` | Review beneficiary requests |
| `/admin/defaulters` | `Defaulters` | Review overdue/defaulting members |
| `/admin/notifications` | `AdminNotifications` | Admin notification inbox/actions |
| `/admin/settings` | `AdminSettings` (`pages/admin/Settings`) | Welfare settings and role management |
| `/admin/banking/diagnostics` | `BankDiagnostics` | Run/check bank API diagnostics |
| `/admin/signatures` | `OfficeSignatures` | Upload/manage office-bearer signature images |
| `/admin/penalty-payments` | `VerifyPenaltyPayments` | Verify/reject penalty payments |
| `/admin/penalty-wallet` | `PenaltyWallet` | Penalty balance, payments, withdrawal requests |
| `/admin/donations` | `DonationWallet` | Donation balance, donations, withdrawal requests |
| `/admin/donation-campaigns` | `DonationCampaigns` | Create/edit/delete funds drives |
| `/admin/withdrawal-approval` | `WithdrawalApproval` | Approve/reject wallet withdrawals |
| `/admin/withdrawal-receipts` | `WithdrawalReceipts` | View/download withdrawal receipts |
| `/admin/registration-settings` | `RegistrationSettings` | Configure public registration settings |
| `/admin/registration-management` | `RegistrationManagement` | List, approve, reject registrations |
| `/admin/registration-api-docs` | `RegistrationApiDocs` | Static API documentation; no API request identified |
| `/admin/executive-badges` | `ExecutiveBadges` | Manage executive badges and images |
| `/admin/website-management` | `WebsiteManagement` | Website admin dashboard and links to management sections |
| `/admin/website-content` | `WebsiteContentManagement` | Manage website content entries |
| `/admin/website-contact-messages` | `WebsiteContactInbox` | Read website contact messages |

### Admin navigation gap

`AdminLayout` includes a Schedule Manager navigation item pointing to `/admin/schedule`, but the Admin-role `<Routes>` block in `App.tsx` does not register `/admin/schedule`. The `Schedule` component is imported in `App.tsx`, but no matching Admin route was found. Treat the sidebar destination as currently unresolved by the frontend route tree.

### Admin also has Treasurer routes

The frontend registers the Treasurer routes outside the role-conditional route block and comments that they are accessible by Admin. Those routes are intentionally not repeated here; see the dedicated Treasurer matrix. Their availability should not be confused with an `/admin/*` page.

## Page/action → API matrix

### Admin dashboard and shared Admin shell

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Summary cards | GET | `/api/members/stats/summary` | Total members, collections, expected/outstanding, defaulters, penalties |
| Recent contribution activity | GET | `/api/admin/api/transactions` | Reads recent transaction data |
| Latest news | GET | `/api/rest/v1/news?limit=3` | `newsApi.list({limit: 3})` populates dashboard |
| Admin notification inbox (polls every 20 seconds) | GET | `/api/admin/notifications/inbox?per_page=20` | Popover list + unread count |
| Mark all admin notifications read | POST | `/api/admin/notifications/read-all` | Invoked from inbox button |
| Mark one notification read | POST | `/api/admin/notifications/{notificationId}/read` | Invoked when opening unread notification |
| Unseen event badge (polls every 30 seconds) | GET | `/api/notifications/events/unseen-count?last_seen_at={optional}` | Reads last-seen timestamp from local storage |
| Open target notification | None | None | Navigates locally; `chat:` target dispatches a chat-open browser event |
| Sidebar, mobile menu, sign out, DashboardSwitcher | None in this component | None | UI/navigation; sign out uses shared auth flow |
| Floating chat, AI assistant, event popup, dashboard lock | Shared components | Varies by child component | Separate reusable UI; not part of dashboard page-specific API calls |

### Members — `/admin/members`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Search/list members | GET | `/api/rest/v1/members?or=(name.ilike...,member_id.ilike...)&limit=1000` | `membersApi.list`; debounced search |
| Load member/role table | GET | `/api/rest/v1/members?select=id,name,phone,user_id&order=name.asc`; `/api/rest/v1/user_roles?select=user_id,role` | Joins roles in frontend; re-fetches role data every 30 seconds |
| Load selected member beneficiaries | GET | `/api/rest/v1/beneficiaries?member_id=eq.{memberId}` | `beneficiariesApi.list` |
| Create member | POST | `/api/functions/v1/create-member` | Sends full name, normalized phone, optional member code |
| Assign role after creation | POST | `/api/functions/v1/rpc/assign_user_role` | Sends user ID and role |
| Update member | PATCH | `/api/rest/v1/members?id=eq.{memberId}` | Saves member fields |
| Resolve user ID for role change | GET | `/api/rest/v1/members?select=user_id&id=eq.{memberId}&limit=1` | Used before role assignment/removal |
| Change role / remove role | POST / DELETE | POST `/api/functions/v1/rpc/assign_user_role`; DELETE `/api/rest/v1/user_roles?user_id=eq.{userId}` | Assign/remove member roles |
| Add beneficiary | POST | `/api/rest/v1/beneficiaries` | Sends beneficiary details for selected member |
| Delete beneficiary | DELETE | `/api/rest/v1/beneficiaries?id=eq.{beneficiaryId}` | Removes selected beneficiary |
| Delete member | DELETE | `/api/rest/v1/members?id=eq.{memberId}` | Deletes member record |
| Search, tabs, dialogs, confirmations, local sorting | None | None | Client-side UX |

### Member detail — `/admin/members/:memberId`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load member profile | GET | `/api/rest/v1/members?id=eq.{memberId}&limit=1` | Displays member profile |
| Load linked auth account fields | GET | `/api/rest/v1/auth_users?id=eq.{userId}&select=last_login_at,last_seen_at,must_change_password` | Adds account/session metadata |
| Load contribution history | GET | `/api/rest/v1/contributions?member_id=eq.{memberId}&order=year.desc,month.desc` | Contribution history |
| Load penalties | GET | `/api/rest/v1/penalties?member_id=eq.{memberId}&order=created_at.desc` | Penalty history |
| Load member financial stats | GET | `/api/members/stats?member_id={memberId}` | Via `useMemberStats` hook |
| Load organization/bank settings | GET | `/api/rest/v1/organization_settings` | Used to display/copy payment instructions |
| Load member SMS history (page size 50) | GET | `/api/bulk-sms/members/{memberId}/history?page={page}&per_page=50` | Paginated communication history |
| Copy bank details / back navigation | None | None | Clipboard and local navigation |

### Admin view-as-member routes

These routes render existing member pages inside `ImpersonateRouter`; the frontend API client adds `X-View-As-Member: {memberId}` based on the current path. The API paths are the same as the underlying member pages, and the header is attached by the shared client. The browser action itself does not call an impersonation-start API in the route component.

| Action/page family | Method and API shape | Frontend behavior |
|---|---|---|
| Enter member view / member detail actions | GET/PATCH/POST/DELETE as used by the member page | `X-View-As-Member` sent by `apiRequest`; member page requests are routed through the shared client |
| Member dashboard, events, documents, downloads, receipts, news, beneficiaries, notifications, profile, penalty payment, donate, executive page | Same member-page endpoint sets as normal member screens | Exact component is the corresponding `pages/member/*` component; view context is route-derived |
| Exit member view | None | Client navigation back to Admin member pages |

### Contributions — `/admin/contributions`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load welfare settings | GET | `/api/rest/v1/organization_settings` | `settingsApi.list` |
| Load members | GET | `/api/rest/v1/members?limit=100` | `membersApi.list({status:'active'})`; current helper does not serialize the `status` option because it is not a supported `MembersListParams` field |
| Load/create/update contribution rows | GET / POST / PATCH | `/api/rest/v1/contributions[?...filters]`; `/api/rest/v1/contributions`; `/api/rest/v1/contributions?id=eq.{contributionId}` | Contribution management/reconciliation |
| Create penalty row if required by action | POST | `/api/rest/v1/penalties` | Creates linked penalty record |
| Update member contribution totals | PATCH | `/api/rest/v1/members?id=eq.{memberId}` | Updates the member aggregate in the page flow |
| Filters and calculations | None | None | Client-side unless a query filter is sent |

### Defaulters — `/admin/defaulters`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load defaulters | GET | `/api/members/stats/defaulters` | Maps outstanding contributions, penalties, pending months and overdue months into the table |
| Activate or suspend member | POST | `/api/functions/v1/rpc/update_member_status` | Sends `new_status` and `target_member_id`; refreshes defaulters, Admin stats and members |
| Record penalty waiver | POST | `/api/functions/v1/admin/members/{memberId}/penalty-waivers` | Sends amount and reason; validates positive amount, outstanding penalty cap and minimum reason length in UI |
| Search, totals, navigation to member, confirmation/dialog state | None | None | Client-side filtering/calculation/navigation |

### Imports — `/admin/import` and `/admin/beneficiary-import`

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/import`: upload/parse/select spreadsheet rows | None | None | Spreadsheet parsing and preview are client-side |
| `/admin/import`: submit bulk rows | POST | `/api/functions/v1/bulk-import` | `importsApi.bulkImport(rows, 'bulk_import', '')` |
| `/admin/beneficiary-import`: lookup member rows | GET | `/api/rest/v1/members` | Data used to match imported beneficiary rows |
| `/admin/beneficiary-import`: clear existing member beneficiaries before import | DELETE | `/api/rest/v1/beneficiaries?member_id=eq.{memberId}` | Per-member replacement import flow |
| `/admin/beneficiary-import`: insert beneficiaries | POST | `/api/rest/v1/beneficiaries` | Creates imported beneficiary rows |
| File parse, validation, preview/progress | None | None | Client-side |

### Beneficiaries — `/admin/beneficiaries`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| List/read beneficiaries and related member data | GET | `/api/rest/v1/beneficiaries` and member/stat queries from the page | Loads dashboard data |
| Edit beneficiary | PATCH | Source request is constructed as `/api/rest/v1/beneficiaries?id=eq.selectedBeneficiary.id` | The observed source contains a literal `selectedBeneficiary.id` string in this URL |
| Search/filter/selection | None | None | Client-side |

### Payments and unmatched payments — `/admin/payments`, `/admin/unmatched`

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/payments`: load latest 100 payments | GET | `/api/rest/v1/payments?order=received_at.desc&limit=100` | Read-only payments table |
| `/admin/unmatched`: load unresolved unmatched payments | GET | `/api/rest/v1/unmatched_payments?resolved=eq.false&order=created_at.desc` | Reconciliation queue |
| `/admin/unmatched`: load active member choices | GET | `/api/rest/v1/members?select=id,name,member_id,phone&is_active=eq.true&order=name.asc` | Member selector |
| `/admin/unmatched`: load active campaigns | GET | `/api/rest/v1/donation_campaigns?select=id,title,active&active=eq.true&order=title.asc` | Fund-drive matching |
| `/admin/unmatched`: load a member's unpaid penalties | GET | `/api/rest/v1/penalties?select=id,amount,created_at&member_id=eq.{memberId}&is_paid=eq.false&order=created_at.asc` | Penalty matching |
| `/admin/unmatched`: load bank unmatched transactions | GET | `/api/banking/coop/unmatched-transactions?page={page}&per_page=50` | Paginated bank statement queue |
| Match bank statement transaction | POST | `/api/banking/coop/unmatched-transactions/{id}/reconcile` | Sends match type/member and optional campaign/penalty IDs |
| Assign generic unmatched payment to member | PATCH | `/api/rest/v1/payments?id=eq.{paymentId}`; `/api/rest/v1/unmatched_payments?id=eq.{unmatchedId}` | Marks payment matched and unmatched row resolved |
| Mark corresponding contribution paid/remove penalty/update totals | GET/PATCH/DELETE | `/api/rest/v1/payments?...`; `/api/rest/v1/contributions?...`; `/api/rest/v1/contributions?id=eq.{contributionId}`; `/api/rest/v1/penalties?contribution_id=eq.{contributionId}`; `/api/rest/v1/members?id=eq.{memberId}` | Frontend performs linked follow-up mutations |

### Penalty payment verification and wallet/campaign operations

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/penalty-payments`: list payment records | GET | `/api/rest/v1/penalty_payments` | Table of submitted penalty payments |
| Verify payment | PATCH | `/api/rest/v1/penalty_payments?id=eq.{paymentId}` | Updates payment verification status/details |
| Reject payment | PATCH | Same endpoint | Updates rejected status and reason |
| `/admin/penalty-wallet`: load settings, wallet, withdrawals, signatures, payments, members, stats | GET | `/api/rest/v1/organization-settings`, `/penalty-wallet`, `/penalty-withdrawals`, `/signatory-signatures`, `/penalty-payment-records`, `/members?select=id,name,member_id`; `/api/members/stats` | Page composes wallet/member display |
| `/admin/penalty-wallet`: request withdrawal | POST | `/api/rest/v1/withdrawals` | Creates withdrawal; page then refreshes `/api/rest/v1/penalty-withdrawals` |
| `/admin/donations`: load settings, wallet, withdrawals, signatures, recent payments | GET | `/api/rest/v1/organization_settings`, `/donation_wallet`, `/donation_withdrawals?order=created_at.desc`, `/signatory_signatures`; `/api/wallets/donation/recent-payments?limit=20` | Donation dashboard |
| `/admin/donations`: request withdrawal | POST | `/api/rest/v1/donation_withdrawals`; then `/api/rest/v1/donation_withdrawal_signatories` | Creates withdrawal and pending chairperson/secretary/treasurer approvals |
| `/admin/donation-campaigns`: list campaigns/member count | GET | `/api/rest/v1/donation_campaigns?order=created_at.desc`; `/api/rest/v1/members?select=id&is_active=eq.true` | Campaign listing and shared goal calculation |
| Create/update/delete campaign | POST / PATCH / DELETE | `/api/rest/v1/donation_campaigns`; `/api/rest/v1/donation_campaigns?id=eq.{campaignId}` | Campaign CRUD |

### Withdrawal approvals, receipts, and beneficiary requests

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/withdrawal-approval`: load roles | GET | `/api/rest/v1/user-roles` | Determines current signatory role |
| Load withdrawal sets and signature data | GET | `/api/rest/v1/penalty-withdrawals`, `/donation-withdrawals`, `/operational-withdrawals`, `/signatory-signatures` | Combines wallet withdrawal types |
| Approve/reject a signatory request | POST | `/api/rest/v1/{withdrawal_signatories\|donation_withdrawal_signatories\|operational_withdrawal_signatories}/update` | Sends withdrawal ID, role, status, optional rejection reason |
| Refresh after decision | GET | Same withdrawal endpoints and signatures endpoint | Reloads status |
| `/admin/withdrawal-receipts`: load withdrawal sets, signatures, organization settings | GET | `/api/rest/v1/penalty-withdrawals`, `/donation-withdrawals`, `/operational-withdrawals`, `/signatory-signatures`, `/organization-settings` | Combines three withdrawal types and enriches signatory names/signatures |
| Print receipt | None | None | Opens a browser print window using the loaded receipt data |
| Download receipt PDF | None | None | Generates the PDF client-side from loaded receipt data |
| `/admin/beneficiary-requests`: list and review | GET / POST | `/api/admin/beneficiary-requests`; `/api/admin/beneficiary-requests/{requestId}/review` | Review endpoint receives decision/review data |

### Communications, events, news, minutes, documents

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/sms`: load recipients with filters/search/pagination | GET | `/api/bulk-sms/recipients?filter={filter}&page={page}&per_page=25[&q={query}][&member_ids={ids}]` | Paginated recipient selection |
| Dry-run / send message | POST | `/api/bulk-sms/dry-run`; `/api/bulk-sms/send` | Sends message, recipient selection and `live` flag; send invalidates recipient and history queries |
| Load send history / selected job recipients | GET | `/api/bulk-sms/history?page={page}&per_page=25`; `/api/bulk-sms/history/{jobId}/recipients?page={page}&per_page=50` | Paginated batch and recipient history |
| `/admin/events`: create event | POST | `/api/rest/v1/events` | `eventsApi`/REST creates event |
| Event-triggered SMS | POST | `/api/notifications/bulk-sms` | Sends selected recipients/message; page call is best-effort |
| `/admin/news`: list/create/edit/delete/publish-state | GET / POST / PATCH / DELETE | `/api/rest/v1/news`; `/api/rest/v1/news?id=eq.{newsId}` | CRUD/status actions |
| News-triggered SMS | POST | `/api/notifications/bulk-sms` | Optional bulk notification action |
| `/admin/documents`: summary/catalog/list/audit/expiring/orphans | GET | `/api/admin/documents/summary`; `/api/documents/types`; `/api/admin/documents?...`; `/api/admin/documents/audit?limit=30`; `/api/admin/documents/expiring?days=30`; `/api/admin/documents/orphans` | Admin document overview and filters |
| Approve/reject/delete document | POST / DELETE | `/api/documents/{id}/approve`; `/api/documents/{id}/reject`; `/api/documents/{id}?hard_delete={0\|1}` | Review and removal actions |
| `/admin/minutes`: meeting minutes CRUD/review | GET / POST / PUT | `/api/minutes`; `/api/minutes/{id}`; `/api/minutes/executive-members`; `/api/minutes/{id}/submit`; `/api/minutes/{id}/approve`; `/api/minutes/{id}/reject` | UI uses the minutes API family for list, detail, editing and workflow transitions |
| Filters, search, dialogs, preview and local print | None | None | Client-side unless a request above is triggered |

### Registration, role/settings, signatures and badges

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/registration-settings`: load/save registration config | GET / PUT | `/api/functions/v1/admin-registration/config` | Loads/updates registration configuration |
| `/admin/registration-management`: list registrations | GET | `/api/functions/v1/admin-registration/registrations?...` | List/status/search filters |
| Load registration detail / approve / reject | GET / POST | `/api/functions/v1/admin-registration/registrations/{id}`; `/registrations/{id}/approve`; `/registrations/{id}/reject` | Sends approve/reject data as applicable |
| `/admin/registration-api-docs` | None | None | Static API documentation page |
| `/admin/settings`: load members/roles | GET | `/api/rest/v1/members`; `/api/rest/v1/user_roles` | `membersApi.withRoles` / list |
| Save welfare settings | GET / POST / PATCH | `/api/rest/v1/welfare_settings`; `/api/rest/v1/welfare_settings?id=eq.{settingsId}` | Reads/saves settings |
| Assign member role | POST | `/api/functions/v1/rpc/assign_user_role` | Role update |
| Remove member role | DELETE | `/api/rest/v1/user_roles?user_id=eq.member.user_id` | Current observed source has a literal `member.user_id` in the query string |
| `/admin/signatures`: upload office signature | POST multipart then POST/PATCH | `/api/storage/v1/object/signatures/{path}`; `/api/rest/v1/office_bearer_signatures` or `?role=eq.admin` | Uploads object, then creates/updates signature URL metadata |
| `/admin/executive-badges`: list/create/edit/delete badges | GET / POST / PATCH / DELETE | `/api/rest/v1/executive_badges`; `/api/rest/v1/executive_badges?id=eq.{badgeId}` | CRUD |
| Upload badge profile image | POST multipart | `/api/storage/v1/object/profile-images/{fileName}` | `getPublicUrl` only constructs URL locally; badge form saves the URL with badge record |
| `/admin/banking/diagnostics`: run connectivity / individual test | POST | `/api/banking/coop/diagnostics/connectivity`; `/api/banking/coop/diagnostics/run/{testType}` | Runs and renders diagnostic checks |
| Load diagnostic history/report | GET | `/api/banking/coop/diagnostics/history?limit=50`; `/api/banking/coop/diagnostics/report/{diagnosticId}`; `/api/banking/coop/diagnostics/report/latest` | Reads saved runs and reports |
| Download/copy diagnostic report | None | None | Creates local TXT/JSON download or copies text to clipboard |

### Website management pages

These administer the Laravel-backed website CMS from the app interface. The separate public website domain itself is outside the scope of this matrix.

| Page/action | Method | API path | Frontend behavior |
|---|---:|---|---|
| `/admin/website-management`: dashboard summary | GET | `/api/admin/website/dashboard` | Counts content, profiles, messages and events |
| `/admin/website-content`: list content by type | GET | `/api/admin/website/content?content_type={type}` | Content table |
| Create/update/archive content | POST / PUT / POST | `/api/admin/website/content`; `/api/admin/website/content/{id}`; `/api/admin/website/content/{id}/archive` | Content CRUD/archive |
| Website people: list profiles and leadership member choices | GET | `/api/admin/website/people`; `/api/admin/website/people/leadership-members` | Profile and member selections |
| Search members for a profile | GET | `/api/admin/website/people/search-members?q={query}` | Search picker |
| Create/update/publish/archive profile | POST / PUT / POST / POST | `/api/admin/website/people`; `/api/admin/website/people/{profileId}`; `/toggle-publish`; `/archive` | Profile lifecycle |
| Contact inbox: list / mark read | GET / POST | `/api/admin/website/contact-messages`; `/api/admin/website/contact-messages/{messageId}/read` | Inbox and message-read action |
| Website settings sections | GET / PUT | `/api/admin/website/settings/{section}` | Save site/navigation/footer/SEO/Google/social/email settings |
| Email templates: list/create/update | GET / POST / PUT | `/api/admin/website/email-templates`; `/api/admin/website/email-templates/{templateKey}` | Template editor |
| Send test email | POST | `/api/admin/website/email/test` | Sends test recipient/template |
| Media library: list/upload/delete | GET / POST / DELETE | `/api/admin/website/media`; `/api/storage/v1/object/website-media/{path}` | Uploads media to storage; deletes media object |

## Actions handled entirely in the frontend

- Dashboard stat formatting, latest-news rendering, search/filter/sort controls, and dialog state.
- Spreadsheet parsing/preview before import.
- PDF/receipt rendering, where generated from already loaded data.
- Search/select and pagination controls that only alter query variables; subsequent server-backed data changes are listed above.
- Website media public URL construction after upload.
- Static Registration API Docs page.

## Observed source-level follow-up items

These are frontend source observations, not backend conclusions:

1. `/admin/schedule` appears in AdminLayout navigation but is not registered in the Admin-role routes, although `Schedule` is imported.
2. `Beneficiaries.tsx` constructs an update URL with literal `selectedBeneficiary.id`, rather than interpolating the selected beneficiary ID.
3. `Settings.tsx` role-removal URL contains the literal `member.user_id`, rather than interpolating the selected member's user ID.
4. `membersApi.list` accepts search, limit, and offset only; the `/admin/contributions` page passes a `status` option that the helper does not serialize.
5. In Admin `MemberDetail`, member contribution/penalty queries and some page state are sourced from direct REST requests; the requests are listed as observed and not claimed to be sufficient for all UI widgets.
6. The admin route tree includes 13 view-as-member pages. They are member components with route-derived `X-View-As-Member` context, rather than separate Admin API implementations.

## Completion boundary

- Routes are transcribed from the Admin-role `<Routes>` block in `frontend/src/App.tsx`; the sidebar-only `/admin/schedule` discrepancy is separately recorded.
- API paths are based on page code and shared API helpers; generic REST table calls are shown as `/api/rest/v1/{table}` when query details vary.
- The matrix does not assert backend authorization, endpoint availability for every role, server payload validity, end-to-end success, or browser acceptance.
