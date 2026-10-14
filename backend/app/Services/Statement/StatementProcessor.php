<?php

declare(strict_types=1);

namespace App\Services\Statement;

use Carbon\Carbon;
use App\Models\AuthUser;
use App\Models\Member;
use App\Models\UserRole;
use App\Services\AuthService;
use App\Services\PaymentEngine;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Centralized processor for transactions coming from a bank statement.
 *
 * Two distinct modes are supported:
 *
 *  - TREASURER_STATEMENT_IMPORT
 *      Used by the Treasurer → Statement Import workflow. Imports historical
 *      statements, builds member + financial records. **May create new members.**
 *
 *  - BANK_STATEMENT_SYNC
 *      Used by the ongoing Bank Statements reconciliation screen. Only matches
 *      transactions to EXISTING members. **MUST NOT create new members.**
 *
 * The two modes share the same transaction parsing, phone normalization, member
 * matching, contribution allocation, and audit logic — but the member-creation
 * branch is hard-gated by StatementMode. The processor owns the rule, not the
 * controller, so that any controller / caller that delegates here automatically
 * inherits the correct policy.
 */
class StatementProcessor
{
    public function __construct(
        private readonly PaymentEngine $paymentEngine,
        private readonly PendingStkBankReconciliation $pendingStkReconciliation,
    ) {}

