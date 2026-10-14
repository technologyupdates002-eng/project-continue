# KHWWC Treasurer Frontend/API Matrix

## Scope and interpretation

This is the Treasurer-facing frontend/API inventory, not an authorization or backend security sign-off. It covers every `/treasurer` route registered in `frontend/src/App.tsx`, the component rendered there, and the HTTP requests/actions visible in those components. Admin components reused by Treasurer routes are included. The payout walkthrough below follows the corresponding Laravel implementation for lifecycle context; it is source inspection, not runtime/provider verification.

The frontend clients resolve paths as follows:

- `apiRequest('/rest/v1/...')` calls `/api/rest/v1/...`.
- `apiRequest('/api/...')` calls that `/api/...` path directly.
- `apiRequest('/banking/...')`, `apiRequest('/treasurer/...')`, and `laravelApi` paths are prefixed by the configured API base (`/api` by default).
- `laravelApi` paths that are not a special API prefix are translated to `/rest/v1/...`.
- `storageApi.upload` sends a multipart `POST`; `storageApi.getPublicUrl` only constructs a URL locally.
- PDF/Excel generation and filters are client-side unless noted otherwise.

## Complete Treasurer route inventory

| Frontend URL | Rendered page/component | Page purpose |
|---|---|---|
| `/treasurer` | `TreasurerDashboard` | Financial summary, alerts, activity, approvals, downloadable system report |
| `/treasurer/withdrawal-approvals` | `TreasurerWithdrawalApprovals` → reuses `admin/WithdrawalApproval` | View/sign/reject penalty, donation, and operational withdrawals |
| `/treasurer/penalty-wallet` | `TreasurerPenaltyWallet` → reuses `admin/PenaltyWallet` | Penalty wallet balance, payments, member balances, withdrawal request |
| `/treasurer/donation-wallet` | `TreasurerDonationWallet` → reuses `admin/DonationWallet` | Donation wallet, verified donations, withdrawal request |
| `/treasurer/donation-campaigns` | `DonationCampaigns` | Create, edit, list, and delete funds drives |
| `/treasurer/contributions` | `TreasurerContributions` | Contribution/member financial status and local assistant |
| `/treasurer/contribution-adjustments` | `ContributionAdjustments` | Add/reduce internal contribution reconciliation adjustment |
| `/treasurer/expenses` | `ExpensesPayouts` | Create expense and member payout requests; inspect eligibility/history |
| `/treasurer/memos` | `MemoHistory` | Search/filter, preview, download, resend, and delete memos |
| `/treasurer/memos/create` | `CreateMemo` | Create, save, send, notify, or share a memo |
| `/treasurer/memos/:id/edit` | `CreateMemo` | Load and edit a memo draft |
| `/treasurer/documents` | `TreasurerDocuments` | Create, preview, and download official letters |
| `/treasurer/reports` | `TreasurerReports` | Generate and download monthly/quarterly/annual reports |
| `/treasurer/operational-wallet` | `OperationalWallet` | View operational wallet, request STK top-up, request withdrawal |
| `/treasurer/wallet-reports` | `WalletReports` | Summarize wallet transactions; export PDF/Excel |
| `/treasurer/settings` | `TreasurerSettings` | Edit organization letterhead/contact/payout rules; upload logo/signature/stamp |
| `/treasurer/bank-sync` | `BankSync` | Sync bank transactions by date; inspect transactions/unmatched items |
| `/treasurer/bank-statement` | `TreasurerBankStatement` | Search/filter/paginate bank transactions, sync, reconcile |
| `/treasurer/bank-statement-import` | `BankStatementImport` | Parse a spreadsheet locally and import statement rows in batches |
| `/treasurer/book-balance-import` | `BookBalanceImport` | Parse and upsert book balance spreadsheet rows |
| `/treasurer/book-balance` | `BookBalance` | View bank balance overview, deposits, withdrawals, transactions |

