# KHWWC Member Frontend/API Matrix

## Scope and interpretation

This is the member application screen, action, and API inventory for building a replacement frontend. It covers every member route declared in `frontend/src/App.tsx`, the member dashboard, member-layout navigation and overlays, and the shared withdrawal-receipt page. It also documents the persistent chat panel because it is rendered inside `MemberLayout`.

The matrix traces source code; it is not a claim that every endpoint, payload, permission rule, storage object, or third-party payment provider was successfully exercised at runtime. An endpoint described as a REST table endpoint is registered through Laravel's generic REST handler, not a standalone CRUD route. Preserve the filters and payloads that the frontend actually sends, and do not infer additional backend capabilities.

## API URL and authentication conventions

| Frontend call pattern | Resolved request |
|---|---|
| `apiRequest('/rest/v1/{table}?...')` | `GET/POST/PATCH/DELETE /api/rest/v1/{table}` by default, through `RestApiController@handle` |
| `apiRequest('/api/{path}')` | Request is sent to the exact `/api/{path}` URL |
| `laravelApi.get('/payments/...')` | `/api/payments/...` by default |
| `laravelApi.get('/{table}')` | `/api/rest/v1/{table}` by default |
| `storageApi.upload(bucket, path, file)` | `POST /api/storage/v1/object/{bucket}/{path}` as multipart form data |

`VITE_LARAVEL_API_BASE` overrides the default `/api` base for the shared clients. The core client reads JWT session data from `localStorage.welfare_auth`; it sends `Authorization: Bearer <token>`. In admin view-as-member URLs it also sends `X-View-As-Member`, which the backend must independently authorize. Do not treat a frontend role check or route as an authorization boundary.

The REST catch-all route is protected by `auth.jwt`, `view.as.member`, and `throttle:api`; it accepts only the table names allowed by `routes/api.php`. Explicit member stats, payments, notification-count, document, and storage routes are registered separately. See `khwwc/routes/api.php`.

For the complete penalty read, STK, settlement, Defaulters, and wallet flow—including the identified limits of aggregate fallback and multiple-penalty payment—see [KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md](./KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md).

## Member route inventory

| Frontend URL | Component/layout | Purpose |
|---|---|---|
| `/member` | `MemberDashboard` in `MemberLayout` | Personal financial overview, contribution payment, bank payment details, executive-role shortcuts, current news |
| `/member/events` | `MemberEvents` in `MemberLayout` | Active upcoming events |
| `/member/documents` | `MemberDocuments` in `MemberLayout` | Required ID/passport document upload, preview, replacement, deletion, review status |
| `/member/downloads` | `MemberDownloads` in `MemberLayout` | Contribution statement, constitution, approved minutes, member memos |
| `/member/withdrawal-receipts` | `WithdrawalReceipts` in `MemberLayout` | Withdrawal receipt preview, print, and local PDF download; link appears in navigation for non-member roles |
| `/member/news` | `MemberNews` in `MemberLayout` | Active announcements |
| `/member/beneficiaries` | `MemberBeneficiaries` in `MemberLayout` | Beneficiary list and add/remove request tracking |
| `/member/notifications` | `MemberNotifications` in `MemberLayout` | Notifications and mark-as-read action |
| `/member/profile` | `MemberProfile` in `MemberLayout` | Name/status message, profile image, password change |
| `/member/pay-penalty` | `PayPenalty` in `MemberLayout` | Select and pay outstanding penalties via M-Pesa |
| `/member/donate` | `Donate` in `MemberLayout` | Make a full or partial payment to an active funds drive |
| `/member/executive/:roleName` | `ExecutiveDashboard` in `MemberLayout` | Role/badge information for an assigned executive role |

The same route set is also mounted below the admin view-as-member prefix by `ImpersonateRouter`. The viewed member ID is taken from the route/context for read requests. Controls that mutate member-owned data are disabled or rejected by some pages in impersonation mode, but this must not replace backend authorization.

## `/member` dashboard — data, calculations, and controls