    /**
     * Process a single transaction row.
     *
     * @param array<string,mixed> $tx
     * @return array<string,mixed>  Result counters — caller aggregates.
     */
    public function process(array $tx, StatementMode $mode, StatementContext $ctx): ProcessOutcome
    {
        $outcome = new ProcessOutcome();

        // 1. Extract / normalize phone + name
        $tx = $this->normalizeRowShape($tx);

        $rawTransDate = $this->asString($tx['trans_date'] ?? null);
        $transDate = $rawTransDate;
        $valueDate = $this->asString($tx['value_date'] ?? null) ?: $transDate;
        $transDate = $this->normalizeDate($transDate);
        $valueDate = $this->normalizeDate($valueDate) ?: $transDate;
        $narration = $this->asString($tx['transaction_details'] ?? null);
        $reference = $this->asString($tx['reference_no'] ?? null);
        $credit = $this->asAmount($tx['credit'] ?? 0);
        $debit = $this->asAmount($tx['debit'] ?? 0);
        $bookBalance = $this->asAmount($tx['book_balance'] ?? 0);

        $outcome->credit = $credit;
        $outcome->debit = $debit;

        $extractedPhone = $this->extractPhone($narration) ?? $this->extractPhone($reference);
        $normalizedPhone = $extractedPhone ? AuthService::normalizePhone($extractedPhone) : null;
        $extractedName = $this->extractName($narration, $reference);

        $outcome->extractedPhone = $extractedPhone;
        $outcome->normalizedPhone = $normalizedPhone;
        $outcome->extractedName = $extractedName;

        if ($credit > 0) {
            $outcome->credits++;
            $outcome->totalAmount += $credit;
        }
        if ($debit > 0) {
            $outcome->debits++;
        }

        // 2. Pre-flight filters
        if (! $normalizedPhone) {
            $outcome->invalidPhones++;
            $outcome->reason = 'no_phone_extracted';
            if ($mode === StatementMode::BANK_STATEMENT_SYNC) {
                $this->recordUnmatchedBankTransaction(
                    ($reference ?: 'no-phone-' . $transDate . '-' . $credit),
                    $transDate,
                    $valueDate,
                    $credit,
                    $debit,
                    $extractedName,
                    '',
                    $narration,
                    $ctx->accountNumber,
                    $ctx->batchId,
                    $ctx->syncUserId,
                    'no_phone_extracted',
                );
            }
            return $outcome;
        }

        if ($debit > 0 && $credit <= 0) {
            $outcome->unmatched++;
            $outcome->reason = 'debit_only';
            return $outcome;
        }

        if ($credit <= 0) {
            $outcome->unmatched++;
            $outcome->reason = 'no_credit';
            return $outcome;
        }

        // 3. Build a stable transaction reference / fingerprint
        $txRef = $this->buildReference($reference, $normalizedPhone, $transDate, $credit, $debit);
        $tx['TransactionReference'] = $txRef;

        if ($this->isDuplicate($txRef, $tx)) {
            $outcome->duplicatesSkipped++;
            $outcome->reason = 'duplicate';
            return $outcome;
        }

        // 4. Match member by normalized phone
        $members = $this->findMembersByPhone($normalizedPhone);
        if ($mode === StatementMode::BANK_STATEMENT_SYNC && $members->count() > 1) {
            $outcome->unmatched++;
            $outcome->reason = 'ambiguous_member';
            $this->recordUnmatchedBankTransaction(
                $txRef,
                $transDate,
                $valueDate,
                $credit,
                $debit,
                $extractedName,
                $normalizedPhone,
                $narration,
                $ctx->accountNumber,
                $ctx->batchId,
                $ctx->syncUserId,
                'ambiguous_member',
            );
            return $outcome;
        }

        $member = $members->first();

        $isNewMember = false;
        $memberId = null;

        if ($member) {
            $memberId = $member->id;
            $outcome->matchedExisting++;
        } else {
            // HARD RULE — only Treasurer Statement Import may create members.
            if ($mode === StatementMode::TREASURER_STATEMENT_IMPORT) {
                $memberId = $this->createMemberFromPhone(
                    $normalizedPhone,
                    $extractedName,
                    $txRef,
                    $mode->value,
                    $transDate
                );
                $isNewMember = true;
                $outcome->newMembers++;
            } else {
                // BANK_STATEMENT_SYNC: record as unmatched, do NOT create member.
                $outcome->unmatched++;
                $outcome->reason = 'unknown_member';
                $this->recordUnmatchedBankTransaction(
                    $txRef,
                    $transDate,
                    $valueDate,
                    $credit,
                    $debit,
                    $extractedName,
                    $normalizedPhone,
                    $narration,
                    $ctx->accountNumber,
                    $ctx->batchId,
                    $ctx->syncUserId,
                );
                return $outcome;
            }
        }

        // 5. Update first_contribution_date if this is an earlier legitimate date
        $this->maybeUpdateFirstContributionDate($memberId, $transDate);

        // 6. Insert the bank_transactions ledger entry
        $bankTxId = Uuid::v4();
        DB::table('bank_transactions')->insert([
            'id' => $bankTxId,
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => $normalizedPhone,
            'name' => $extractedName ?: '',
            'amount' => max($credit, $debit),
            'transaction_date' => $transDate,
            'month' => $transDate ? (int) date('n', strtotime($transDate)) : null,
            'year' => $transDate ? (int) date('Y', strtotime($transDate)) : null,
            'transaction_reference' => $txRef,
            'mpesa_code' => null,
            'raw_details' => $narration,
            'bank_transaction_reference' => $txRef,
            'transaction_type' => $credit > 0 ? 'CREDIT' : 'DEBIT',
            'credit_amount' => $credit,
            'debit_amount' => $debit,
            'narration' => $narration,
            'account_number' => $ctx->accountNumber,
            'source' => $mode->value,
            'reconciliation_status' => 'matched',
            'member_matched_at' => now(),
            'match_method' => $isNewMember ? 'auto_created' : 'phone_match',
            'match_confidence' => 'high',
            'matched_by' => $ctx->syncUserId,
            'matched_at' => now(),
            'contribution_id' => null,
            'sync_batch_id' => $ctx->batchId,
            'raw_response' => json_encode($tx),
            'transaction_state' => 'completed',
            'category' => $this->detectCategory($narration),
            'updated_at' => now(),
            'bank_fingerprint' => $this->fingerprint($txRef, $tx),
            'created_at' => now(),
        ]);

        $stkReconciliation = $mode === StatementMode::BANK_STATEMENT_SYNC
            ? $this->pendingStkReconciliation->reconcile(
                $memberId,
                $normalizedPhone,
                (int) round($credit * 100),
                $rawTransDate ?: now()->toDateTimeString(),
                $txRef,
                $narration . ' ' . $reference,
            )
            : ['status' => 'none'];

        if ($stkReconciliation['status'] === 'reconciled') {
            $legacyContributionId = DB::table('contributions')
                ->where('payment_id', $stkReconciliation['payment_id'])
                ->value('id');
            DB::table('bank_transactions')->where('id', $bankTxId)->update([
                'contribution_id' => $legacyContributionId,
                'updated_at' => now(),
            ]);
            $outcome->contributionsCreated = $legacyContributionId ? 1 : 0;
            $outcome->memberId = $memberId;
            $outcome->bankTxId = $bankTxId;
            $outcome->wasMatched = true;
            $outcome->reason = 'matched_pending_stk';

            return $outcome;
        }

        if ($stkReconciliation['status'] === 'conflict') {
            DB::table('bank_transactions')->where('id', $bankTxId)->update([
                'reconciliation_status' => 'unmatched',
                'match_method' => 'stk_conflict',
                'match_confidence' => 'none',
                'updated_at' => now(),
            ]);
            $outcome->unmatched++;
            $outcome->memberId = $memberId;
            $outcome->bankTxId = $bankTxId;
            $outcome->reason = $stkReconciliation['reason'] ?? 'stk_reconciliation_conflict';

            return $outcome;
        }

        $canonicalPayment = $this->paymentEngine->processProviderEvent([
                'provider' => $mode === StatementMode::BANK_STATEMENT_SYNC ? 'bank' : 'bank_import',
                'provider_event_id' => $txRef,
                'event_type' => 'bank_confirmed',
                'member_id' => $memberId,
                'amount_minor' => (int) round($credit * 100),
                'provider_reference' => $txRef,
                'internal_reference' => $txRef,
                'source' => $mode === StatementMode::BANK_STATEMENT_SYNC ? 'bank_sync' : 'bank_import',
                'occurred_at' => $transDate ?: now()->toDateTimeString(),
                'payload' => $tx,
            ]);
        $legacyContributionId = $this->projectLegacyContributionRows($canonicalPayment['payment_id'], $memberId, $txRef, $transDate);
        DB::table('bank_transactions')->where('id', $bankTxId)->update([
            'contribution_id' => $legacyContributionId,
            'updated_at' => now(),
        ]);

        $outcome->contributionsCreated = $mode === StatementMode::TREASURER_STATEMENT_IMPORT
            ? DB::table('contribution_allocations')->where('payment_id', $canonicalPayment['payment_id'])->count()
            : ($legacyContributionId ? 1 : 0);

        $outcome->memberId = $memberId;
        $outcome->bankTxId = $bankTxId;
        $outcome->wasMatched = true;
        $outcome->reason = $isNewMember ? 'new_member_created' : 'matched_existing';

        return $outcome;
    }

