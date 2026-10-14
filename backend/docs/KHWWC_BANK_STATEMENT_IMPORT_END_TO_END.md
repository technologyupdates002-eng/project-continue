# KHWWC Bank Statement Import — End-to-End Flow

## Scope

This describes the Treasurer spreadsheet import at `/treasurer/bank-statement-import`, from local XLS/XLSX parsing through member matching/creation and contribution projection. It is based on the frontend and Laravel source, with behavior tests cited below. It is not a runtime guarantee for any particular bank file.

This is distinct from the ongoing Bank Statement Sync/reconciliation flow: `StatementMode::BANK_STATEMENT_SYNC` is not allowed to create a member; this Treasurer import uses `StatementMode::TREASURER_STATEMENT_IMPORT`, which may create one.

For the related Defaulters and penalty balance/payment lifecycle, see [KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md](./KHWWC_DEFAULTERS_PENALTIES_END_TO_END.md).

## End-to-end sequence

### 1. Treasurer selects a statement file

`BankStatementImport.tsx` reads the selected `.xlsx`/`.xls` file in the browser with SheetJS. The original workbook is not posted to Laravel. It scans each worksheet for a header row, using synonyms for date, narration/details, reference, credit/deposit/amount, debit/withdrawal, name, and phone.

It then:

1. Locates the header row even if the statement has preamble rows.
2. Chooses the credit column, preferring a credit/deposit column and otherwise a generic amount/value column.
3. If both credit and debit columns exist, samples up to 19 data rows; if the debit total exceeds the credit total, it swaps their interpretation (the Co-op statement accommodation).
4. Treats subsequent rows without a transaction start as continuation text for the current transaction, while ignoring common balance/total rows.
5. Keeps a candidate only when the interpreted incoming amount is positive, no outgoing debit is present, a Kenyan phone is found, and the date can be parsed.
6. Derives a reference from the reference cell, an M-Pesa-like code in the narration, or a fallback of `phone-date-amount`.
7. Removes duplicates within the workbook using `phone|reference|date|amount`.

The UI shows a preview of the parsed rows (up to 30) and the total amount before the user presses Import.

### 2. Frontend sends normalized rows in chunks

The frontend maps each parsed transaction to this API row:

```text
trans_date       = parsed date
value_date       = parsed date
transaction_details = name + phone + optional M-Pesa code + raw details
reference_no     = parsed/fallback reference
credit           = parsed amount
debit            = 0
book_balance     = 0
```

`importsApi.bankStatement` posts JSON `{ rows, source_name, file_name }` to `POST /api/functions/v1/bank-statement-import`. It splits files into chunks of 200 rows; **each chunk is a separate backend import batch**. The page aggregates the returned counters locally and refreshes the members/contributions/dashboard queries after successful completion.

### 3. Laravel validates access and request shape

The route requires a JWT. `ImportController` then requires a staff role (`admin`, `super_admin`, `treasurer`, `chairperson`, `vice_chairperson`, `secretary`, `vice_secretary`, or `patron`). A non-staff identity is rejected with HTTP 403.

`BankStatementImportRequest` checks that `rows` is an array and each row is an array; `source_name`, `file_name`, and `statement_period` are optional strings. It does not enforce required per-row date/amount/reference fields. The browser parser supplies its own candidate-row checks, while `StatementProcessor` normalizes and filters the server-side rows.

### 4. Laravel creates a batch and processes rows

`ImportService::importMemberStatement` creates a UUID `member_import_batches` record with status `processing`, then processes each row using `StatementProcessor` in `TREASURER_STATEMENT_IMPORT` mode. Each row is separately caught and reported as a failure if processing throws; the import continues to later rows.

The processor normalizes supported row shapes, parses date and monetary values, and:

1. Extracts a Kenyan phone from narration/details, falling back to the reference.
2. Normalizes the phone to `+254...`.
3. Extracts a payer/member name from the narration/reference where possible.
4. Counts positive credits and amounts; records debits in the import summary but does not turn debit-only rows into contributions.
5. Requires a normalized phone and a positive credit. Missing phone is counted as invalid; debit-only/no-credit rows are unmatched and do not create member/contribution records.
6. Builds the transaction reference from the supplied reference, or `phone-date-credit` if no reference exists.
7. Checks existing `bank_transactions` for that transaction reference (and a supplied bank fingerprint, if present). A duplicate is skipped before member or contribution processing.

