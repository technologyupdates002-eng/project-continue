# KHWWC Payout, Expense, and Wallet Functional/API Matrix

## Scope and evidence

This document maps the Treasurer expense-and-payout screen to the Laravel APIs and backend lifecycle for member payouts, expenses, wallet withdrawals, approvals, and wallet settlement. It combines the frontend request inventory in [KHWWC Treasurer Frontend/API Matrix](./KHWWC_TREASURER_FRONTEND_API_MATRIX.md) with route/controller/service and schema inspection.

This is a source-level matrix, not runtime or payment-provider verification. An endpoint being registered does not prove that its provider, credentials, callback, or production deployment is operational. Unless explicitly stated otherwise, `/api/...` endpoints require a JWT bearer token and use the middleware shown in the route registry.

## API conventions

| Call form | Resolved API path |
|---|---|
| `apiRequest('/rest/v1/{table}')` | `/api/rest/v1/{table}` |
| `apiRequest('/api/{path}')` | Exact `/api/{path}` |
| `laravelApi` generic table request | `/api/rest/v1/{table}` |

Generic REST table calls are handled by `RestApiController`; the exact allowed table names are constrained by the route registry and `PolicyService`. Hyphenated names are normalized to underscores.

## Main API matrix

| Function | Method and endpoint | Request/query | Response and important behavior |
|---|---|---|---|
| Read payout rules | GET `/api/rest/v1/organization_settings` | No body; frontend reads `payout_rules` | Settings rows. Amounts are configured per payout type. The Treasurer settings page's payout-settings PATCH currently constructs a literal `id=eq.settings?.id` query rather than interpolating the loaded ID; verify/fix that save path before relying on edited values. |
| Find payout-eligible members | GET `/api/admin/payouts/eligible?payout_type={type}` | Supported types: `wedding`, `death`, `death_member`, `death_spouse`, `death_child`, `death_parent`, `retirement`, `emergency`; defaults to `wedding` | `{ data: [{ id, name, member_code, phone, eligible_amount }] }`. Requires `admin`, `super_admin`, or `treasurer`. Includes active members and excludes members with a paid payout of that type. It does not exclude pending/approved payouts; creation does. |
| Read payout history | GET `/api/admin/payouts` | No body | `{ data: [...] }`, enriched with member details, linked wallet type/withdrawal status/signatories/provider reference, and a `PAY-...` display reference. Requires `admin`, `super_admin`, or `treasurer`. |
| Create member payout | POST `/api/admin/payouts` | `{ member_id, amount, payout_type, wallet_type, reason?, phone_number?, death_relation? }`; `wallet_type` is `penalty`, `donation`, or `operational` | `201 { data: { id, withdrawal_id, member_id, member_name, member_code, amount, eligible_amount, payout_type, status: "pending" } }`. In one transaction inserts the payout, selected-wallet withdrawal, and secretary/chairperson signatory rows. Requires Treasurer/admin role. |
| Read expenses | GET `/api/rest/v1/expenses` | Supports generic REST query filters, e.g. `?order=created_at.desc` | Expense records, staff-scoped. |
| Create expense record | POST `/api/rest/v1/expenses` | Expense fields include `expense_type`, `category`, `amount`, `created_by`; optional recipient, payment method, wallet type, phone/reference and withdrawal link fields | Creates a generic expense row for staff. An expense must be tied to an approved withdrawal for cash/bank/check settlement; the REST handler rejects an expense submitted as `completed`, `approved`, or `paid` without a withdrawal link. |
| Read wallet totals | GET `/api/wallets/{penalty\|donation\|operational}/balance` | No body | `{ wallet_type, total_balance, available_balance, reserved_balance, total_received, total_withdrawn }`, or `404` if no wallet record exists. JWT + view-as middleware + throttle. |
| Read wallet transactions | GET `/api/wallets/{walletType}/transactions?member_id={id}&limit={n}` | Wallet type: `penalty`, `donation`, `operational`; limit defaults to 100 | `{ data: [...] }`, ordered by occurrence time descending. Staff can read all; a non-staff user is restricted to their own member ID. |
| Credit wallet manually | POST `/api/wallets/{walletType}/credit` | `{ member_id, amount, source, mpesa_receipt?, reference_id?, reference_table?, notes? }` | Updates wallet totals and adds a ledger credit in a database transaction. Restricted to admin/super-admin/treasurer. A repeated reference pair is idempotent. Intended automated payment paths should use their canonical payment lifecycle, not manual credit calls. |
| Debit wallet manually | POST `/api/wallets/{walletType}/debit` | `{ member_id, amount, reason, reference_id?, reference_table? }` | Immediately subtracts from wallet and records a debit; insufficient balance returns 422. Restricted to admin/super-admin/treasurer. This direct ledger operation is distinct from the approval/reservation withdrawal flow. |
| Read verified donation payments | GET `/api/wallets/donation/recent-payments?limit={n}` | Limit defaults to 20 and is clamped to 1–100 | `{ data: [...] }`, verified donation records with member name; staff only. |
| Create a penalty-wallet withdrawal via virtual table | POST `/api/rest/v1/withdrawals` | `{ amount, reason, phone_number? }` | Creates a pending penalty withdrawal for the authenticated user's member record; server assigns requester, pending status, initial stage, and secretary/chairperson signatory rows. This is a special legacy path, not the generic `POST /{table}` operation. |
| Approve/reject a withdrawal stage | POST `/api/rest/v1/{withdrawal_signatories\|donation_withdrawal_signatories\|operational_withdrawal_signatories}/update` | `{ withdrawal_id, signatory_role, status: "approved" }` or `{ withdrawal_id, signatory_role, status: "rejected", rejection_reason }` | Uses `WithdrawalApprovalService`; enforces stage/role/signature rules. Approval can advance status and trigger the withdrawal flow. Rejection requires a reason. |
| Change withdrawal status directly | POST `/api/rest/v1/{penalty_withdrawals\|donation_withdrawals\|operational_withdrawals}/update-status` | Not supported for direct status changes | Returns 409: canonical approval/provider outcome controls the status. Generic PATCH/PUT/DELETE of withdrawal tables are likewise blocked from replacing the canonical withdrawal workflow. |
| Initiate staff operational wallet top-up | POST `/api/functions/v1/operational-topup` | `{ amount, phone_number, narration? }` | Starts an STK push for the operational wallet; returns pending checkout/payment identifiers. Staff only. The wallet is credited through the authoritative payment lifecycle after provider confirmation, not when the prompt is merely sent. |
| Initiate B2C for an approved withdrawal | POST `/api/b2c-transfer` | `{ amount, phone_number, withdrawal_id, wallet_type, member_id?, narration? }` | Staff-only canonical endpoint; amount and normalized phone must match the withdrawal. Payout screen normally invokes the approval service automatically, so this is not the normal payout-submit request. |
| Receive Coop transfer outcome | POST `/api/banking/coop/callback/transfer` | Provider callback payload | Callback handler validates/applies transfer outcome to canonical withdrawal and wallet ledger. Provider behavior must be verified separately. |