| Visible data/control | Frontend behavior and request | Request / payload | Result and implementation detail |
|---|---|---|---|
| Member avatar, name, greeting, member-since | Reads member record | `GET /api/rest/v1/members?id=eq.{memberId}&limit=1` | Uses `members.profile_picture_url`, `name`, `first_contribution_date`, `created_at`; greeting is computed locally using Africa/Nairobi time. |
| Contribution totals, months pending, outstanding, overdue and due-date cards | Calls `useMemberStats(memberId)` | `GET /api/members/stats?member_id={memberId}` | Backend response is wrapped as `{ data: ... }`; stats service returns member metrics. The cards are styled as clickable but have no click handlers in source. Do not make them navigate unless specifically designed as a new behavior. |
| Contribution records and recent contribution history | Reads contribution rows, sorted year/month descending | `GET /api/rest/v1/contributions?member_id=eq.{memberId}&order=year.desc&order=month.desc` | Used for determining if any contribution exists. Recent contribution section is locally collapsible; it renders confirmed payment rows from the payments request. |
| Last 5 confirmed payment entries | Reads payment history | `GET /api/rest/v1/payments?member_id=eq.{memberId}&status=eq.confirmed&order=occurred_at.desc&limit=5` | Displays provider reference, occurrence time, confirmed status, and amount. |
| Penalty rows | Reads penalty records | `GET /api/rest/v1/penalties?member_id=eq.{memberId}&order=created_at.desc` | Dashboard calculations primarily use `MemberStats`; penalty-row list is not presented as a dashboard control. |
| Welfare settings / monthly amount / bank data | Reads first settings row | `GET /api/rest/v1/welfare_settings?limit=1` | Dashboard uses monthly contribution amount, bank name, paybill, and account number. Existing UI has fallback display values; a replacement must not present fallback values as verified server data. |
| Latest news cards | Reads up to 10 active announcements | `GET /api/rest/v1/news?status=eq.active&order=created_at.desc&limit=10` | Client removes announcements whose scheduled/rescheduled date is in the past. Cards have no action handler in the inspected source. |
| Executive roles / role cards | Reads member roles and badges | `GET /api/rest/v1/member_executive_roles?member_id={memberId}`; `GET /api/rest/v1/executive_badges`; separate sorted badges request `GET /api/rest/v1/executive_badges?order=role_name` | Role-card click navigates locally to `/member/executive/{role_name}` (or the matching view-as-member route). A missing role table is caught and treated as no roles by the current component. |
| “Pay overdue amount / Pay contribution balance / Pay monthly contribution” | Opens payment dialog and prefills amount and phone locally | No request on open | Prefill uses outstanding amount where positive, otherwise configured monthly amount; phone comes from member profile. |
| Amount and phone fields | Local form state; phone is normalized when submitting | No request until “Send Prompt” | Client checks amount is positive and a phone value is present. It rounds amount upward to an integer. Server-side validation remains authoritative. |
| “Send Prompt” | Starts M-Pesa STK push | `POST /api/payments/stk-push` JSON: `{ phone, amount, account_ref, member_id, paymentType: "monthly_contribution", transactionDesc }` | `paymentsApi.stkPush`; successful response may contain `checkoutRequestId` / `checkout_request_id`. Setup-required and unsuccessful results show an error. |
| Payment status poll | After checkout ID, polls status every 10 seconds for up to 60 attempts; stores pending checkout ID in local storage per member so it can resume on return | `GET /api/payments/status/{checkoutRequestId}` | Success statuses recognized: `confirmed`, `paid`, `verified`, `partial`. Terminal failure statuses: `failed`, `cancelled`, `reversed`. On success it refreshes financial queries. |
| “Close” / “Cancel” in payment dialog | Closes dialog; does not itself cancel the provider payment | No request | While request is being sent the action is disabled. When payment is pending the close label is “Close”. |
| “Recent contributions” disclosure | Expands/collapses payment history | No request | Loading, empty-history, and empty-confirmed-payments messages are rendered. |
| “Copy Details” | Copies formatted bank/paybill/account details to clipboard | No request | Uses `navigator.clipboard`; shows local success toast. No server-side clipboard operation. |
| Dashboard stat cards, verified badges, news cards | Presentation only in inspected component | None | Do not implement a presumed click action; no handler is wired in source. |

## Member route page → action/API matrix

### Events — `/member/events`

| Control/data | Request and behavior |
|---|---|
| Event list | `GET /api/rest/v1/events?status=eq.active&order=created_at.desc` |
| Upcoming event cards | Client filters out events whose `rescheduled_date || scheduled_date` is earlier than the current time; cards show title, type, created date, schedule, description, departed name/relationship, and contribution amount when provided. |
| Loading / empty | Displays “Loading...” and “No active events at this time.” No explicit retry button is present. |
| Card actions | No per-event action/button is wired on this route. |
| Event alert overlay in shared layout | `EventPopup` separately reads active events using the event list helper. “Mark events as seen” stores the latest event timestamp in local storage key `member_last_seen_events_at` and invalidates local queries. “Dismiss” only closes the overlay. Badge count comes from `GET /api/notifications/events/unseen-count?last_seen_at={timestamp}`. No event-seen write endpoint is called. |

### Documents — `/member/documents`

| Control/data | Request and behavior |
|---|---|
| Required-document summary | `GET /api/documents/me/summary`; response envelope `{ data: DocumentSummary }`. Displays completion percent and required/uploaded/pending/approved/rejected/expired/expiring counters. |
| Document types | `GET /api/documents/types`; response envelope `{ data: DocumentType[] }`. The page limits its displayed/uploadable types to `national_id` and `passport`. |
| My uploaded documents | `GET /api/documents/me`; response envelope `{ data: MemberDocument[] }`; controller scopes records to authenticated member identity. |
| Status tabs | Local filtering for all, approved, pending, rejected, and expired; expiry is determined in the browser from `expires_at`. |
| Search input | Local search by file name, type code, and notes. |
| “Upload document” / “Upload your first document” | Opens upload dialog; no request until submit. User selects document type and file, optionally notes/expiry. If a document of that type is already uploaded, UI asks for confirmation and uses replace instead. |
| “Submit upload” | `POST /api/documents/` multipart: `document_type_code`, `file`, optional `notes`, optional `expires_at`. Backend `DocumentController@upload` validates fields, active document type and allowed member upload types, then returns a document envelope. |
| Upload constraints | Backend requires type code and file; notes are nullable string up to 1000 chars; `expires_at` is nullable date. It restricts member uploads to `national_id` and `passport`, and further checks MIME/size against the configured document type. |
| “Preview” | Opens local dialog using returned `download_url`; image and PDF are embedded, other file types offer a direct link. No fetch is issued by the preview handler. |
| “Download” | Opens `doc.download_url` in a new tab. The URL/content type and download disposition are controlled by the backend URL response and storage implementation, not this page. |
| “Replace” | File picker then `POST /api/documents/{id}/replace` multipart (`file`, optionally `notes`, `expires_at`). Success invalidates document list and summary. |
| Delete icon | Confirmation dialog then `DELETE /api/documents/{id}?hard_delete=0`; success invalidates list and summary. Backend documents soft-delete for members; staff hard-delete behavior is not a member capability. |
| Upload dialog “Cancel” | Closes the dialog locally; no request. |
| Errors/success | Upload/replace/delete errors are shown by toast; success messages are shown after writes. Loading and empty-list states exist. |

**Document client integration caveat:** `documentsApi.upload` and `documentsApi.replace` currently use direct `fetch('/api/documents/...')` and read the legacy local-storage key `auth_token`, whereas the shared API client uses `welfare_auth` and `VITE_LARAVEL_API_BASE`. A replacement frontend should use one configured authenticated client for every request, while preserving the backend multipart contracts. Do not reproduce this split token/base behavior.

