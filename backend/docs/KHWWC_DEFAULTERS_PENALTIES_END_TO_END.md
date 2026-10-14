# KHWWC Defaulters and Penalties — End-to-End Flow Matrix

## Scope and evidence

This matrix traces the Admin Defaulters screen, member Pay Penalty screen, Admin penalty-payment verification screen, and Admin Penalty Wallet screen through the Laravel read models, payment lifecycle, routes, and database records.

It documents the checked-in source, not a live production payment or production data. No financial data was changed and no penalty assessment command was run. The key implementation sources are:

- `frontend/src/pages/admin/Defaulters.tsx`
- `frontend/src/pages/member/PayPenalty.tsx`
- `frontend/src/pages/admin/VerifyPenaltyPayments.tsx`
- `frontend/src/pages/admin/PenaltyWallet.tsx`
- `khwwc/app/Services/FinancialReadModel.php`
- `khwwc/app/Services/MemberStatsService.php`
- `khwwc/app/Services/PaymentService.php`
- `khwwc/app/Services/PaymentLifecycleService.php`
- `khwwc/app/Console/Commands/PenaltiesAssess.php`
- `khwwc/database/migrations/2026_09_02_000001_add_penalty_obligation_columns_and_unique_index.php`
- `khwwc/routes/api.php`
- `khwwc/routes/console.php`

## Executive result

The Defaulters screen uses a Laravel financial read model and is not a frontend calculation of who is delinquent. There are, however, **two distinct payment-review paths**: STK payments use the canonical `penalty_payment_records` table and lifecycle; the Verify Penalties page patches the legacy `penalty_payments` table directly. They are not interchangeable, and the inspected legacy verify action does not itself post a payment to a penalty or credit the canonical wallet.

The source also contains important penalty payment limitations:

1. Pay Penalty's aggregate fallback calls `laravelApi.get('/api/members/stats', ...)`. The shared client does not recognize `/api/` as an explicit API prefix and prepends `/rest/v1`, so the default URL becomes `/api/rest/v1/api/members/stats` rather than the stats endpoint. If the fallback data is obtained through another route/configuration, it invents a `derived-overdue-{memberId}` ID; the payment backend treats `penaltyIds[0]` as a persisted penalty ID, and settlement writes it to `penalty_payment_records.penalty_id`, whose relationship is to `penalties.id`. Neither path safely maps an aggregate balance to a persisted penalty.
2. When more than one penalty is selected, the backend links the payment only to the first ID. It does not distribute the received amount across all selected penalties.
3. The Defaulters calculation subtracts stored paid penalty rows (`penalties.is_paid = 1`), not partial amounts in `penalty_payment_records`. A partial settlement leaves the penalty row unpaid and can therefore leave the full amount in the Defaulters balance.
4. The STK pending record is initially inserted without a `penalty_id`. Once confirmed, lifecycle posting creates a separate verified record. The inspected flow does not update that initial pending record to a terminal status.
5. `penalties:assess` is not registered in `routes/console.php`. No scheduler invocation or other automated cadence was found in the inspected source. The command also deletes stale unpaid `Late%` penalty rows, so it must not be run as a diagnostic or casually added to a schedule.

## Data model and status meanings

| Record/store | Role in the flow | What its status means here |
|---|---|---|
| `monthly_obligations` | Canonical per-member/per-period obligation and contribution balance. Contribution allocations and eligible confirmed payment projections determine paid, partial, or unpaid state. | `unpaid`, `partial`, `paid`, and `advance_covered` are obligation outcomes; `pending_months` is not the same as overdue months. |
| `penalties` | Assessed penalty rows, with amount, member, optional contribution link, and `is_paid`. The obligation migration adds nullable year/month and a unique index on member/year/month/reason. | In the canonical STK lifecycle, `is_paid = 1` is set only when linked payment-record totals cover that specific penalty row. |
| `penalty_waivers` | Audited reductions of a member's penalty balance in integer minor units, with reason, creator and timestamp. | A waiver is not a payment; it does not set `penalties.is_paid` or credit the wallet. |
| `penalty_payment_records` | Canonical penalty payment ledger used by STK settlement and the Penalty Wallet's recent-payment list. | STK initiation creates `pending`; canonical settlement creates a separate `verified` record and credits the penalty wallet. |
| `penalty_payments` | Legacy/manual payment-verification list read and patched by Verify Penalties. | `pending`, `verified`, and `rejected` are changed directly by the page. The inspected patch flow does not call `PaymentLifecycleService`. |
| Penalty wallet / wallet transactions | Receives canonical successful penalty-payment credits and is read for balance/history/withdrawal UI. | Wallet totals are not evidence that a particular penalty row was allocated correctly. |