## Page/action → API matrix

`GET`, `POST`, `PATCH`, and `DELETE` below are the actual frontend request methods. Query strings shown are the parameter shapes used by the page; values are filled in at runtime.

### Dashboard — `/treasurer`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Main bank/financial summary | GET | `/api/banking/coop/dashboard/financial` | Reads bank provider and app summary data |
| Canonical contribution/member summary | GET | `/api/members/stats/summary` | Used by metrics and overdue/penalty alerts |
| Dashboard chart | GET | `/api/treasurer/chart-data` | Six-month income/expense chart |
| Recent contribution/payment activity | GET | `/api/treasurer/recent-activity` | Recent activity list |
| Pending penalty and donation withdrawals | GET | `/api/rest/v1/penalty_withdrawals?...withdrawal_signatories(...)`; `/api/rest/v1/donation_withdrawals?...donation_withdrawal_signatories(...)` | Loads withdrawal details and signatory state |
| Signature lookup | GET | `/api/rest/v1/signatory_signatures` | Resolves signatory signature images/identity |
| Pending counts | GET | `/api/rest/v1/penalty_withdrawals?select=id&status=eq.pending`; same pattern for `donation_withdrawals`, `operational_withdrawals`, and `payouts` | Builds pending-work alert count |
| Approve/reject a withdrawal | POST | `/api/rest/v1/{withdrawal_signatories\|donation_withdrawal_signatories}/update` | Posts withdrawal ID, Treasurer signatory role, decision, and optional rejection reason |
| Build the dashboard system report | GET | `/api/rest/v1/penalty_wallet`, `/donation_wallet`, `/operational_wallet`, `/contributions`, `/penalties`, `/penalty_withdrawals`, `/donation_withdrawals`, `/operational_withdrawals`, `/expenses`, `/organization_settings`, `/members` | Fetches report data; generated report HTML/PDF is client-side |
| Dashboard AI prompt | None | None | Mock response generated locally; no AI API call from this action |

### Withdrawal approvals — `/treasurer/withdrawal-approvals`

Rendered through `pages/admin/WithdrawalApproval.tsx`.

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load role assignments | GET | `/api/rest/v1/user-roles` | Determines the current signatory role |
| Load requests | GET | `/api/rest/v1/penalty-withdrawals`, `/donation-withdrawals`, `/operational-withdrawals` | Combines all three withdrawal types |
| Load signature data | GET | `/api/rest/v1/signatory-signatures` | Adds signature information to request rows |
| Approve/reject selected request | POST | `/api/rest/v1/{withdrawal_signatories\|donation_withdrawal_signatories\|operational_withdrawal_signatories}/update` | Sends withdrawal ID, signatory role, status, and optional rejection reason |
| Refresh after decision | GET | Same three withdrawal endpoints and `/api/rest/v1/signatory-signatures` | Reloads approval state |

### Penalty wallet — `/treasurer/penalty-wallet`

Rendered through `pages/admin/PenaltyWallet.tsx`.

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load organization settings/letterhead | GET | `/api/rest/v1/organization-settings` | `laravelApi` alias for generic REST table access |
| Load penalty wallet totals | GET | `/api/rest/v1/penalty-wallet` | Wallet balance/received/withdrawn figures |
| Load penalty withdrawals and signatories | GET | `/api/rest/v1/penalty-withdrawals` | Withdrawal list |
| Load signatures | GET | `/api/rest/v1/signatory-signatures` | Signature display/receipt support |
| Load penalty payment records | GET | `/api/rest/v1/penalty-payment-records` | Payment listing |
| Load member names | GET | `/api/rest/v1/members?select=id,name,member_id` | Resolves member names |
| Load member penalty/contribution stats | GET | `/api/members/stats` | Member-level outstanding penalty balances |
| Submit withdrawal request | POST | `/api/rest/v1/withdrawals` | Creates a penalty withdrawal through the virtual REST table |
| Refresh withdrawal list | GET | `/api/rest/v1/penalty-withdrawals` | Updates the page after request submission |
| Receipt/report presentation | None | None | Rendering/printing is client-side; no additional request identified |