### Downloads — `/member/downloads`

| Control/data | Request and behavior |
|---|---|
| Member and contribution data | `GET /api/rest/v1/members?user_id=eq.{userId}&limit=1`; `GET /api/rest/v1/contributions?member_id=eq.{memberId}&order=year.desc&order=month.desc`. |
| Current financial statistics | `GET /api/members/stats?member_id={memberId}`. |
| Detailed statement source | `GET /api/members/stats/statement?member_id={memberId}`; backend returns `{ data: { member_id, statement, obligations, overview } }`. |
| “Preview” / “Download” statement | Preview builds HTML locally from fetched statement/contribution data. Download creates a local `.html` file; there is no PDF/export endpoint in this action. |
| Constitution “Read” / “Download” | Constitution HTML is embedded in the frontend source. Both actions are local; download produces an `.html` file. No API request supplies the document. |
| Approved meeting minutes | `GET /api/rest/v1/meeting_minutes?status=eq.approved&order=meeting_date.desc`; `GET /api/rest/v1/user_roles?user_id=eq.{userId}&select=role`. Client filters executive minutes according to role and `visible_to_members`. |
| Minute “Preview” | Opens a local dialog from already-fetched minutes; no detail API is called. |
| Minute “Download” | Generates an HTML file locally; no file download endpoint is called. |
| Member memos | `GET /api/rest/v1/memo_recipients?member_id=eq.{memberId}&order=created_at.desc&select=id,seen_at,downloaded_at,memos(*)`; client retains joined memos whose status is `sent`. Organization letterhead/settings are loaded through `settingsApi.list()` (the REST organization-settings table). |
| Memo “Preview” | Opens locally generated HTML. If unseen, `PATCH /api/rest/v1/memo_recipients?id=eq.{recipientId}` with `{ seen_at: ISO timestamp }`; then refetches memo recipients. |
| Memo “Download” | Generates and downloads HTML locally. If not previously downloaded, `PATCH /api/rest/v1/memo_recipients?id=eq.{recipientId}` with `{ downloaded_at: ISO timestamp, seen_at: existing-or-current timestamp }`; then refetches. |
| Loading / empty sections | Minutes and memo cards render only when there are records; no dedicated download-page loading/error screen was found in the inspected component. |

### Beneficiaries — `/member/beneficiaries`

| Control/data | Request and behavior |
|---|---|
| Beneficiary list | `GET /api/rest/v1/beneficiaries?member_id=eq.{effectiveMemberId}` through `beneficiariesApi.list`. |
| Request history | `GET /api/rest/v1/beneficiary_requests?member_id=eq.{effectiveMemberId}&order=created_at.desc`. |
| Tabs “My Beneficiaries” / “My Requests” | Local tab switch; request tab displays pending request count and statuses. |
| “Request to Add Beneficiary” | Opens dialog; inputs are full name, relationship (`spouse`, `child`, `parent`, `next_of_kin`), optional phone/ID except next-of-kin requires them, and reason. |
| Add submit | `POST /api/rest/v1/beneficiary_requests` JSON: `{ member_id, request_type: "add", beneficiary_name, beneficiary_relationship, beneficiary_phone|null, beneficiary_id_number|null, reason, status: "pending" }`. On success invalidates beneficiary-request query and closes/resets the form. |
| “Request Removal” on beneficiary | Opens removal dialog for that beneficiary. |
| Remove submit | `POST /api/rest/v1/beneficiary_requests` JSON: `{ member_id, request_type: "remove", beneficiary_id, reason, status: "pending" }`. This is a request, not direct deletion from the beneficiary table. |
| Impersonation | Add/remove submit functions throw and controls are disabled in view-as mode. List reads remain available. |
| Loading / empty / failures | Loading spinner and empty-state add action exist. Mutation failures show toast. Request-list loading is shown; no separate request-list error state was found. |

### News — `/member/news`

| Control/data | Request and behavior |
|---|---|
| News list | `GET /api/rest/v1/news?status=eq.active&order=created_at.desc` |
| Scheduled news cards | Client hides items whose scheduled/rescheduled date has passed. Shows title, content, created date, and scheduled date. |
| Empty state | “No announcements at this time.” |
| Card controls | No item click, mark-as-read, or read-state mutation is wired on this page. Unread tracking is separately handled by `NewsPopup`. |
| News popup overlay | Queries active news and `GET /api/rest/v1/news_read?user_id=eq.{userId}`; “Mark as Read & Next/Close” writes `POST /api/rest/v1/news_read` JSON `{ news_id, user_id }`. Closing/dismissing only closes locally. Navigation badge reads `GET /api/notifications/news/unread-count`. |

### Notifications — `/member/notifications`

| Control/data | Request and behavior |
|---|---|
| Member notifications | `GET /api/rest/v1/notifications?user_id=eq.{userId}&order=created_at.desc`. |
| Unread badge in member nav | `GET /api/notifications/unread-count`, polled every 10 seconds by `MemberLayout`. |
| Unread notification card | Check button calls `PATCH /api/rest/v1/notifications?id=eq.{notificationId}` JSON `{ is_read: true }` through `notificationsApi.markAsRead`. |
| Mutation success | Invalidates `["my-notifications"]`. |
| Empty state | “No notifications.” No explicit loading or error state is rendered by the page component. |

### Profile — `/member/profile`