The import-level browser parser excludes invalid-date rows. For direct API callers, the request validator itself does not require a date; the processor can normalize a missing/invalid date to null, and the payment engine then falls back to the current date when allocating. This means callers should send a valid transaction date.

### 5. Matching an existing member or creating a new one

The processor searches `members.phone` for any of these variants of the normalized number:

- E.164 with plus, e.g. `+254712345678`
- International digits without plus, e.g. `254712345678`
- Local format, e.g. `0712345678`

**If a member matches:** the transaction is associated with that member. The importer does not update that member's name, phone, auth account, role, or profile from the statement. It may update `first_contribution_date` when the imported transaction is earlier than the stored date, and it adds the payment/contribution records described below.

**If no member matches:** in Treasurer import mode only, the backend creates, in a DB transaction:

- An `auth_users` row with normalized phone, a synthetic login email (`254...@welfare.local`), a hashed initial password `Member2026`, and `must_change_password = 1`.
- A `members` row linked to that auth user, with the extracted name (or phone as fallback), normalized phone, active status, zero initial contribution/penalty totals, first contribution date, and source `TREASURER_STATEMENT_IMPORT`.
- An active `member` row in `user_roles`.

It then associates the imported bank payment with the newly created member. The current implementation does not deliver the initial password from this import flow; the UI displays it as `Member2026`, and the account is required to change it on first password change/login flow.

**Ambiguous phone matches:** the strict ambiguity-to-unmatched behavior is implemented for the separate `BANK_STATEMENT_SYNC` mode. The Treasurer import chooses the first result returned by the phone lookup if multiple members share a matching phone; it does not currently flag that as ambiguous.

### 6. Bank transaction, payment, and contribution records are written

For a matched or newly created member, the processor first creates a `bank_transactions` row with bank/source/reference/date/credit/debit/narration, member association, match metadata, import batch id, and a fingerprint. It then passes the confirmed bank event to `PaymentEngine` using provider `bank_import` and source `bank_import`.

`PaymentEngine` creates or reuses an idempotent canonical `payments` record and provider-event record. For a confirmed monthly contribution payment, it allocates the amount to `monthly_obligations` and `contribution_allocations`. Unless explicit allocations are supplied (this import supplies none), default allocation:

- Starts in the transaction's month.
- Reads `welfare_settings.monthly_contribution_amount` (with a code fallback of KES 300).
- Allocates one monthly amount at a time into that month and subsequent months, up to 240 months.
- Marks each obligation paid or partial based on the amount allocated versus the required amount.
- Places any amount remaining after the 240-month allocation limit into carry-forward balance.

The processor then projects allocations into the legacy `contributions` table by member/year/month, including `payment_id`, reference, amount, paid/partial status, and paid date when fully paid. It updates the bank transaction with the projected contribution ID and calls `MemberFinancialProjectionService::syncMember`, which recalculates `members.total_contributions` from fully paid canonical obligations plus eligible legacy contributions and adjustments.

**Example:** with the configured monthly contribution set to KES 300, a KES 1,000 payment dated January creates allocations of KES 300 in January, KES 300 in February, KES 300 in March, and KES 100 in April. The first three obligations are fully paid and April is partial. The test suite verifies this shape.

### 7. Import rows, audit, and response summary

Every input row is written to `member_import_rows` with raw payload, extracted and normalized name/phone, match/member reference, status/message, and any failure reason. A new member is marked `new_member`; existing matches are `matched`; duplicates and no-phone rows are skipped; debit/no-credit rows are unmatched; thrown errors are failed.

After processing, the batch is marked `completed` with counts/summary, and a `statement_processing_audit` record is written. The response includes batch ID, new/matched members, contributions created/updated, duplicates, unmatched/invalid phone counts, imported count, amount, and row failures. Since each 200-row frontend chunk has its own batch, the page's displayed batch ID is only the last chunk's ID.

## What the import actually checks—and what it does not