### Donation wallet — `/treasurer/donation-wallet`

Rendered through `pages/admin/DonationWallet.tsx`.

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load organization settings | GET | `/api/rest/v1/organization_settings` | Letterhead/receipt context |
| Load donation wallet totals | GET | `/api/rest/v1/donation_wallet` | Balance/received/withdrawn |
| Load donation withdrawals | GET | `/api/rest/v1/donation_withdrawals?order=created_at.desc` | Request list |
| Load signatory signatures | GET | `/api/rest/v1/signatory_signatures` | Signature display/receipt support |
| Load verified recent donations | GET | `/api/wallets/donation/recent-payments?limit=20` | Recent payment list |
| Submit withdrawal request | POST | `/api/rest/v1/donation_withdrawals` | Creates pending withdrawal with amount/reason/requester/phone |
| Create signatory rows | POST | `/api/rest/v1/donation_withdrawal_signatories` | Creates pending chairperson, secretary, and treasurer approvals |
| Refresh after submission | GET | `/api/rest/v1/donation_withdrawals?order=created_at.desc` | Reloads withdrawal rows |
| Receipt/preview/download | None | None | Generated or opened client-side from returned data |

### Donation campaigns — `/treasurer/donation-campaigns`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load campaigns | GET | `/api/rest/v1/donation_campaigns?order=created_at.desc` | Campaign list |
| Count active members | GET | `/api/rest/v1/members?select=id&is_active=eq.true` | Computes shared per-member campaign amount |
| Create campaign | POST | `/api/rest/v1/donation_campaigns` | Sends title, description, goal, amount, partial-payment flag, active flag, creator |
| Edit campaign | PATCH | `/api/rest/v1/donation_campaigns?id=eq.{campaignId}` | Updates campaign fields |
| Delete campaign | DELETE | `/api/rest/v1/donation_campaigns?id=eq.{campaignId}` | Deletes after browser confirmation |
| Refresh list | GET | Same campaign and active-member endpoints | Reloads campaign data after mutation |

### Contributions — `/treasurer/contributions`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load member contribution/penalty stats | GET | `/api/members/stats` | Supplies member balance/status table |
| Search, status/month filters, member details | None | None | Filters/selection are client-side |
| AI assistant prompt | None | None | Generates a mock response locally; no API call |

### Contribution adjustments — `/treasurer/contribution-adjustments`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load member overviews and adjustments | GET | `/api/treasurer/contribution-adjustments` | Supplies selectable members, canonical totals, adjustment history |
| Record add/reduce adjustment | POST | `/api/treasurer/contribution-adjustments` | Sends `member_id`, `direction`, `amount`, `category`, and `reason`; then refetches |

### Expenses and payouts — `/treasurer/expenses`

For the end-to-end wallet connections, API request matrix, authorization boundaries, and settlement outcomes, see [KHWWC Payout, Expense, and Wallet Functional/API Matrix](./KHWWC_PAYOUT_EXPENSE_WALLET_FUNCTIONAL_MATRIX.md).

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load payout rules | GET | `/api/rest/v1/organization_settings` | Determines configured eligible payout amounts |
| Load eligible members by payout type | GET | `/api/admin/payouts/eligible?payout_type={type}` | Eligibility list for selected payout type/relation |
| Load expense history | GET | `/api/rest/v1/expenses` | Expense list |
| Load payout request history | GET | `/api/admin/payouts` | Payout list |
| Create payout request | POST | `/api/admin/payouts` | Sends `member_id`, amount, normalized payout type/relation, reason, phone, wallet type; response creates linked payout + withdrawal + approval rows |
| Create expense withdrawal | GET then POST | GET `/api/rest/v1/organization_settings`; POST `/api/rest/v1/{operational_withdrawals\|donation_withdrawals\|penalty_withdrawals}` | Reads signatory roles, then creates a pending withdrawal for the selected wallet |
| Create expense signatory rows | POST | `/api/rest/v1/{operational_withdrawal_signatories\|donation_withdrawal_signatories\|withdrawal_signatories}` | Creates pending approval rows |
| Save expense row | POST | `/api/rest/v1/expenses` | Stores expense details after withdrawal/signatory creation |
| Payout/expense dialogs and filters | None | None | Form validation and display are local |