| Control/data | Request and behavior |
|---|---|
| My profile | `GET /api/rest/v1/members?user_id=eq.{userId}&limit=1`; admin view-as mode queries `id=eq.{impersonatedMemberId}` instead. |
| Name and status message fields | Local inputs; phone and member ID are displayed read-only. |
| “Save Changes” | `PATCH /api/rest/v1/members?id=eq.{memberId}` JSON `{ name, status_message }`; success invalidates `["my-profile"]`. |
| Photo picker/camera button | Uploads multipart file to `POST /api/storage/v1/object/profile-images/{userId}/profile.{extension}` through `storageApi.upload`. Then `PATCH /api/rest/v1/members?id=eq.{memberId}` JSON `{ profile_picture_url }`, invalidates profile query. |
| “Change Password” then “Update” | Client checks matching password and minimum length 6; `POST /api/auth/v1/password` JSON `{ new_password }`. Success clears password fields and shows toast. |
| “Cancel” password change | Resets and hides local form; no request. |
| Impersonation | Profile reads the selected member; source disables no profile mutations based on impersonation. Do not assume those writes are safe; backend must enforce policy. |
| Loading/errors | Loading spinner; mutations show success/error toasts. Image upload reports upload and member-record update failures separately. |

### Penalty payment — `/member/pay-penalty`

| Control/data | Request and behavior |
|---|---|
| Find member | `GET /api/rest/v1/members?user_id=eq.{userId}` through `laravelApi.get('/members', { user_id })`. |
| Outstanding penalty list | `GET /api/rest/v1/penalties?member_id=eq.{memberId}&is_paid=false`. If no rows are returned, fallback reads `GET /api/members/stats?member_id={memberId}` and locally creates a derived overdue line when `penalty_outstanding > 0`. |
| Select-all / individual penalty selection | Local state only; updates the amount shown. |
| Phone field | Prefilled from member; local input. |
| “Pay Selected Penalties” / payment submit | Requires a member, selected penalty IDs, and phone. Calls STK push helper with total amount and selected IDs. |
| STK push | `POST /api/payments/stk-push` JSON `{ member_id, amount: ceil(total), phone, accountReference: "PENALTY-{memberId}", transactionDesc, paymentType: "penalty", penaltyIds }`. |
| Verify payment | Polls `GET /api/payments/status/{checkoutRequestId}` every 10 seconds, up to 60 times. Recognizes `confirmed`, `paid`, `verified`, and `partial`; failed/final/timeout states display an error. Success reloads the page. |
| Errors | Failed initial load/payment and status errors show toast/inline alert. No financial transaction was triggered during this source audit. |

### Funds drive — `/member/donate`

| Control/data | Request and behavior |
|---|---|
| Active funds drives | `GET /api/rest/v1/donation-campaigns` through `laravelApi.get('/donation-campaigns')`; query retries up to 3 times. The browser expects a list. |
| Member profile/phone | `GET /api/rest/v1/members?user_id=eq.{userId}&limit=1`. |
| Per-member verified progress | Frontend attempts `GET /api/rest/v1/donation-payment-records?member_id={memberId}&campaign_id={campaignId}`; client expects a list and sums rows with status `verified` or `paid`. **The table name is absent from the `routes/api.php` generic REST table allowlist, so this request is not a registered REST-table route as currently declared. Treat progress as blocked until the backend exposes a verified endpoint; do not invent a substitute.** |
| Select campaign | Local selection only; recalculates target, remaining, and progress. |
| “Pay full” / “Lipa Mdogo Mdogo” | Local mode selection; partial option appears only when `allow_partial` is true. |
| Amount / phone inputs | Local form state; amount validation is client-side. |
| “Contribute” payment action | `POST /api/payments/stk-push` JSON `{ member_id, amount: ceil(amount), phone, accountReference: "DONATION-{campaignId}", transactionDesc, paymentType: "donation", campaignId }`. |
| Payment status | Polls `GET /api/payments/status/{checkoutRequestId}`. On success reloads campaign progress and invalidates queries; terminal failure/final/timeout states show alerts. |
| Empty/error/back action | Empty state explains no active campaigns and “Go back to dashboard” navigates locally. |

### Executive role — `/member/executive/:roleName`

| Control/data | Request and behavior |
|---|---|
| Member profile and assigned roles | `GET /api/rest/v1/members?id=eq.{effectiveMemberId}&limit=1`; `GET /api/rest/v1/member_executive_roles?member_id=eq.{effectiveMemberId}`. |
| Badge for selected role | `GET /api/rest/v1/executive_badges?role_name=eq.{encodedRoleName}&limit=1`. |
| Access check | Client checks whether selected role appears in `member_executive_roles`; otherwise shows “Access Denied.” Backend must independently authorize any role-scoped data. |
| Role tabs/cards | Local role selection; routes are entered from dashboard cards. Back buttons navigate to the relevant member dashboard route. |
| Executive pages | Source shows role-specific executive content and read-only summaries; no separate action API was found in this component. |

### Withdrawal receipts — `/member/withdrawal-receipts`

This component is reused by other staff layouts. Its requests fetch all three withdrawal table collections and settings, and it does not visibly filter rows to the current member:

| Control/data | Request and behavior |
|---|---|
| Penalty, donation, and operational withdrawal rows | `GET /api/rest/v1/penalty-withdrawals`; `GET /api/rest/v1/donation-withdrawals`; `GET /api/rest/v1/operational-withdrawals`. |
| Signatures and organization details | `GET /api/rest/v1/signatory-signatures`; `GET /api/rest/v1/organization-settings`. |
| View receipt | Opens local dialog for a row. No separate receipt API request. |
| Print | Builds print HTML in a popup window and invokes browser print; no server export. |
| Download | Creates a PDF locally with `html2pdf.js`; no server export. |
| Authorization caveat | Route is wrapped by `auth.jwt`, `view.as.member`, and `throttle:api` via the REST table route, but the component's global table reads and lack of member filtering must be checked against the actual backend data-scope implementation before exposing this page to ordinary members. Navigation currently shows the link only when `role !== "member"`; route registration itself does not enforce that condition. |

## Shared member layout, badges, logout, and chat