## Defaulters calculation and display matrix

| Stage | Source/action | Rule or result |
|---|---|---|
| Read request | Admin React Query key `["defaulters"]`; `GET /api/members/stats/defaulters` | JWT-protected route. `MemberStatsController::defaulters()` explicitly requires a staff role; non-staff receives 403. |
| Obligation source | `FinancialReadModel::defaulters()` calls `resolvedObligations()` | Reads canonical `monthly_obligations`, resolving contribution allocations, eligible confirmed contribution payments, legacy contribution rows, and carry-forward. Covered obligations do not count as outstanding. |
| Overdue rule | `isOverdue(obligation, graceDays)` | Grace period is `welfare_settings.penalty_grace_days`, falling back to 30 days in code. Current/future obligations are pending and should not count as overdue until their due date plus grace period has elapsed. |
| Contribution outstanding | Sum `required_minor - paid_minor` for overdue obligations only | The Defaulters table's contribution balance is the overdue portion, not every current or future pending contribution. |
| Penalty accrued | Grouped `penalties` totals, compared with unpaid obligation count times configured `penaltyAmount()` | The accrued basis is the larger of stored penalty amounts and derived penalty-rate × unpaid-obligation count. |
| Penalty paid and waived | Subtract amounts of stored penalty rows marked paid and total `penalty_waivers.amount_minor` | Partial `penalty_payment_records` are not directly subtracted by this Defaulters method. |
| Membership in result | Union of members with overdue obligations and members with positive net penalty balance | A member can appear for overdue contributions, unpaid penalties, or both. A current pending contribution by itself is not enough. |
| Returned values | `id`, name, phone, member number/status, contribution outstanding, penalty outstanding, total outstanding, pending months, overdue months, contribution/penalty counts | Sorted by total outstanding descending. The endpoint returns all rows; this implementation does not paginate. |
| Frontend table/totals | Client maps API fields to table fields; search and totals are computed locally | Search filters the already-fetched complete response. Displayed total owed and overdue-month totals are client sums over that response. |
| Summary counter | `GET /api/members/stats/summary` | Test asserts its `total_defaulters` count uses the same `FinancialReadModel::defaulters()` set. |

### Defaulters page actions

| UI action | Request | Effect and boundary |
|---|---|---|
| View member | Local navigation to `/admin/members/{memberId}` | Opens member detail; does not change delinquency or payment records. |
| Suspend/activate | `POST /api/functions/v1/rpc/update_member_status` with `new_status` and `target_member_id` | Changes member access/status. It does not settle a contribution or penalty. On success the page invalidates `["defaulters"]`, `["admin-stats"]`, and `["members"]`. |
| Record partial waiver | `POST /api/functions/v1/admin/members/{memberId}/penalty-waivers` with amount and reason | Staff-only controller validates positive amount, reason length, and amount no greater than current outstanding; inserts an audited waiver and invalidates member stats. It does not mark a penalty paid or credit wallet funds. The page invalidates defaulters, admin stats, and members. |
| Search | No request | Client-side name, phone, and member-number matching only. |

## Member Pay Penalty payment lifecycle