| Check | Current behavior |
|---|---|
| Incoming amount | Browser attempts to identify credit/deposit; positive amount required |
| Outgoing/debit amount | Browser excludes rows with debit; backend skips debit-only rows |
| Phone presence | Browser requires an extractable Kenyan phone; backend independently extracts and normalizes from narration/reference |
| Member matching | Phone match only; not name, member number, ID, or bank-account ownership |
| Duplicate check | In-file composite duplicate check, then server transaction-reference check; no human review step |
| Member creation | Automatic when no phone match in Treasurer-import mode |
| Contribution period | Transaction date month followed by later months; it does not first apply to historical arrears |
| Transaction category | `detectCategory` labels the bank ledger row as penalty/donation/monthly contribution, but the import still calls the monthly-contribution payment allocation path. The label does not route the money to a penalty or donation wallet. |
| Existing member profile | Does not overwrite existing name/phone/profile; updates financial records and potentially first contribution date |
| Error handling | A failed row is logged/reported and later rows continue. Processing is not wrapped as one all-or-nothing transaction across member, bank transaction, canonical payment, and legacy projection. |

## Important distinction: import vs bank sync

| Workflow | Mode | Unknown phone/member | Contribution processing |
|---|---|---|---|
| Treasurer statement import | `TREASURER_STATEMENT_IMPORT` | Creates a member and login identity | Processes confirmed bank payment and allocates monthly contributions |
| Bank statement sync/reconciliation | `BANK_STATEMENT_SYNC` | Records unknown/ambiguous transactions as unmatched; does not create a member | Reconciles pending STK payment when possible, otherwise uses the canonical payment engine |

Do not use the regular `/treasurer/bank-statement` sync action as a substitute for onboarding members from historical statements.

## Complete contribution matching and unmatched-resolution matrix

“Matched” has two distinct meanings in this application:

1. **Member matching** associates a statement transaction with a member, primarily by normalized phone.
2. **Financial reconciliation** identifies what the transaction paid for and posts it to the appropriate payment/contribution records.

A transaction may match a member and still have a financial conflict (for example, an STK conflict). Conversely, a real bank credit may have no identifiable member. The following queues and statuses are not interchangeable.

### Source → decision → records → resolution