## Payout workflow: member benefit from request to payment

| Stage | API or backend action | Lifecycle behavior |
|---|---|---|
| Configure benefit amounts | Organization setting `payout_rules` | JSON values define eligible amounts for wedding, death categories, retirement, and emergency. Death subtypes fall back to the configured general `death` amount when not configured separately. |
| Load member candidates | GET `/api/admin/payouts/eligible` | Only active members are listed. A paid payout of the same type removes a member from eligibility. Pending/approved duplicates may still appear here but are rejected at submission. |
| Submit benefit | POST `/api/admin/payouts` | Requires active member, configured eligible amount greater than zero, positive payout amount no larger than eligible amount, valid payout and wallet types, and no existing pending/approved/paid payout of that member/type. Uses a DB transaction to create `payouts`, linked withdrawal, and two pending signatories. |
| Approve stages | Signatory `/update` route | Approval chain is secretary then chairperson. The approver needs the corresponding role and uploaded signature. Self-approval is blocked for non-admins. Rejection marks the withdrawal rejected; it does not settle wallet funds. |
| Reserve wallet funds and submit transfer | Backend `WithdrawalApprovalService` | After final approval, required signatures are checked and funds are reserved in the selected wallet. If a phone number is missing, the withdrawal becomes `approved_no_phone`. In production, provider submission is currently stopped by a safety gate before funds are reserved; do not treat approval as payment. |
| Process provider outcome | Coop transfer callback/reconciliation | Confirmed success settles the reservation (reduces balance, increases withdrawn total, records a debit), marks withdrawal completed, records provider reference, and creates a receipt. Confirmed failure releases the reservation and marks it failed. Reversal releases a pending reservation or reverses a settled withdrawal; unsafe reconciliation is surfaced as `reconciliation_required`. |
| Mark payout paid | `MemberPayoutService::markWithdrawalCompleted` | On confirmed completion, sets payout status to `paid` and `paid_at`; attempts an SMS to the member. |
| Review final history | GET `/api/admin/payouts` | Shows linked wallet, signatories, withdrawal/provider status and payout reference. Payout history remains available after payment. |

### Payout request constraints and observed limitations

- The create endpoint accepts `phone_number`, but the backend builds the linked withdrawal using the member's saved phone number. Verify the member profile phone number before approval.
- Payout eligibility is configuration-driven; zero/unconfigured payout amount causes creation to fail.
- Only one pending, approved, or paid payout per member and normalized payout type is permitted; a rejected/failed payout does not appear in that duplicate guard.
- A normal payout is funding from the selected organization wallet, not a direct transfer from the member's own balance.
- The payout request endpoint is staff-only. No member-facing payout request/history API was identified in this source matrix.
- Provider integration and callbacks are source-mapped only. Approval, `processing`, and transfer initiation are not equivalent to confirmed `paid`.