| Step | Frontend/backend action | Persisted outcome / caveat |
|---|---|---|
| 1. Resolve member | Member page loads `/api/rest/v1/members?user_id=...` via `laravelApi` | Selects the authenticated member's profile and phone for payment. |
| 2. Load unpaid penalties | `GET /api/rest/v1/penalties?member_id=eq.{memberId}&is_paid=eq.false` | Uses actual unpaid `penalties` rows when present. |
| 3. Fallback when no rows | Calls `laravelApi.get('/api/members/stats', { member_id })`; client prefixes non-special paths with `/rest/v1` | With the default API base, this resolves to `/api/rest/v1/api/members/stats`, not the explicit stats route. The page does not check `statsRes.error`; failed lookup yields no derived row. If stats data is returned via a configuration/route override, the frontend creates a non-persisted `derived-overdue-{memberId}` ID that the payment backend cannot safely use as a real penalty ID. |
| 4. Select amount | UI selects all returned rows by default and sums the selected row amounts | Member can toggle rows. The aggregate fallback appears as a single selectable row. |
| 5. Initiate STK | `POST /api/payments/stk-push`: member ID, amount rounded up to integer KES, phone, `paymentType: "penalty"`, selected `penaltyIds` | `PaymentController` validates amount, phone, payment type and optional ID array. `PaymentService::assertCanWrite()` permits staff or the member paying their own member ID; view-as mode is blocked. |
| 6. Create pending entries | `PaymentService` inserts `penalty_payment_records` with member, amount, checkout ID and `pending` status; separately creates the canonical pending row in `payments` | Initial `penalty_payment_records` row does not include the selected `penalty_id`. Canonical `payments.related_entity_id` is set from only `penaltyIds[0]`. |
| 7. Initiate and check provider payment | Provider STK request; frontend polls `GET /api/payments/status/{checkoutRequestId}` | Laravel status polling is authoritative; the legacy STK callback explicitly says it is ignored. Member page polls for up to 60 attempts at 10-second intervals. |
| 8. Successful canonical settlement | `PaymentService::settleConfirmedCanonicalPayment()` calls `PaymentLifecycleService::postPayment('penalty', ...)` | `postPenalty()` creates a new verified `penalty_payment_records` row, uses the canonical payment's related entity as its `penalty_id`, and credits the penalty wallet idempotently by provider reference. |
| 9. Update a penalty row | If the linked penalty ID exists, sum its `penalty_payment_records` and set `penalties.is_paid` true only when the stored penalty amount is fully covered | The service processes one related penalty ID only. No allocation loop across selected penalties was found. With a missing ID, no penalty row is marked paid. |
| 10. Frontend completion | Pay Penalty recognizes `confirmed`, `paid`, `verified`, `partial`, or `failed`, then reloads after success | Source currently calls `pollPaymentStatus(checkoutRequestId)` from the success branch without defining `checkoutRequestId` in that component. Although per-file editor diagnostics returned no errors, repository search found no other declaration in the frontend source. Therefore this status-poll transition is not established as working from source. |

### Payment outcome interpretation

- **Provider prompt sent / pending**: does not mean paid or verified. The canonical payment and initial penalty record are pending.
- **Provider confirms**: canonical payment status polling invokes lifecycle settlement, which creates the canonical verified payment record and credits wallet.
- **Penalty fully covered**: only the particular linked penalty row can be marked paid; the lifecycle checks the amount total for that row.
- **Partial penalty coverage**: lifecycle stores a verified payment record but leaves that penalty row `is_paid = 0`. The current read models commonly count only fully-paid penalty rows when calculating the paid amount, so partial payments may not reduce the shown outstanding balance.
- **Multiple selected penalties**: backend associates the whole paid amount with the first selected ID, not all selected IDs. Do not assume each selected penalty is cleared.
- **Aggregate fallback**: the synthetic ID is not a stored penalty ID. Its relation is not a supported settlement mapping and may fail the `penalty_payment_records.penalty_id` relationship at settlement.
- **Payment record duplication**: STK initiation inserts a pending `penalty_payment_records` entry without a penalty ID; successful lifecycle posting inserts a second, verified entry. The inspected lifecycle does not transition the original pending entry.

## Assessment and accrual matrix

| Item | Source behavior |
|---|---|
| Assessment command | `php artisan penalties:assess` invokes `FinancialReadModel::overdueObligationsForAccrual()`. |
| Penalty rule inputs | Uses canonical overdue obligations after the configured `penalty_grace_days`; inserts the configured `penaltyAmount()` for each member/year/month without an existing penalty row for that period. |
| Duplicate avoidance | Before inserting, checks whether a penalty already exists for member/year/month. This is an application-level check; the examined command does not show a transaction around the overall assessment. |
| Stale-row handling | Deletes unpaid penalties whose reason starts with `Late` and whose year/month no longer corresponds to an overdue obligation. This is a data-changing operation. |
| Automated operation | `routes/console.php` registers reminders, backup and conditional Co-op jobs, but does not schedule `penalties:assess`. No external cron or manual invocation was verified. |
| Operational implication | Do not claim that overdue penalty rows are automatically assessed on a particular cadence. Confirm deployment cron/runbook and review the stale-row deletion semantics before scheduling or executing it. |

## Penalty Wallet and verification surfaces