| UI control/data | Request and behavior |
|---|---|
| Side navigation | Client links to the member routes listed above. For a user whose primary role is not `member`, it prepends a role-dashboard link. Role-based visibility is navigation behavior, not backend authorization. |
| Mobile bottom navigation | Displays the first four visible navigation items (normally Home, Events, Documents, Beneficiaries) plus Logout; selecting a link navigates locally. Other items remain in the sidebar/menu rather than the bottom bar. |
| Events badge | `GET /api/notifications/events/unseen-count?last_seen_at={localStorage member_last_seen_events_at}`, polled every 30 seconds. |
| News badge | `GET /api/notifications/news/unread-count`, polled every 30 seconds. |
| Notifications badge | `GET /api/notifications/unread-count`, polled every 10 seconds. |
| Mobile menu open/close, navigation drawer close | Local state and route navigation only. |
| Sign out | Calls `useAuth().signOut()`; session/logout API behavior is owned by the auth provider and must use the existing auth contract. |
| “Back to admin” in impersonation | Stops view-as context and navigates to `/admin`; no member-data mutation. |
| Floating chat open/close, drag, dark/light theme, back to conversations | Local UI state only. The chat panel is present in `MemberLayout`. |
| Chat unread bubble | Queries `conversation_participants`, then unread group/private `messages`; refreshes every 5 seconds while panel closed. |
| Presence / online indicators | `GET /api/rest/v1/user_presence?select=user_id,is_online,last_seen` through `useChatPresence`; hook refreshes every 10 seconds and locally treats recent presence timestamps as online. |
| Conversation list | Reads `conversation_participants`, `conversations`, other participant roles/profile; polls every 5 seconds. Search and All/Unread/Mentions tabs are local filters. Current component initializes unread/mention counts to zero and includes a hard-coded mention badge, so do not assume those filters/badges are backend-complete. |
| Create private/group conversation | Reads active member directory from `GET /api/rest/v1/members?is_active=eq.true&order=name&select=id,name,phone,user_id,profile_picture_url`; then creates `POST /api/rest/v1/conversations` `{ type, name, created_by }`, followed by `POST /api/rest/v1/conversation_participants` with participant rows. |
| Load chat messages | `GET /api/rest/v1/messages?order=created_at.asc&limit=200&conversation_id=is.null` for welfare group or `conversation_id=eq.{id}` for private chat. Additional reads fetch participant role/member profiles, reply messages, and `message_reactions`. |
| Send message | `POST /api/rest/v1/messages` JSON `{ user_id, member_id, content, conversation_id|null, reply_to_id|null, status: "sent" }`; client then calls Firebase push helper. |
| Delivery/read state | `PATCH /api/rest/v1/messages?id=in.(...)` with `{ status: "delivered" }` or `{ status: "read" }` based on received messages. |
| Edit/delete message | `PATCH /api/rest/v1/messages?id=eq.{id}` updates `content` and `edited_at`; delete is a soft UI update to text `"🚫 This message was deleted"` and `status: "deleted"`. Edit window is 15 minutes in UI. |
| Reply / cancel reply | Reply action selects the source message locally; cancel button clears the reply preview. The send payload includes `reply_to_id`; cancel sends no request. |
| Edit / save / cancel | Available for the current user's messages for 15 minutes. Save sends `PATCH /api/rest/v1/messages?id=eq.{id}` with `{ content, edited_at }`; cancel only resets local form state. |
| Delete message | Own-message overflow menu changes the message content/status via `PATCH /api/rest/v1/messages?id=eq.{id}` with `{ content: "🚫 This message was deleted", status: "deleted" }`; it does not issue HTTP DELETE. |
| Quick reactions / existing reaction pills | Loads `GET /api/rest/v1/message_reactions?message_id=in.(...)`; when toggled, first looks up `GET /api/rest/v1/message_reactions?message_id=eq.{messageId}&user_id=eq.{userId}&emoji=eq.{emoji}`; removes with `DELETE /api/rest/v1/message_reactions?id=eq.{reactionId}` or adds with `POST /api/rest/v1/message_reactions` `{ message_id, user_id, emoji }`. |
| Emoji picker | Adds a selected emoji to the composer text locally; no API call until the message is sent. |
| Attachment button | Rendered as a button with no click handler in the inspected source. It is not a working upload feature and must not be represented as one. |
| Presence | `usePresence` and `useChatPresence` hooks operate in the shared layout. Presence writes are owned by `usePresence`; inspect that hook before integrating client heartbeat behavior. |
| Fund drive reminder overlay | Reads the logged-in member via `GET /api/rest/v1/members?user_id=eq.{userId}`, campaigns via `GET /api/rest/v1/donation-campaigns`, and attempts per-campaign records via `GET /api/rest/v1/donation-payment-records?member_id={memberId}&campaign_id={campaignId}`. The last table is absent from the generic REST table allowlist, so the reminder's progress query is not registered as declared. Shows a daily reminder for eligible partial-payment campaigns only if those reads succeed. “Pay any amount” navigates to `/member/donate`; “Remind me tomorrow” only closes it and records a daily local-storage suppression key. Disabled in view-as-member mode. |
| Browser notification permission | `useNotifications` may request browser `Notification` permission. No Laravel API call was identified in that hook. |

## Relevant registered Laravel routes

| Route | Middleware / implementation |
|---|---|
| `GET /api/members/stats` | `auth.jwt`, `view.as.member`, `throttle:api` → `MemberStatsController@index`; handles own-member scope and authoritative view-as target. |
| `GET /api/members/stats/statement` | Same group → `MemberStatsController@statement`; returns `member_id`, `statement`, `obligations`, and `overview` in `data`. |
| `POST /api/payments/stk-push` | `auth.jwt`, `view.as.member`, `throttle:api` → `PaymentController@stkPush`. |
| `GET /api/payments/status/{checkoutRequestId}` | `auth.jwt`, `throttle:api` → `PaymentController@status`. |
| `GET /api/notifications/{unread-count,news/unread-count,events/unseen-count}` | `auth.jwt`, `view.as.member`, `throttle:api` → `NotificationController`; each returns `{ count }`. |
| `/api/documents/...` | `auth.jwt`, `view.as.member`, `throttle:api` → `DocumentController` member document lifecycle routes. |
| `/api/storage/v1/object/{bucket}/{path}` | Upload/delete/signed URL require JWT and API throttle; download route is separately registered. |
| `/api/rest/v1/{table}` | `auth.jwt`, `view.as.member`, `throttle:api` → `RestApiController@handle`, restricted by route table allowlist. |