#### Payout lifecycle — request through settlement

The payout types in this screen are wedding, death (member/spouse/child/parent), retirement, and emergency. A payout is a member benefit, not just a generic expense: the backend links a `payouts` row to one wallet withdrawal using `payment_reference`.

| Stage | Request or backend transition | What happens |
|---|---|---|
| Configure payout rules | Treasurer settings PATCH to `/api/rest/v1/organization_settings?id=eq.settings?.id` | The page displays configurable amounts for each payout type. **Current source uses the literal `settings?.id` path**, so this save request does not target the loaded settings row as intended. |
| Find eligible members | GET `/api/admin/payouts/eligible?payout_type={wedding\|death_member\|death_spouse\|death_child\|death_parent\|retirement\|emergency}` | Backend returns active members with the configured amount, excluding members with a `paid` payout of that exact type. |
| Submit request | POST `/api/admin/payouts` | Backend checks active member, configured amount, amount > 0 and not over eligible amount, and blocks an existing pending/approved/paid payout for that member/type. In one DB transaction, it inserts a pending `payouts` row, a pending withdrawal in the selected wallet table, and pending secretary + chairperson signatory rows. Operational-wallet withdrawals are additionally tagged as payout and include the member recipient name. |
| Review and sign | POST `/api/{withdrawal_signatories\|donation_withdrawal_signatories\|operational_withdrawal_signatories}/update` | The shared `/treasurer/withdrawal-approvals` screen lists the linked wallet withdrawal. Secretary is the first stage and chairperson the second. Each approver must have the matching role and uploaded signature; requester self-approval is blocked for non-admins. Rejection requires a reason and marks the withdrawal rejected. |
| Submit B2C after final approval | Backend `WithdrawalApprovalService::triggerB2CWithdrawal` (automatic; not a separate Treasurer button/request) | After approval, backend checks both approvals/signatures and attempts to reserve funds in the selected wallet. The transfer path uses the Coop Bank B2C service. Production safety mode currently blocks provider submission; non-production may use configured provider/mock behavior. |
| Receive provider outcome | POST callback `/api/banking/coop/callback/transfer` | Verified provider callback is applied to the transfer and wallet ledger. Confirmed success settles the reservation and completes the withdrawal; confirmed failure releases the reservation and marks the withdrawal failed. |
| Complete payout | Backend `MemberPayoutService::markWithdrawalCompleted` | On confirmed success, payout status becomes `paid`, `paid_at` is set, and a member payout SMS is attempted. The payout list overlays its displayed status with the linked withdrawal status until the payout row is marked paid. |
| Inspect history | GET `/api/admin/payouts` | Returns member identity, wallet type, phone/reason, signatory rows, provider reference and a `PAY-` reference derived from the payout ID. |

The request body includes `phone_number`, but the payout controller uses the member's saved phone number when it creates the withdrawal. The frontend validates the displayed phone locally; the backend's payout create validation only treats the request phone as an optional bounded string. Confirm the saved member number is current before approval.

The eligibility endpoint excludes paid payouts only, while create rejects existing pending and approved payouts too. A member with a request already in progress can therefore remain in the eligible-member response; creating a duplicate is rejected by the POST endpoint.

