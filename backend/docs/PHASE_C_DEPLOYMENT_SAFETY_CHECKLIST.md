# Phase C Deployment Safety Checklist

This checklist is preparation only. It does not authorize deployment, migration, backfill, or live provider traffic.

## Data preservation

| Check | Status | Evidence / blocker |
|---|---|---|
| Existing members preserved | BLOCKED | Requires read-only inspection of the target production backup/database. |
| Existing contributions preserved | BLOCKED | Legacy-to-canonical crosswalk requires the target data inventory. |
| Existing payments preserved | PASS | Canonical payment flow preserves existing rows; no destructive migration is defined. |
| Existing wallets preserved | PASS | Wallet transactions remain separate ledger records; no conversion to payments is planned. |
| Existing penalties preserved | PASS | Penalties remain penalty records and are read separately from contribution payments. |
| Existing bank transactions preserved | PASS | Bank records remain import/reconciliation history; unmatched records are not converted silently. |
| Existing STK records preserved | PASS | Legacy callback is a no-op; polling remains authoritative. |

## Canonical financial path

| Check | Status | Evidence / blocker |
|---|---|---|
| PaymentEngine is the payment lifecycle authority | PASS | Verified by PaymentEngine and reconciliation suites. |
| Monthly obligations are canonical where present | PASS | FinancialReadModel and ContributionObligationService use canonical obligations when available. |
| Allocations are canonical and idempotent | PASS | Allocation and reconciliation tests pass. |
| Carry-forward is allocation-derived | PASS | Carry-forward tests pass; no amount-only backfill is planned. |
| Financial exceptions are retained | PASS | Conflict and unmatched reconciliation paths record safe exceptions/no-ops. |
| FinancialReadModel is the current-truth read path | PASS | Member financial, admin reports, treasurer charts, statements, and consistency tests use it. |
| Idempotency is verified | PASS | Provider events, reconciliation, wallet, withdrawal, and payment lifecycle checks pass in targeted suites. |

## User interfaces and reporting

| Check | Status | Evidence / blocker |
|---|---|---|
| Member dashboard | BLOCKED | Frontend build and complete consumer audit remain to be run. |
| Admin member view | PASS | Backend financial endpoint returns FinancialReadModel data. |
| Statements | PASS | Canonical statement path is covered by FinancialReadModel tests. |
| Contribution page | BLOCKED | Requires complete frontend consumer audit/build. |
| Transaction page | BLOCKED | Requires complete frontend consumer audit/build. |
| Bank monitoring | PASS | Targeted reconciliation/bank monitoring tests pass; production-data verification remains required. |
| STK monitoring | PASS | Pending/status polling path is tested; callback is non-financial. |
| Reports | PASS | Admin/Super Admin targeted reports use FinancialReadModel for contribution totals. |

## Operations

| Check | Status | Evidence / blocker |
|---|---|---|
| Verified production backup | BLOCKED | Production backup is not being executed in this phase; local backup test has an environment-specific dump hang. |
| Migrations | BLOCKED | Must be reviewed against a verified production schema before deployment. |
| Queue | BLOCKED | Requires deployment-environment verification. |
| Scheduler | BLOCKED | Requires deployment-environment verification. |
| Redis | PASS | Required local Redis service was restored for targeted verification. |
| Logs and financial exceptions | PASS | Conflict/unmatched paths are explicitly recorded. |
| Rollback plan | BLOCKED | Requires the approved deployment artifact and backup location. |

## Transition controls

- Run `php artisan financial:transition-audit --dry-run` equivalent behavior before any write-capable backfill is introduced. The current command is read-only and does not accept a write mode.
- Review every unresolved and conflict record before approving any deterministic backfill.
- Never create a payment from a legacy contribution when an existing payment relationship is absent without explicit reconciliation approval.
- Never derive obligations, allocations, carry-forward, wallet payments, or penalties from amount-only heuristics.
- Do not delete, truncate, or replace legacy financial tables.
- Do not enable real STK, B2C, bank synchronization, or reconciliation traffic during local verification.