These are source-registered contracts. Do not report them as successfully runtime-tested merely because their route declarations exist.

## Frontend implementation requirements for a replacement

1. Preserve all twelve member URLs, the role-aware member layout, mobile navigation, and admin view-as-member URL support.
2. Use one configurable authenticated Laravel client for JSON and multipart requests; send the logged-in JWT and the authorized view-as context consistently. Never store credentials or infer permissions in the UI.
3. Keep the explicit `/api/payments`, `/api/members/stats`, `/api/documents`, `/api/notifications`, `/api/storage`, and generic `/api/rest/v1` path conventions distinct; do not accidentally prefix a path twice.
4. Use TanStack Query for reads; use targeted invalidation after writes: payment success refreshes member, contribution, penalty/stat data; document writes refresh document list/summary; beneficiary writes refresh request/list data; notification/news reads refresh relevant count/list queries.
5. Preserve payment lifecycle states and avoid claiming a transaction succeeded until the status endpoint confirms it. Never include a real-payment test action in QA.
6. Generate statement/constitution/minutes/memo/receipt exports only as the current frontend does unless a verified server-side file endpoint is found. Label the resulting file type honestly.
7. Match current fields/payloads and backend envelopes shown above. Do not invent undocumented fields, routes, role names, data, or APIs.
8. Provide explicit loading, empty, error, pending, success, and retry behavior for each query/mutation. Some current pages omit these states; the replacement should make errors visible rather than render a success-shaped empty screen.
9. Keep all member-scoped records scoped on the backend. Confirm member ownership and staff-role policy for generic REST resources, receipts, profile writes, downloads, and view-as before releasing the replacement.
10. Do not add behavior to decorative cards or items that have no handler in the current frontend. Treat this matrix as an inventory, not approval to reproduce insecure or inconsistent behavior verbatim.

## Verification limits and notable follow-up checks

- This matrix was built by inspecting frontend source and Laravel route/controller source. No member login was used and no payment, upload, beneficiary request, profile mutation, chat send, or other write was executed.
- Generic REST table response schemas are determined by `RestApiController` and related serializer/query behavior; where the page expects a record shape, this matrix describes only the fields actually consumed by that page. Verify exact deployed response shapes before implementation.
- **Backend route gap:** the donation screens call `/api/rest/v1/donation-payment-records`, but the table constraint in `routes/api.php` does not include `donation-payment-records` or `donation_payment_records`. Although `PolicyService` contains a `donation_payment_records` policy, that does not register the missing HTTP table route. Member campaign-progress/reminder reads therefore need a backend contract decision before a working replacement can truthfully implement them.
- Audit ordinary-member authorization and ownership for `withdrawal-receipts`, `beneficiaries`, `beneficiary_requests`, `news_read`, memos, chat tables, and member profile updates before treating frontend route visibility as sufficient access control.
- Confirm whether `MemberEvents` and `EventPopup` should share last-seen state: the popup uses local storage, while the list itself is read-only.
- Confirm document upload constraints and actual file response headers/storage URLs against deployment configuration. The member page expects `download_url` in the document representation.
- Confirm payment provider configuration, validation, webhook/callback handling, idempotency, and status ownership only through safe non-financial test infrastructure; this source audit does not establish those runtime properties.

## Wallet funding (STK) and withdrawals (B2C)

This section covers the wallet payment and withdrawal paths found in the frontend and Laravel source. The wallet type changes the backing tables and accounting destination; it does not define a separate provider engine. The current implementation uses three wallet-specific withdrawal tables behind `WithdrawalApprovalService`, rather than one shared withdrawal table.

### Wallet and withdrawal request matrix

| Wallet/source | Frontend entry point | Withdrawal request and backing record | Approval rows / current behavior |
|---|---|---|---|
| Penalty wallet | Admin Penalty Wallet | `POST /api/rest/v1/withdrawals` (virtual alias) creates `penalty_withdrawals`; the authenticated identity must have a member record. | The backend creates `withdrawal_signatories` for `secretary` and `chairperson` in the same DB transaction. The UI says three approvers, but the backend chain is two stages. |
| Donation/fund-drive wallet | Admin Donation Wallet | `POST /api/rest/v1/donation_withdrawals` creates `donation_withdrawals`; generic REST forces initial status/stage and creates the two approval rows. | The UI then separately tries to insert chairperson/secretary/treasurer rows. Generic REST rejects all signatory inserts with `409 canonical_approval_endpoint_required`. The withdrawal has already been committed, so the UI can report failure while leaving a pending withdrawal behind. |
| Operational wallet | Treasurer Operational Wallet | `POST /api/rest/v1/operational_withdrawals` creates `operational_withdrawals`; generic REST creates the two approval rows. | The UI then posts to `/api/rest/v1/operational-withdrawal-signatories/bulk`; no such bulk route is registered. The extra request fails after the withdrawal was created. |
| Payout sourced from penalty, donation, or operational wallet | Treasurer Expenses & Payouts → Payouts | `POST /api/admin/payouts` creates a `payouts` row, the selected wallet withdrawal row, and approval rows in one DB transaction. | This is the consistent request-creation path. It inserts the same secretary/chairperson chain and ties `payouts.payment_reference` to the withdrawal ID. Final payout status becomes paid only after provider success. |
| Expense sourced from penalty, donation, or operational wallet | Treasurer Expenses & Payouts → Expenses | Creates a wallet withdrawal through generic REST, then attempts a separate signatory insert, then creates a linked `expenses` row. | The separate signatory insert is rejected; because it runs before the expense insert, the request can leave a withdrawal with backend-created approvals but no linked expense. For cash/bank/cheque methods, the canonical approval service settles the linked expense without B2C if a linked expense exists. |