The separate backend endpoints `/api/banking/coop/transfers/b2c` and `/api/b2c-transfer` are not called by this Treasurer page's payout action; the regular path invokes the withdrawal service automatically after final approval. The disabled legacy `ExecuteWithdrawalB2CJob` is not the payout execution path.

### Memo history — `/treasurer/memos`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Resolve current Treasurer name | GET | `/api/rest/v1/members?user_id=eq.{userId}` | Name used for memo identity |
| Load memos | GET | `/api/rest/v1/memos` | Memo list |
| Load organization letterhead | GET | `/api/rest/v1/organization_settings` | PDF header/signature/stamp |
| Search and status filter | None | None | Client-side |
| Delete memo | DELETE | `/api/rest/v1/memos?id=eq.memoId` | Current source uses literal `memoId` in the query string rather than the callback argument |
| Resend memo | PATCH | `/api/rest/v1/memos?id=eq.memoId` | Current source uses literal `memoId`; patches status and `sent_at` |
| Download memo PDF | None, then PATCH | Local PDF generation; then `/api/rest/v1/memo_recipients?memo_id=eq.{memoId}&downloaded_at=is.null` | Marks previously unrecorded recipient downloads |
| Open preview / navigate to create or edit | None | None | Client-side navigation/dialog |

### Create/edit memo — `/treasurer/memos/create`, `/treasurer/memos/:id/edit`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Resolve Treasurer name | GET | `/api/rest/v1/members?user_id=eq.{userId}` | Memo signature |
| Load organization branding | GET | `/api/rest/v1/organization_settings` | Live letterhead |
| Load memo templates | GET | `/api/rest/v1/memo_templates` | Template selector |
| Load active members | GET | `/api/rest/v1/members` | Recipient selection |
| Load role assignments/executive recipients | GET | `/api/rest/v1/user_roles` | Resolves executive recipients |
| Load memo draft for edit | GET | `/api/rest/v1/memos?id=eq.editId` | Current source uses literal `editId`, not the route parameter |
| Load draft recipients | GET | `/api/rest/v1/memo_recipients?memo_id=eq.editId` | Current source uses literal `editId` |
| Create memo | POST | `/api/rest/v1/memos` | Saves a draft or sent memo |
| Update memo | PATCH | `/api/rest/v1/memos?id=eq.{editId}` | Saves edits to a memo |
| Replace recipient rows when editing | DELETE then POST | `/api/rest/v1/memo_recipients?memo_id=eq.{editId}`; `/api/rest/v1/memo_recipients` | Rebuilds recipients for the saved memo |
| Save/send to recipient set | POST | `/api/rest/v1/memo_recipients` | Stores one row per recipient; `delivered_at` set for sent memos |
| Download PDF | None | None | Client-side PDF generation |
| Send notifications | POST | `/api/rest/v1/notifications` | Creates one notification per selected member |
| Share to Welfare chat | GET then conditional POST | `/api/rest/v1/conversations`; `/api/rest/v1/conversations` if missing; then `/api/rest/v1/messages` | Finds/creates group conversation and writes message |
| Attach files | None identified | None | UI stores selected `attachments` in component state; no attachment upload/request was found in `CreateMemo.tsx` |

### Official letters — `/treasurer/documents`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load organization branding | GET | `/api/rest/v1/organization_settings?limit=1` | Letterhead used when generating text letter |
| Load official letters | GET | `/api/rest/v1/documents?document_type_code=eq.official_letter&order=created_at.desc&limit=100` | List |
| Create official letter | POST multipart | `/api/documents/` | `documentsApi.upload` submits generated `.txt` file and notes |
| Preview/download letter | Browser GET | `download_url` returned on document data | Opens/downloads URL directly; no separate API client call is made by the button |

