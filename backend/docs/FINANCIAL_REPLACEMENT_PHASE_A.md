# Financial Replacement Phase A

## Scope

This phase is read-only. It documents ownership and provides duplicate inventory before any financial schema constraint or posting-engine migration is applied.

Production data, configuration, queues, bank synchronization, and STK flows are not changed by this phase.

## Canonical Ownership

| Table | Current role | Phase A disposition | Target owner |
| --- | --- | --- | --- |
| `members` | Member identity and denormalized financial totals | Keep; totals become projections | Member domain and financial read model |
| `payments` | Generic payment record | Extend; canonical payment authority | `PaymentEngine` |
| `contributions` | Monthly contribution rows | Preserve as compatibility projection | `ContributionAllocationEngine` |
| `bank_transactions` | Raw bank evidence and matching state | Keep; add canonical payment linkage | `BankTransactionIngestionService` |
| `coop_transfer_requests` | Provider transfer lifecycle | Keep; provider workflow only | Provider adapter and payment lifecycle |
| `coop_transfer_responses` | Provider response evidence | Keep; immutable evidence | Provider event store |
| `coop_transfers` | Transfer state | Keep; link to canonical payment where applicable | Transfer lifecycle service |
| `coop_transfer_events` | Transfer event history | Keep; deduplicate events | Provider event store |
| `wallet_transactions` | Wallet movements | Keep; ledger-backed projection | `FinancialLedgerService` |
| `penalties` | Member penalty obligations | Keep; projection of obligations | `MonthlyObligationService` |
| `penalty_payments` | Legacy penalty payments | Preserve; migrate references | `PaymentEngine` |
| `penalty_payment_records` | Penalty STK intent/status | Preserve; migrate provider evidence | `StkPaymentLifecycleService` |
| `donation_payment_records` | Donation STK intent/status | Preserve; migrate provider evidence | `PaymentEngine` |
| `operational_payment_records` | Operational STK intent/status | Preserve; migrate provider evidence | `PaymentEngine` |
| `campaign_contributions` | Campaign allocations | Keep as projection | `FinancialLedgerService` |
| `member_import_batches` | Import audit | Keep | Bank/import workflow |
| `member_import_rows` | Import row evidence | Keep | Financial exception workflow |
| `statement_processing_audit` | Statement processing audit | Keep | Bank/import workflow |
| `members.total_contributions` | Cached total | Projection only; never authoritative | `FinancialReadModelService` |

## Canonical Payment Contract

The existing `payments` table will be extended rather than replaced. Required normalized fields are:

```text
source: bank|stk|manual|donation|penalty|operational
status: pending|successful|failed|cancelled|unknown|reversed
amount_minor
currency
provider_name
provider_reference
internal_reference
transaction_reference
fingerprint
received_at
occurred_at
confirmed_at
```

Only `successful` payments may allocate contributions, post ledger entries, update paid-month state, or appear in successful totals.

## Required New Concepts

These require new tables or equivalent durable storage after duplicate review:

- provider events;
- contribution allocations;
- monthly obligations;
- carry-forward balances;
- append-only ledger entries;
- financial exceptions.

No uniqueness migration is safe until the duplicate inventory command has been reviewed.

## Duplicate Review

Run locally against a safe database copy:

```text
php artisan financial:inventory-duplicates
```

The command reports duplicate provider references, transaction references, payment fingerprints, bank fingerprints, STK checkout identifiers, and member-period contribution rows. It does not update or delete data.

## Implementation Order

1. Review this ownership contract and duplicate report.
2. Extend `payments` locally with nullable compatibility fields.
3. Add provider-event and financial-exception storage.
4. Implement the canonical payment engine behind tests.
5. Implement allocation, obligations, carry-forward, and ledger posting.
6. Route bank import/sync and STK polling through the engine.
7. Replace dashboard, statement, admin, and monitoring reads.
8. Rehearse historical migration and reconciliation.
9. Approve controlled deployment and rollback.