    private function normalizeRowShape(array $tx): array
    {
        // Accept both raw Co-op API shape (TransactionReference, CreditAmount, ...)
        // and the legacy ImportService shape (trans_date, transaction_details, ...).
        if (! isset($tx['trans_date']) && isset($tx['TransactionDate'])) {
            $tx['trans_date'] = $tx['TransactionDate'];
            $tx['value_date'] = $tx['ValueDate'] ?? $tx['TransactionDate'];
            $tx['transaction_details'] = $tx['Narration'] ?? '';
            $tx['reference_no'] = $tx['TransactionReference'] ?? $tx['TransactionId'] ?? '';
            $tx['credit'] = $tx['CreditAmount'] ?? 0;
            $tx['debit'] = $tx['DebitAmount'] ?? 0;
            $tx['book_balance'] = $tx['BookBalance'] ?? $tx['RunningBalance'] ?? 0;
            $tx['transaction_time'] = $tx['TransactionTime'] ?? '';
        }

        // Some callers send nested extracted fields directly (frontend ParsedTx shape).
        // The frontend now sends through the mapper; this is a defence-in-depth fallback.
        if (! isset($tx['transaction_details']) && isset($tx['rawDetails'])) {
            $tx['transaction_details'] = trim(
                ($tx['name'] ?? '') . ' ' . ($tx['phone'] ?? '') . ' ' . ($tx['mpesaCode'] ?? '') . ' ' . $tx['rawDetails']
            );
        }
        if (! isset($tx['credit']) && isset($tx['amount'])) {
            $tx['credit'] = $tx['amount'];
        }
        if (! isset($tx['trans_date']) && isset($tx['date'])) {
            $tx['trans_date'] = $tx['date'];
            $tx['value_date'] = $tx['date'];
        }
        if (! isset($tx['reference_no']) && isset($tx['reference'])) {
            $tx['reference_no'] = $tx['reference'];
        }

        return $tx;
    }

    private function asString(mixed $v): ?string
    {
        if ($v === null) return null;
        $v = is_string($v) ? trim($v) : (string) $v;
        if ($v === '' || strtolower($v) === 'nan') return null;
        return $v;
    }