### Financial reports — `/treasurer/reports`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load organization settings | GET | `/api/rest/v1/organization_settings` | Report letterhead |
| Resolve Treasurer name | GET | `/api/rest/v1/members?user_id=eq.{userId}` | Report signature |
| Load saved reports | GET | `/api/rest/v1/financial_reports` | Existing report list |
| Generate monthly/quarterly/annual report data | GET | `/api/rest/v1/contributions`, `/expenses`, `/penalty_withdrawals`, `/donation_withdrawals`, `/operational_withdrawals` | Requests records from period start and filters through period end client-side |
| Load wallet totals for report | GET | `/api/rest/v1/penalty_wallet`, `/donation_wallet`, `/operational_wallet` | Captures balances/received/withdrawn |
| Save generated report | POST | `/api/rest/v1/financial_reports` | Persists calculated totals and serialized report data |
| Download saved report PDF | GET then local PDF | `/api/rest/v1/wallet_transactions?occurred_at=gte.{start}&limit=5000`; `/api/rest/v1/members` | Loads transactions/member lookup; renders PDF locally |

### Operational wallet — `/treasurer/operational-wallet`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load balance (polls every 60 seconds) | GET | `/api/wallets/operational/balance` | Displays wallet totals |
| Request STK top-up | POST | `/api/functions/v1/operational-topup` | Sends amount, phone, optional narration |
| Create operational withdrawal | POST | `/api/rest/v1/operational-withdrawals` | Creates pending withdrawal |
| Create bulk signatory records | POST | `/api/rest/v1/operational-withdrawal-signatories/bulk` | Sends withdrawal ID and chairperson/secretary/treasurer signatories |

### Wallet reports — `/treasurer/wallet-reports`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load wallet transaction rows | GET | `/api/rest/v1/wallet_transactions?order=occurred_at.desc[&wallet_type=eq.{type}][&direction=eq.{in\|out}][&status=eq.{status}]` | Computes wallet totals and transaction-source summaries locally |
| Export PDF | None | None | Client-side `jsPDF` generation |
| Export Excel | None | None | Client-side XLSX generation |

### Treasurer settings — `/treasurer/settings`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load organization settings | GET | `/api/rest/v1/organization_settings` | Initializes organization/contact/rules form |
| Upload logo/signature/stamp | POST multipart | `/api/storage/v1/object/organization/organization/{filename}` | Uploads file; following `getPublicUrl` is local URL construction |
| Save uploaded asset URL | PATCH | `/api/rest/v1/organization_settings?id=eq.{settingsId}` | Saves corresponding `*_url` and `updated_by` |
| Save organization/contact/payout rules | PATCH | `/api/rest/v1/organization_settings?id=eq.settings?.id` | Current source contains literal `settings?.id` in the URL rather than interpolating the settings object |

### Bank sync — `/treasurer/bank-sync`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load recent bank transactions (refreshes every 60 seconds) | GET | `/api/rest/v1/bank_transactions?order=transaction_date.desc&limit=50` | Recent bank transaction table |
| Load unresolved unmatched payments | GET | `/api/rest/v1/unmatched_payments?select=*&resolved=eq.false&order=created_at.desc&limit=25` | Reconciliation queue |
| Sync selected date range | POST | `/api/banking/coop/account/sync` | Sends `from_date` and `to_date`; refreshes transaction, unmatched, dashboard, member, and contribution query caches |

### Bank statement — `/treasurer/bank-statement`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load/filter/page bank transactions | GET | `/api/banking/coop/transactions?page={n}&per_page=50[&search=...&status=...&category=...&type=...&date_from=...&date_to=...]` | Server data list and pagination |
| Load financial summary | GET | `/api/banking/coop/dashboard/financial` | Summary cards |
| Sync selected date range | POST | `/api/banking/coop/account/sync` | Imports/synchronizes the selected bank period |
| Reconcile selected transaction | POST | `/api/banking/coop/transactions/{transactionId}/reconcile` | Sends member ID, category, and narration |
| Open statement import page | None | None | Client-side navigation/new tab to `/treasurer/bank-statement-import` |