Common request rules:

- Generic REST normalizes withdrawal create requests to `status=pending`, `approval_stage=secretary`, and the authenticated `requested_by`; it strips client-supplied provider/receipt/completion fields.
- Generic REST auto-creates the `secretary` and `chairperson` signatory rows with the withdrawal. It blocks generic signatory insert, withdrawal update, and withdrawal delete; approval updates must use the dedicated `/{signatory_table}/update` flow.
- The ordinary wallet form balance check is only a UI check. Available balance is authoritatively checked and reserved under a wallet row lock immediately before provider submission.
- The payout controller checks member eligibility and configured payout rules, but available wallet balance is still enforced later at reservation time.

### Approval and signature matrix

| Step | Server implementation | Enforced behavior |
|---|---|---|
| Approval-chain definition | `WithdrawalApprovalService::APPROVAL_CHAIN` | Exactly `secretary` → `chairperson`; treasurer is not an approval stage. |
| Approve/reject request | `POST /api/rest/v1/{withdrawal_signatories\|donation_withdrawal_signatories\|operational_withdrawal_signatories}/update` | `RestApiController` routes to `recordApproval` or `recordRejection`. The caller role must match the current stage; rejection requires a non-empty reason. |
| Identity and self-approval | `WithdrawalApprovalService` + `PolicyService` | Role is checked server-side. Non-admin requesters cannot approve their own withdrawal. Admin/super-admin may be mapped to the secretary stage by the service. |
| Signature upload | Upload image to `/api/storage/v1/object/signatory-signatures/{path}`, then `POST /api/signatures` | Officer may store their own signature for a role they hold; admin/super-admin may upload on behalf of another user. Backend resolves and validates the stored image before saving the signature row. |
| Signature validation | `WithdrawalSignatureService::resolveImage` | Approval requires a readable uploaded PNG/JPEG/WebP image in the recognized private signature storage path; image size is limited to 2 MiB. Missing/unreadable signature returns a conflict and prevents that approval. |
| Approval evidence | `WithdrawalApprovalService::recordApproval` | Records approver user ID, role, approval status/time and signature URL on the matching signatory row; logs an audit event. |
| Final approval | `advanceApprovalStage` then `triggerB2CWithdrawal` | First approval advances to chairperson. Once both are approved, withdrawal becomes approved and the same canonical service considers submission. Approval alone is not payment completion. |

The admin wallet forms and some prompts still describe a three-person chain. That text is inconsistent with the server chain and should not be treated as policy.

### Canonical B2C submission and wallet-ledger updates

| Lifecycle stage | Status / action | Wallet effect |
|---|---|---|
| Request | `pending`, approval stage `secretary` | No wallet mutation. |
| Approval/signatures incomplete | Signatory row pending, or request `awaiting_signature` when signature evidence is unavailable | No reservation or debit. |
| Both approvals complete | Withdrawal `approved` | In non-production submission mode, `WithdrawalApprovalService::triggerB2CWithdrawal` checks idempotency and required signatures, then locks/reserves funds. |
| Reservation | Withdrawal `submitted`; a `wallet_transactions` row with direction `reserve` is pending | `reserved_balance` increases and `available_balance` decreases. `total_balance` is unchanged. A locked wallet balance check prevents overspending. |
| Provider accepted / outcome unknown | Withdrawal and transfer `processing` (or submitted) | Reservation remains held. Submission is not success. A provider timeout is not a definitive failure. |
| Provider success | Transfer status `successful`; `applyProviderOutcome` settles the pending reservation | One `debit` wallet transaction is created; `total_balance` decreases, `total_withdrawn` increases, and the reservation is removed. Withdrawal becomes `completed`; receipt generation is attempted. Linked payout becomes `paid`. |
| Provider failure | Transfer status `failed`; `applyProviderOutcome` releases the reservation | One `release` transaction; balance is not permanently deducted. Withdrawal becomes `failed`; linked payout is not marked paid. |
| Provider reversal | Transfer status `reversed`; `applyProviderOutcome` releases an outstanding reservation or reverses an existing settlement | One release or one reversal credit, depending on whether settlement already occurred. If a matching reservation/settlement cannot be safely found, withdrawal becomes `reconciliation_required`. |
| Duplicate callback/status result | Same provider outcome is re-applied | Transfer/withdrawal row locks and reservation/settlement/reversal lookups make already-applied ledger effects no-ops. |

Canonical application paths:

- Approval-triggered submission: `WithdrawalApprovalService` → `CoopBankTransferService::sendToMpesa`.
- Staff compatibility endpoints `POST /api/functions/v1/b2c-transfer`, `POST /api/payments/b2c`, and `POST /api/banking/coop/transfers/b2c` validate the submitted amount/phone against the stored withdrawal and delegate back to `WithdrawalApprovalService`; they do not define wallet-specific provider engines.
- `ExecuteWithdrawalB2CJob` is intentionally disabled; it must not submit a direct transfer.
- B2C callback: `POST /api/banking/coop/callback/transfer` is behind `verify.coop.callback`, updates the transfer, then calls `applyProviderOutcome`.
- Scheduled reconciliation: `coop:reconcile-transfers` polls outstanding non-STK transfers every 15 minutes when Coop real calls are enabled. The callback/status result, not the initial submission response, determines completion.
- Completed server receipt: withdrawal receipt HTML is rendered from the withdrawal and approval records; approved signatures are resolved to image data before render. The member receipt page also has a separate local print/PDF implementation, so that browser-generated file is not the same artifact as the server receipt.