| Stage / source | Result and why | Persisted records | Staff action / completion | Member dashboard effect |
|---|---|---|---|---|
| Browser statement parser | Candidate must have a valid date, positive credit, no debit, and extractable Kenyan phone. Duplicate candidates inside the workbook (`phone|reference|date|amount`) are dropped. | Preview only until import; workbook itself is not uploaded. | Review preview and submit. | No changes before server processing. |
| Treasurer spreadsheet import: existing phone | `StatementProcessor` normalizes phone and finds an existing member. Import mode selects the first phone match if multiple matches exist; it does not mark that as ambiguous. | `member_import_batches`, `member_import_rows`, `bank_transactions`, canonical `payments` and provider event, `monthly_obligations`, `contribution_allocations`, projected `contributions`. | Review batch/import history when needed. | Payment allocations are projected; `MemberFinancialProjectionService::syncMember` refreshes member totals/status. |
| Treasurer spreadsheet import: unknown phone | No existing phone match and mode is `TREASURER_STATEMENT_IMPORT`, which permits member creation. | Same import/payment ledgers, plus `auth_users`, `members`, `user_roles`. Import row becomes `new_member`. | Complete the normal member onboarding/password handoff. This import path does not itself deliver the initial password. | New member dashboard identity is created and the bank credit is allocated to contribution periods. |
| Treasurer import: missing phone | Neither narration nor reference yields a usable normalized phone. | `member_import_rows.status=skipped`, `match_status=no_phone`; batch invalid-phone count increments. This does not enter `bank_unmatched_transactions` in this import mode. | Review the source row and correct the data before any controlled retry; check the bank reference first to avoid reposting. | No member or contribution is created. |
| Treasurer import: debit-only/no credit | There is no positive incoming credit. | `member_import_rows.status=unmatched`; message identifies debit/no-credit. | Do not match as a contribution. Use the appropriate payout/expense workflow if the transaction is an outgoing payment. | No member contribution is added. |
| Treasurer import: duplicate | Existing bank transaction reference/fingerprint is found before member/payment processing. | `member_import_rows.status=skipped`, `match_status=duplicate`; batch duplicate counter increments. | Verify the prior transaction/payment rather than importing a second time. | No second posting is intentionally created. |
| Bank Sync: known phone, normal credit | `BANK_STATEMENT_SYNC` finds one member and there is no conflicting pending STK match. It confirms an eligible STK payment or submits a confirmed bank event to `PaymentEngine`. | `bank_transactions`, canonical payment/provider event, obligations/allocations and contribution projection; sync audit/batch. | Normally no manual action; verify bank transaction detail/reconciliation if needed. | Projection updates the dashboard stats and contribution history. |
| Bank Sync: unknown member | No member matches. Sync mode is forbidden from creating a member. | `bank_transactions.reconciliation_status=unmatched`; `bank_unmatched_transactions.status=pending`, reason `unknown_member`; a financial exception is recorded. | **Unmatched Payments → Unmatched Statement Transactions** → Match → select an existing member and choose `Contribution` for a credit. If not yet a member, create/onboard them first; this dialog selects existing members only. | Manual contribution reconciliation posts through the canonical payment engine, links the bank transaction, and closes the unmatched row. |
| Bank Sync: ambiguous phone | More than one member matches the phone; sync refuses to pick one. | Same bank unmatched queue, reason `ambiguous_member`; `ambiguous_bank_member` financial exception. | Verify identity and choose the correct existing member manually. Correct duplicate/incorrect phone records separately. | The transaction is assigned to the selected member only; no member profiles are automatically merged. |
| Bank Sync: pending STK conflict | Multiple STK candidates, conflict with failed/cancelled/reversed status, or unsuccessful bank confirmation. | Typed `financial_exceptions` record; the processor marks its `bank_transactions` row unmatched, but this conflict branch does not create a `bank_unmatched_transactions` row. | There is no matching staff-resolution action for this exception in the current Unmatched Payments UI. Investigate the linked member, amount, reference/checkout ID, time window and payment status; resolve the original payment/exception under an approved financial process, then verify before any retry. Do not create a second posting to force a match. | Do not treat it as a contribution until a successful canonical transition or separately authorized reconciliation is verified. |
| Legacy unmatched payment | `unmatched_payments.resolved=false` appears in the first table on **Unmatched Payments**. This is separate from the bank-sync queue. | `unmatched_payments` linked to `payments`; legacy period rows in `contributions`. | Choose Assign and select a member. Current UI updates payment/member and unresolved state, finds the earliest pending/overdue contribution, marks it paid, removes its penalty, and recomputes the legacy member total. | Legacy contribution/payment display is updated. This is an older multi-request frontend workflow; see the control caveat below. |
| Import-history review | Admin/Super Admin opens `/admin/import-history` or `/super-admin/import-history`. | Read-only API joins `member_import_batches` and `member_import_rows` to current members and `MemberStatsService` results. | Search/filter/paginate, then use the appropriate financial workflow to correct a real mismatch. The history page does not reconcile or mutate rows. | Shows import outcome and current paid/outstanding member status; read-only. |

### Cause/state → queue → supported resolution

| Cause/state | Where staff sees it | Supported resolution today | Close-out evidence |
|---|---|---|---|
| Bank Sync did not identify a member (`unknown_member`) | `bank_unmatched_transactions`, Unmatched Statement Transactions table | Select Match, an existing member, and a match type. For contribution, backend calls canonical payment/allocation handling. | `bank_unmatched_transactions.status=matched`; `bank_transactions.reconciliation_status=matched`; resolution actor/time/notes recorded. |
| Bank Sync phone matched multiple members (`ambiguous_member`) | Same bank unmatched queue and financial exception | Verify payer then select the correct existing member. This is not a member-merge feature. | Same as above; member identity cleanup is a separate operation. |
| STK/bank payment conflict | `financial_exceptions`; affected `bank_transactions` can be `unmatched`. This specific conflict branch does not add the row to the `bank_unmatched_transactions` staff list. | No dedicated staff resolution screen/action is wired for this exception in the current frontend. Investigate/resolve the original payment and exception under an approved financial process; do not assume the generic Match button can see or close it. | Canonical payment state and any linked contribution projection agree with the bank transaction, and the exception is independently accounted for. |
| Import row has no phone, debit/no credit, duplicate, or processing failure | `member_import_rows`, visible in Import History with status/message | History view is read-only and has no “match this row” or retry action. Correct the source/member information and use a controlled retry after checking the bank reference. | New valid processing outcome and financial records; viewing the old row does not mark it reconciled. |
| Legacy `unmatched_payments` item | First Unmatched Payments table | Assign the payment to a selected member. | `unmatched_payments.resolved=true`, payment/contribution/member projections independently verified. |