## Expense workflow and relationship to payouts

| Expense scenario | Required connection | Wallet and status effect |
|---|---|---|
| Ordinary expense record | `expenses` row with category, type, amount, creator, and any relevant recipient/payment metadata | Generic REST supports staff expense reads/writes. Creation alone does not debit a wallet. |
| Expense paid by cash/bank/cheque/check | Matching `expenses.withdrawal_id` and `withdrawal_table` must point to a wallet withdrawal; linked withdrawal must pass the approval process | Once approved, service settles the wallet reservation, marks withdrawal completed, marks the expense approved and sets `paid_by`/`paid_at`. It generates a receipt and audit event. |
| Expense paid through B2C/M-Pesa | Matching linked expense plus phone and fully signed withdrawal | Approval reserves funds and submits the provider transfer. Provider success settles the wallet and completes the withdrawal; failure releases reserved funds. The linked expense is marked approved when the transfer is initiated; the inspected callback path does not separately set its `paid_at` fields. |
| Member benefit payout | `payouts.payment_reference` points to a selected-wallet withdrawal | Payout row is created with the withdrawal. On withdrawal completion, payout transitions to paid and SMS is attempted. Operational-wallet payout withdrawals are tagged with `category = payout`, `expense_type = payout`, and the recipient member name. |

Expenses and payouts are related financial concepts but are separate records. Do not count both as independent cash outflow if a report includes both the linked expense and its wallet withdrawal/payout record; reconcile by `withdrawal_id` / `payment_reference`.

## Wallet connection matrix

| Wallet | Backing balance table | Withdrawal table | Signatory table | Payout eligible? | Inflow examples |
|---|---|---|---|---|---|
| Penalty | `penalty_wallet` | `penalty_withdrawals` | `withdrawal_signatories` | Yes | Verified penalty payment lifecycle credits the penalty wallet; canonical credit is idempotent by provider receipt. |
| Donation | `donation_wallet` | `donation_withdrawals` | `donation_withdrawal_signatories` | Yes | Verified general donations and fund-drive payments credit the donation wallet and maintain donation/campaign records. |
| Operational | `operational_wallet` | `operational_withdrawals` | `operational_withdrawal_signatories` | Yes | Staff operational top-up begins via STK and credits only after provider confirmation; other operational credits use canonical payment/ledger services. |

All three wallets share `wallet_transactions` as their ledger, distinguished by `wallet_type`. A withdrawal reserves available funds first; successful settlement then decreases total balance/increases total withdrawn and writes a debit ledger row. Failure releases the reservation without treating the funds as spent. `available_balance` is total balance less reserved balance. Wallets remain distinct; a payout or expense uses exactly its selected wallet and does not automatically move money between wallets.

## Authorization and API boundaries

| Resource/action | Effective source-level access |
|---|---|
| Payout eligibility, history, creation | `admin`, `super_admin`, or `treasurer` enforced in `MemberPayoutController` |
| `expenses` reads/writes | Staff read; `admin`, `super_admin`, `treasurer` write in generic REST |
| Payout table through generic REST | Staff read; no generic writes. Payout creation uses `/api/admin/payouts`. |
| Wallet balance | JWT authenticated generic wallet route; role restrictions are not applied in `balance()` itself. |
| Wallet transaction history | Staff can read all; non-staff are restricted to their own member ID |
| Wallet manual credit/debit | `admin`, `super_admin`, or `treasurer`; blocked in view-as-member mode |
| Withdrawal and signatory mutations | Must pass role/stage/signature service checks; direct status mutation is blocked |

The generic REST policy is not a substitute for conversation/withdrawal ownership checks. Confirm current deployed middleware and policy behavior before exposing these endpoints in a new client. In particular, read access to wallet balance tables is broader than the manual write role check in the source.

## Source tables and services

- Frontend matrix and exact screen calls: [KHWWC Treasurer Frontend/API Matrix](./KHWWC_TREASURER_FRONTEND_API_MATRIX.md)
- Route declarations: `routes/api.php`
- Payout creation/history/eligibility: `app/Http/Controllers/Api/MemberPayoutController.php`
- Expense generic REST behavior: `app/Http/Controllers/RestApiController.php`
- Wallet API: `app/Http/Controllers/WalletController.php`
- Wallet ledger and reservation/settlement: `app/Services/WalletService.php`
- Approval, B2C, settlement, and receipts: `app/Services/WithdrawalApprovalService.php`
- Payout completion/SMS: `app/Services/MemberPayoutService.php`
- Wallet, withdrawal, expense, and payout schemas: `database/schema.sql`
- Role and table policies: `app/Services/PolicyService.php`