**Production safety note:** the deployed code-level B2C guard blocks B2C submission while `app.env=production`, before funds are reserved, and the provider-transfer service also fails closed in production. The release smoke check previously observed that production's cached Coop `enable_real_calls` value and provider credentials were enabled; the guard prevented B2C submission without changing `.env`. This does not disable STK calls, bank enquiries, or other Coop operations. Confirm the current deployed version/config before treating this source-level guard as current production state.

### STK push matrix (wallet inflows and contribution payments)

All listed payment pushes use `PaymentController@stkPush` → `PaymentService::initiateStkPush` → `CoopBankTransactionService::initiateStkPush`. The service creates a pending canonical `payments` record and a legacy type-specific pending record before provider initiation. If Coop is configured for real calls, the service calls the provider; otherwise it creates a mock processing transfer. Do not run a push as a smoke test.

| Payment type | Frontend entry point and request | Pending record / canonical type | On authoritative success |
|---|---|---|---|
| Monthly contribution | Member Dashboard; `POST /api/payments/stk-push` with `paymentType=monthly_contribution`, member ID, amount, phone | `payments` + `contributions` pending/checkout reference | `PaymentEngine` confirms canonical payment and `ContributionAllocationService` allocates it across monthly obligations. Not a wallet credit. |
| Penalty payment | Member Pay Penalty; same endpoint with `paymentType=penalty` and selected `penaltyIds` | `payments` + `penalty_payment_records` pending | `PaymentLifecycleService` verifies the payment, updates penalty paid state, and credits the penalty wallet once via `WalletService`. |
| Donation/fund-drive payment | Member Donate; same endpoint with `paymentType=donation` and `campaignId` | `payments` + `donation_payment_records` pending | Canonical confirmation posts campaign progress and credits the donation wallet through `PaymentLifecycleService`/`WalletService`. |
| Operational wallet top-up | Treasurer Operational Wallet calls `POST /api/functions/v1/operational-topup`; controller delegates to the same payment/STK service with `paymentType=operational` and system member reference. | `payments` + `operational_payment_records` pending | Canonical confirmation credits the operational wallet once. Frontend currently waits for its periodic wallet refresh rather than explicitly refreshing immediately on accepted STK. |

Status and settlement:

- Frontends poll `GET /api/payments/status/{checkoutRequestId}`; member contribution/penalty/donation UIs refresh relevant data after a success response.
- `PaymentService::getStatus` reads canonical `payments` first, verifies member ownership for non-staff callers, polls provider status for nonterminal records, and calls canonical settlement only for confirmed payments. It retains legacy record lookup as compatibility fallback for older checkout IDs.
- `POST /api/payments/stk-callback` is deliberately ignored; status polling and reconciliation are authoritative.
- Successful/failed STK reconciliation is also processed by `ReconcilePendingStkTransaction` and the scheduled `coop:reconcile-transfers` scanner. Repeated confirmed statuses route through idempotent canonical payment posting.
- Wallet credit affects `total_balance`, `total_received`, `available_balance`, and one credit transaction. Monthly contributions allocate to obligations instead of a wallet.

Other STK-like path: member registration payment currently creates a `RegistrationFee` and calls `MockPaymentProvider::simulateStkPush`; it is not a wallet inflow and is not the shared Coop STK provider path.

### Wallet balance/read refresh matrix

| Frontend surface | Current balance/history read | Refresh behavior found |
|---|---|---|
| Admin Penalty Wallet | Reads penalty wallet, withdrawal rows, penalty payment rows, staff signatures, and member stats through REST/client APIs. | Initial load and withdrawal-list reload; balance is not explicitly refreshed after submitting a withdrawal. UI shows `total_balance` as available even when reserved funds exist. |
| Admin Donation Wallet | Reads donation wallet, withdrawal rows, signature rows, and verified recent donations from `/api/wallets/donation/recent-payments`. | Polls on interval/focus for wallet data; after a withdrawal request, reloads withdrawals, not the wallet balance. |
| Treasurer Operational Wallet | `GET /api/wallets/operational/balance`; STK top-up; withdrawal request. | Balance polls every 60 seconds; accepted top-up/withdrawal does not immediately invalidate/refetch it. |
| Shared wallet API | `GET /api/wallets/{penalty\|donation\|operational}/balance` and `/transactions`; mutation routes `POST /credit` and `/debit` are staff-only. | Backend computes available balance as total minus reserved. UI pages do not all use this shared read contract. |
| Approval/receipt pages | Read all three withdrawal tables and signatories; receipts use local rendering/download in the shared page. | Approval view reloads withdrawal/signature data after an action; current receipt list is not a canonical member-scoped receipt API. |

### Integration gaps to resolve before relying on every wallet UI

1. Donation Wallet and Expenses & Payouts make generic signatory insert calls that the backend intentionally rejects. Operational Wallet calls an unregistered `/bulk` path. These flows can report failure after a withdrawal row has already been committed; use the existing auto-created two-stage chain and remove the extra writes, or switch to the transactional `/api/admin/payouts` request flow when appropriate.
2. Penalty, donation, and operational wallet screens tell users three signatures are required, while backend approval policy requires secretary then chairperson. Align copy with server policy; do not add a treasurer approval stage in the frontend.
3. The generic UI balance checks display `total_balance`, not available balance after reservations. Use `/api/wallets/{type}/balance` and display `available_balance` separately.
4. Refresh wallet balance, withdrawal status/history, payout/expense state, and receipt availability after relevant backend confirmations. A request being accepted or approved is not provider success.
5. The `frontend/src/lib/b2c.ts` helper polls older `/banking/coop/transactions/status` paths, but no inspected component calls it. Keep it out of the live approval path unless its callers/routes are deliberately reinstated and aligned with canonical status handling.
6. Production B2C and STK safety are separate concerns: the code-level production B2C guard does not prevent `CoopBankTransactionService` STK submission when the global Coop flag and credentials are enabled. Disable provider configuration operationally before any non-read-only payment verification; never use live money as a test.