### API/action matrix

| Action | API path | Server/client path | Resulting record lifecycle |
|---|---|---|---|
| Upload parsed statement chunk | `POST /api/functions/v1/bank-statement-import` | `ImportController` → `ImportService` → `StatementProcessor` in `TREASURER_STATEMENT_IMPORT` mode | Creates batch/row audit; may create identity/member/role; creates bank ledger and canonical payment/allocation; projects monthly contribution rows. |
| Sync Co-op bank statement | `POST /api/banking/coop/account/sync` | `CoopAccountController` → `BankSyncService` → `StatementProcessor` in `BANK_STATEMENT_SYNC` mode | Writes matched financial transaction or unmatched queue/exception; never creates a member. |
| List bank-sync unmatched rows | `GET /api/banking/coop/unmatched-transactions?page=…&per_page=…` | `CoopAccountController::unmatchedStatementTransactions` | Read-only; returns `pending` and `investigating`. |
| Resolve bank-sync unmatched row | `POST /api/banking/coop/unmatched-transactions/{id}/reconcile` with `match_type`, `member_id`, optional `campaign_id`/`penalty_id` | `CoopAccountController::reconcileUnmatchedStatementTransaction`; locks and validates within a database transaction. Contribution path calls `BankSyncService::reconcileManually` → `PaymentEngine`. | Posts the selected financial type, associates the bank transaction, writes match audit, marks unmatched row `matched`. Identical repeats are idempotent; a different target after match is rejected. |
| List legacy unmatched payment rows | `GET /api/rest/v1/unmatched_payments?resolved=eq.false&order=created_at.desc` | `UnmatchedPayments.tsx` → generic REST API and table policy | Read-only listing. |
| Assign legacy unmatched payment | PATCH `/api/rest/v1/payments?...`, PATCH `/api/rest/v1/unmatched_payments?...`, then reads/updates `/api/rest/v1/contributions?...`, `/api/rest/v1/penalties?...`, `/api/rest/v1/members?...` | `UnmatchedPayments.tsx` client mutation (not one server transaction) | Assigns payment/member; marks unmatched resolved; attempts contribution payment/date/link, penalty removal, and member total recomputation. |
| Inspect statement import rows | `GET /api/admin/bank-statement-imports?q=…&status=…&page=…` | `BankStatementImportHistoryController` | Read-only paginated batch/row/member/current-stat view. No reconciliation or write action. |

### Complete bank contribution cycle

```text
Statement received
  → parse and preview in browser
  → submit in import mode OR sync mode
  → normalize date, credit/debit, reference, narration, and phone
  → duplicate check
  → member match
      ├─ import mode + no member → create auth user/member/member role, then continue
      ├─ sync mode + no member → unmatched queue + exception; stop contribution posting
      ├─ sync mode + multiple members → unmatched queue + exception; stop posting
      └─ one member → continue
  → pending STK reconciliation (sync path only)
      ├─ unique matching pending payment/transfer → confirm existing STK payment
      ├─ conflict/ambiguous → record financial exception and leave bank transaction unmatched; not necessarily visible in manual unmatched queue
      └─ no STK candidate → process confirmed bank event via PaymentEngine
  → allocate payment to monthly obligations/contribution allocations
  → project member/year/month rows to legacy contributions
  → sync member financial projection
  → member dashboard reads current summary + contribution history
  → save staff batch/row/sync audit
```

For an unresolved Bank Sync row that has a `bank_unmatched_transactions` record, the manual completion path is:

```text
pending bank_unmatched_transactions row
  → staff verifies bank reference, date, amount, narration, and member
  → chooses match type and existing target
  → backend locks/validates the unmatched row and corresponding bank transaction
  → posts the appropriate financial record (canonical contribution payment for contribution)
  → links member and marks bank transaction matched
  → marks unmatched row matched and records resolver/time/target audit
  → verify bank transaction detail and member dashboard totals/history
```