### Bank statement import — `/treasurer/bank-statement-import`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Select/parse spreadsheet | None | None | XLSX parsing, column matching, validation preview, and batching are client-side |
| Import parsed statement (chunks of 200) | POST | `/api/functions/v1/bank-statement-import` | Sends rows, source name, and filename through `importsApi.bankStatement`; aggregates batch results locally |

For the full backend processing path—including duplicate checks, phone-based matching, when new members are created, monthly contribution allocation, and known limitations—see [KHWWC Bank Statement Import End-to-End](./KHWWC_BANK_STATEMENT_IMPORT_END_TO_END.md).

### Book balance import — `/treasurer/book-balance-import`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Select/parse spreadsheet | None | None | XLS/XLSX/CSV parsing is client-side |
| Import/upsert parsed book balance rows | POST | `/api/rest/v1/book_balance` | Sends records with `created_by`; sets `Prefer: resolution=merge-duplicates` |

### Book balance — `/treasurer/book-balance`

| UI action/data | Method | API path | Frontend behavior |
|---|---:|---|---|
| Load bank balance overview | GET | `/api/treasurer/bank-balance/overview` | Current balance, total credits/debits, monthly summary |
| Load withdrawals tab | GET | `/api/treasurer/bank-balance/withdrawals?page={n}&per_page=50[&search=...&start_date=...&end_date=...]` | Paginated debit transactions |
| Load deposits tab | GET | `/api/treasurer/bank-balance/deposits?page={n}&per_page=50[&search=...&start_date=...&end_date=...]` | Paginated credit transactions |
| Load all-transactions tab | GET | `/api/treasurer/bank-balance/transactions?page={n}&per_page=50[&search=...&start_date=...&end_date=...]` | Paginated credits/debits |
| Search/date filters, clear filters, pagination | None | None | State changes drive the relevant GET query |

## Source-level frontend/API follow-up items

These are recorded as observed request-construction or action-wiring concerns, not as a full backend validation:

1. Memo history delete/resend requests use the literal `id=eq.memoId`; clicking an item therefore does not substitute its actual ID in those paths.
2. Memo edit hydration requests use the literal `id=eq.editId` and `memo_id=eq.editId`; the route ID is not interpolated in the observed source.
3. The memo attachment picker maintains/display files, but no upload or persistence request for those files was found in the page.
4. Treasurer settings save (including payout rules) uses the literal `id=eq.settings?.id`; unlike the asset update request, it does not interpolate `settings.id`.
5. `WalletReports` tracks date-from/date-to and reruns its query when those values change, but the constructed `wallet_transactions` URL does not include either date filter.
6. Operational wallet withdrawal uses `/operational-withdrawal-signatories/bulk`; this exact path is constructed by the frontend. Confirming whether a matching backend route exists is outside this frontend-only matrix.
7. `TreasurerDocuments` submits `official_letter` through the member documents upload client; this matrix records the request but does not certify that the backend accepts that document type for this role.
8. The document upload client reads `localStorage.auth_token`, while the shared API clients store the session in `localStorage.welfare_auth`; verify whether a separate `auth_token` is maintained before relying on the upload request's bearer header.
9. Payout eligibility only filters out already-paid records, whereas payout creation also blocks pending/approved requests; the server rejects duplicate submission, but the eligibility list may still offer the member.
10. Payout submission sends a phone number, but the backend creates the linked withdrawal using the member record's stored phone instead; confirm and maintain that member phone before payout approval.

## Coverage status

- Frontend routes inventoried: 21 (including memo creation and edit URLs).
- Reused Admin pages included: withdrawal approvals, penalty wallet, donation wallet.
- API requests/actions are listed from the page implementations; locally performed filtering, calculations, document/PDF creation, and spreadsheet parsing are called out separately.
- Backend authorization, runtime payload success, and response semantics are not claimed by this frontend-only inventory.