    private function asAmount(mixed $v): float
    {
        if (is_numeric($v)) return (float) $v;
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
        return (float) ($clean ?: 0);
    }

    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            Log::warning('StatementProcessor: invalid transaction date', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function extractPhone(?string $text): ?string
    {
        if (! $text) return null;
        if (preg_match('/(07\d{8}|01\d{8}|2547\d{8}|2541\d{8}|\+2547\d{8}|\+2541\d{8})/', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{9,12})/', $text, $m)) {
            $digits = $m[1];
            if (strlen($digits) >= 9) return $digits;
        }
        return null;
    }

    private function extractName(?string $narration, ?string $reference): ?string
    {
        $source = trim(($narration ?? '') . ' ' . ($reference ?? ''));
        if (! $source) return null;

        if (preg_match('/Confirm\.\s*([A-Za-z][A-Za-z\s\.]{2,60})/', $source, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^([A-Z][A-Za-z\s\.\-]{2,60})/', $source, $m)) {
            $name = trim($m[1]);
            $name = preg_replace('/^(M-PESA|Confirm\.|Payment from)\s*/i', '', $name);
            $name = preg_replace('/(07\d{8}|2547\d{8}|\+2547\d{8})/', '', $name);
            $name = preg_replace('/\b[A-Z0-9]{8,}\b/', '', $name);
            $name = preg_replace('/\d+/', '', $name);
            $name = preg_replace('/\s+/', ' ', trim($name));
            if (preg_match('/[A-Za-z]{2,}/', $name)) return $name;
        }
        return null;
    }

    private function buildReference(?string $reference, string $phone, ?string $date, float $credit, float $debit): string
    {
        if ($reference) return $reference;
        return $phone . '-' . ($date ?? 'nd') . '-' . $credit;
    }

    private function fingerprint(string $txRef, array $tx): ?string
    {
        if (! empty($tx['bank_fingerprint'])) return $tx['bank_fingerprint'];
        $date = $tx['trans_date'] ?? '';
        $time = $tx['transaction_time'] ?? '';
        $narration = substr((string) ($tx['transaction_details'] ?? ''), 0, 100);
        $credit = $tx['credit'] ?? 0;
        $debit = $tx['debit'] ?? 0;
        return md5($txRef . '|' . $date . '|' . $time . '|' . $credit . '|' . $debit . '|' . $narration);
    }

    private function isDuplicate(string $txRef, array $tx): bool
    {
        $q = DB::table('bank_transactions')->where('transaction_reference', $txRef);
        if (! empty($tx['bank_fingerprint'])) {
            $q->orWhere('bank_fingerprint', $tx['bank_fingerprint']);
        }
        return $q->exists();
    }

    private function findMembersByPhone(string $normalizedPhone): \Illuminate\Support\Collection
    {
        $candidate = $normalizedPhone;
        $alt0 = ltrim($normalizedPhone, '+');
        $altLocal = '0' . substr($alt0, 3);

        return DB::table('members')
            ->whereIn('phone', [$candidate, $alt0, $altLocal])
            ->get();
    }

    private function maybeUpdateFirstContributionDate(?string $memberId, ?string $date): void
    {
        if (! $memberId || ! $date) return;
        DB::table('members')
            ->where('id', $memberId)
            ->where(function ($q) use ($date) {
                $q->whereNull('first_contribution_date')
                    ->orWhere('first_contribution_date', '>', $date);
            })
            ->update(['first_contribution_date' => $date]);
    }

    private function createMemberFromPhone(
        string $normalizedPhone,
        ?string $name,
        string $bankRef,
        string $source,
        ?string $firstContributionDate
    ): string {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        $defaultPassword = 'Member2026';

        DB::transaction(function () use ($userId, $memberId, $normalizedPhone, $name, $defaultPassword, $bankRef, $source, $firstContributionDate) {
            AuthUser::create([
                'id' => $userId,
                'email' => AuthService::emailForPhone($normalizedPhone),
                'phone' => $normalizedPhone,
                'encrypted_password' => AuthService::hashPassword($defaultPassword),
                'must_change_password' => 1,
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Member::create([
                'id' => $memberId,
                'user_id' => $userId,
                'name' => $name ?: $normalizedPhone,
                'phone' => $normalizedPhone,
                'member_id' => $memberId,
                'is_active' => 1,
                'status' => 'active',
                'total_contributions' => 0,
                'total_penalties' => 0,
                'first_contribution_date' => $firstContributionDate,
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            UserRole::create([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => 'member',
                'is_active' => 1,
            ]);
        });

        Log::info('StatementProcessor: created new member', [
            'member_id' => $memberId,
            'phone' => $normalizedPhone,
            'source' => $source,
        ]);

        return $memberId;
    }

    private function recordUnmatchedBankTransaction(
        string $bankRef,
        ?string $date,
        ?string $valueDate,
        float $credit,
        float $debit,
        ?string $name,
        string $phone,
        ?string $narration,
        ?string $accountNumber,
        string $batchId,
        ?string $userId,
        string $reason = 'unknown_member',
    ): void {
        $txId = Uuid::v4();

        DB::table('bank_transactions')->insert([
            'id' => $txId,
            'bank_name' => 'coop',
            'member_id' => null,
            'phone' => $phone,
            'name' => $name ?: '',
            'amount' => $credit > 0 ? $credit : $debit,
            'transaction_date' => $date,
            'month' => $date ? (int) date('n', strtotime($date)) : null,
            'year' => $date ? (int) date('Y', strtotime($date)) : null,
            'transaction_reference' => $bankRef,
            'bank_transaction_reference' => $bankRef,
            'transaction_type' => $credit > 0 ? 'CREDIT' : 'DEBIT',
            'credit_amount' => $credit,
            'debit_amount' => $debit,
            'narration' => $narration,
            'account_number' => $accountNumber,
            'source' => 'bank_sync',
            'reconciliation_status' => 'unmatched',
            'member_matched_at' => null,
            'match_method' => null,
            'match_confidence' => null,
            'matched_by' => null,
            'matched_at' => null,
            'contribution_id' => null,
            'sync_batch_id' => $batchId,
            'raw_response' => json_encode(['reason' => $reason]),
            'transaction_state' => 'completed',
            'category' => $this->detectCategory($narration),
            'bank_fingerprint' => md5($bankRef . '|' . $date . '|' . $credit . '|' . $debit . '|' . ($narration ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_unmatched_transactions')->insert([
            'id' => Uuid::v4(),
            'bank_reference' => $bankRef,
            'transaction_date' => $date,
            'value_date' => $valueDate,
            'amount' => $credit > 0 ? $credit : $debit,
            'credit_amount' => $credit,
            'debit_amount' => $debit,
            'payer_name' => $name,
            'phone' => $phone,
            'narration' => $narration,
            'account_number' => $accountNumber,
            'sync_batch_id' => $batchId,
            'reason' => $reason,
            'status' => 'pending',
            'recorded_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(\App\Services\PaymentEngine::class)->createFinancialException($reason === 'ambiguous_member' ? 'ambiguous_bank_member' : 'unknown_bank_transaction', 'bank_sync', [
            'source_table' => 'bank_transactions',
            'source_id' => $txId,
            'external_reference' => $bankRef,
            'member_reference' => $phone ?: $name,
            'payload' => [
                'reason' => $reason,
                'narration' => $narration,
                'amount' => $credit > 0 ? $credit : $debit,
            ],
        ]);
    }

    private function projectLegacyContributionRows(string $paymentId, string $memberId, string $txRef, ?string $paymentDate): ?string
    {
        $allocations = DB::table('contribution_allocations as allocations')
            ->join('monthly_obligations as obligations', 'obligations.id', '=', 'allocations.obligation_id')
            ->where('allocations.payment_id', $paymentId)
            ->get([
                'allocations.amount_minor',
                'obligations.period_year',
                'obligations.period_month',
                'obligations.required_amount_minor',
                'obligations.paid_amount_minor',
            ]);

        $firstContributionId = null;
        foreach ($allocations as $allocation) {
            $amount = round((int) $allocation->amount_minor / 100, 2);
            $status = (int) $allocation->paid_amount_minor >= (int) $allocation->required_amount_minor ? 'paid' : 'partial';
            $existing = DB::table('contributions')
                ->where('member_id', $memberId)
                ->where('year', $allocation->period_year)
                ->where('month', $allocation->period_month)
                ->first();

            $values = [
                'amount' => $amount,
                'payment_ref' => $txRef,
                'paid_date' => $status === 'paid' ? $paymentDate : null,
                'status' => $status,
                'payment_id' => $paymentId,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('contributions')->where('id', $existing->id)->update($values);
                $firstContributionId ??= $existing->id;
                continue;
            }

            $contributionId = Uuid::v4();
            DB::table('contributions')->insert(array_merge($values, [
                'id' => $contributionId,
                'member_id' => $memberId,
                'month' => $allocation->period_month,
                'year' => $allocation->period_year,
                'due_date' => date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $allocation->period_year, $allocation->period_month))),
                'created_at' => now(),
            ]));
            $firstContributionId ??= $contributionId;
        }

        app(\App\Services\MemberFinancialProjectionService::class)->syncMember($memberId);

        return $firstContributionId;
    }

    private function detectCategory(?string $narration): string
    {
        if (! $narration) return 'monthly_contribution';
        $lower = strtolower($narration);
        if (str_contains($lower, 'penalty') || str_contains($lower, 'fine') || str_contains($lower, 'late')) {
            return 'penalty';
        }
        if (str_contains($lower, 'donation') || str_contains($lower, 'fund drive') || str_contains($lower, 'fundrive')) {
            return 'donation';
        }
        return 'monthly_contribution';
    }
}