### Status fields are not interchangeable

| Table/field | Relevant states | Meaning |
|---|---|---|
| `member_import_rows.status` | `new_member`, `matched`, `unmatched`, `skipped`, `failed`, `pending` | Statement-import row outcome; not the bank ledger reconciliation state. |
| `member_import_rows.match_status` | `matched`, `unmatched`, `no_phone`, `duplicate`, `failed`, `pending` | Import processing/member-match outcome. |
| `bank_transactions.reconciliation_status` | `matched`, `unmatched` | Bank ledger reconciliation/member-association state. |
| `bank_unmatched_transactions.status` | `pending`, `investigating`, `matched` and other table-supported terminal states | Staff work-queue lifecycle for Bank Sync. |
| `payments.status` | `pending`, `confirmed`, `failed`, `cancelled`, `reversed` | Canonical/provider payment lifecycle; not the member’s monthly contribution status. |
| `contributions.status` / obligation | commonly `paid`, `partial`, `pending`, `overdue` | Period-level obligation state; one payment may allocate across several months. |
| Member dashboard summary | totals, outstanding, paid/pending months, due dates | Derived server-side financial projection/summary; use it rather than inferring status from one import row. |

### Verification and safety checkpoints

1. Find the batch/row by import batch ID, file, bank reference, or phone.
2. Verify `bank_transactions` contains the expected reference, member, credit/debit, source and reconciliation state.
3. Verify one canonical `payments` record/provider event exists for the reference and intended member; provider-event processing is intended to be idempotent.
4. For contributions, check `contribution_allocations` and `monthly_obligations`, then the corresponding member/month `contributions` projection and `payment_id`.
5. Confirm `MemberFinancialProjectionService` output and the member dashboard's separately fetched stats and contribution history.
6. For manual Bank Sync resolution, verify unmatched status, `resolved_by`, `resolved_at`, `resolution_notes`, and bank transaction match state.
7. Do not infer a successful contribution only from `member_import_rows.status=matched`, a bank ledger category label, or a positive raw credit.

**Control caveat:** Bank Sync unmatched reconciliation is a server-side database transaction and locks/validates the unmatched and bank transaction records, but only works when the `bank_unmatched_transactions` work-queue row exists. The pending-STK conflict branch currently records a financial exception and marks the bank ledger row unmatched without inserting that work-queue row; there is no dedicated UI action for that exception. The older `unmatched_payments` Assign action is a series of frontend REST mutations rather than one server transaction, and some request `{error}` values are not checked before the UI can report success. Independently verify the payment, contribution, unmatched, penalty, and member-total records after using that legacy action. These are separate paths.

## Relevant implementation and tests

- Frontend parser/page: `frontend/src/pages/treasurer/BankStatementImport.tsx`
- Admin/Super Admin import history (read-only): `frontend/src/pages/admin/BankStatementImportHistory.tsx`
- Unmatched payment queues and actions: `frontend/src/pages/admin/UnmatchedPayments.tsx`
- Request mapper: `frontend/src/api/imports.ts`
- Routes/controllers/requests: `khwwc/routes/api.php`, `ImportController.php`, `BankStatementImportHistoryController.php`, `BankStatementImportRequest.php`, `Banking/CoopAccountController.php`
- Batch orchestration: `ImportService.php`
- Row/member/payment processing: `Statement/StatementProcessor.php`
- Canonical payment allocation: `PaymentEngine.php`
- Legacy member totals: `MemberFinancialProjectionService.php`
- Bank Sync reconciliation: `BankSyncService.php`, `Statement/PendingStkBankReconciliation.php`
- Acceptance coverage: `tests/Unit/ImportServiceTest.php`, `tests/Feature/StatementWorkflowTest.php`, `tests/Feature/Banking/CoopBankTest.php`, `tests/Feature/Admin/MemberPayoutAndUnmatchedReconciliationTest.php`, `tests/Feature/BankStatementImportHistoryTest.php`

The tests specifically cover phone/name extraction, new-member creation and credentials, existing-member phone matching, contribution allocation (including partial month), duplicate skipping, and the rule that bank sync does not create members.