| Page | Data/action | Source-of-truth distinction |
|---|---|---|
| `/admin/penalty-wallet` | Loads `/api/rest/v1/penalty-wallet`, `/api/rest/v1/penalty-payment-records`, `/api/rest/v1/penalty-withdrawals`, `/api/rest/v1/signatory-signatures`, `/api/rest/v1/members`, organization settings and `/api/members/stats` | `laravelApi` resolves its ordinary table paths to `/api/rest/v1/...`. Recent payment history is based on canonical `penalty_payment_records`; per-member figures are composed from member stats. Wallet balance represents collected wallet funds, not proof that individual penalties were allocated. |
| `/admin/penalty-wallet` | Creates withdrawal request and displays approvals/receipt | Withdrawal and signatory approval is a wallet-funds flow separate from member penalty assessment/payment. It does not resolve a member's penalty balance. |
| `/admin/penalty-payments` | GET `/api/rest/v1/penalty_payments?status=eq.pending...` and `/api/rest/v1/penalty_payments?...` | Reads the legacy `penalty_payments` table, not canonical STK `penalty_payment_records`. |
| `/admin/penalty-payments` | PATCH legacy row to `verified` or `rejected`, setting verifier/time and optional rejection reason | The inspected page performs a generic REST table patch and invalidates only its own legacy-payment queries. It does not call canonical settlement, update `penalties.is_paid`, or credit `penalty_wallet` in this page flow. Treat it as a legacy manual-review record unless another explicitly verified backend process connects it. |

## End-to-end flow matrix

| Starting condition | Transition | Authoritative record/result | What resolves the condition |
|---|---|---|---|
| Monthly obligation not past due plus grace | Remains pending | `monthly_obligations` and resolved read model | Time passing past due date plus configured grace can make the unpaid remainder overdue. |
| Past-grace unpaid or partial obligation | Included in overdue group | `FinancialReadModel::defaulters()` | Eligible contribution payment allocation or carry-forward changes the resolved obligation; query refresh reflects the result. |
| Overdue obligation without a penalty row | Can still produce a derived penalty balance in the read model | Derived rate × count of unpaid obligations | `penalties:assess` can persist a row, but no scheduler is configured in inspected code. The Defaulters balance itself may appear before a row exists. |
| Stored unpaid penalty row | Included in penalty outstanding | `penalties` minus fully paid rows and waivers, subject to derived accrual maximum | Canonical payment fully covering that linked row or an audited waiver reduces the read-model balance. |
| Member initiates penalty STK | Pending canonical payment and pending penalty-payment record | `payments` plus `penalty_payment_records` | Provider confirmation via status polling and canonical settlement. |
| STK confirmed and linked row fully covered | Canonical payment record becomes confirmed; settlement writes verified ledger and wallet credit; linked penalty can become paid | `payments`, `penalty_payment_records`, `wallet_transactions`, `penalties.is_paid` | Defaulters query recomputation should then remove the resolved penalty-only membership if no other overdue balance remains. |
| STK partially covers a penalty | Verified record exists; linked row stays unpaid | `penalty_payment_records` and `penalties.is_paid = 0` | Current read-model subtraction does not reliably apply partial record totals; balance can remain overstated. |
| Member pays multiple selected penalties | Full amount is linked to first ID only | `payments.related_entity_id` and resulting `penalty_payment_records.penalty_id` | Current backend has no per-penalty split; this needs backend allocation behavior before multi-select is financially reliable. |
| Admin waives part of a penalty | Creates positive, capped, reasoned, actor-attributed waiver | `penalty_waivers` | Recomputed net penalty balance falls by waiver amount; penalty row remains unpaid and no wallet credit is created. |
| Admin suspends/reactivates member | Changes member status | Member record/status RPC | Changes access only; it is not payment, waiver, or balance resolution. |
| Legacy manual penalty payment marked verified | Updates row in `penalty_payments` | Legacy table only in inspected UI flow | No canonical penalty/wallet outcome was found; do not count it as a resolved balance without another verified reconciliation path. |

## Verification evidence and limits

The focused source tests inspected include:

- `tests/Feature/DefaultersReadModelTest.php`: asserts the endpoint and admin summary use the same defaulter set; covers an overdue member, penalty-only member, and a member whose obligation is covered by carry-forward; checks batched query count.
- `tests/Feature/PenaltyWaiverTest.php`: asserts a partial waiver is audited, updates the financial read model, does not mark the penalty paid, and cannot exceed current outstanding.
- `tests/Feature/Payments/PenaltyPaymentTest.php`: asserts STK initiation creates a pending `penalty_payment_records` row. The inspected test does not prove provider settlement, multi-penalty allocation, or member-page polling.

These test sources were inspected but not run for this documentation change. No live provider payment, production database read, penalty assessment, waiver, or wallet operation was performed.

## Related matrices

- [Bank statement import and contribution/unmatched lifecycle](./KHWWC_BANK_STATEMENT_IMPORT_END_TO_END.md)
- [Admin frontend/API matrix](./KHWWC_ADMIN_FRONTEND_API_MATRIX.md)
- [Member frontend/API matrix](./KHWWC_MEMBER_FRONTEND_API_MATRIX.md)
