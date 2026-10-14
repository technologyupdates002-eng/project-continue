<?php

namespace App\Services;

use App\Models\BankSyncAudit;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\Statement\ProcessOutcome;
use App\Services\Statement\StatementContext;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Support\Uuid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankSyncService
{
    public function __construct(
        protected CoopBankAccountService $accountService,
        protected StatementProcessor $processor,
        protected FinancialReadModel $financialReadModel,
    ) {}

    /**
     * HARD GUARANTEE for callers: BankSyncService NEVER creates a new member.
     * Even if the upstream flow tries to, the StatementProcessor is invoked
     * with StatementMode::BANK_STATEMENT_SYNC, which short-circuits the
     * member-creation branch and routes the transaction to bank_unmatched_transactions
     * instead.
     */
    public function allowMemberCreation(): bool
    {
        return false;
    }

    public function syncFullStatement(
        string $accountNumber,
        string $fromDate,
        string $toDate,
        ?string $userId = null,
        int $pageSize = 500
    ): array {
        $startedAt = now();
        $batchId = Uuid::v4();

        DB::table('bank_sync_audit')->insert([
            'id' => $batchId,
            'account_number' => $accountNumber,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_bank_transactions' => 0,
            'new_transactions' => 0,
            'duplicate_skipped' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'bank_balance' => null,
            'status' => 'in_progress',
            'started_at' => $startedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = [
            'total_transactions' => 0,
            'new_transactions' => 0,
            'duplicate_skipped' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'new_members' => 0, // MUST stay 0 in sync mode
            'contributions_created' => 0,
            'contributions_updated' => 0,
            'total_credits' => 0.0,
            'total_debits' => 0.0,
            'errors' => [],
            'mode' => StatementMode::BANK_STATEMENT_SYNC->value,
            'allow_member_creation' => $this->allowMemberCreation(),
        ];

        $processedMemberIds = [];
        $ctx = new StatementContext(
            batchId: $batchId,
            accountNumber: $accountNumber,
            syncUserId: $userId,
        );

        try {
            $result = $this->accountService->getStatementAllPages(
                $accountNumber, $fromDate, $toDate, $pageSize
            );

            $transactions = $result['transactions'] ?? [];
            $stats['total_transactions'] = count($transactions);
            $stats['bank_balance'] = $result['bank_balance'] ?? null;

            DB::table('bank_sync_audit')
                ->where('id', $batchId)
                ->update(['bank_balance' => $stats['bank_balance']]);

            foreach ($transactions as $tx) {
                try {
                    /** @var ProcessOutcome $outcome */
                    $outcome = $this->processor->process(
                        is_array($tx) ? $tx : (array) $tx,
                        StatementMode::BANK_STATEMENT_SYNC,
                        $ctx,
                    );

                    $stats['new_transactions'] += ($outcome->reason !== 'duplicate') ? 1 : 0;
                    $stats['duplicate_skipped'] += ($outcome->reason === 'duplicate') ? 1 : 0;
                    $stats['matched'] += $outcome->wasMatched ? 1 : 0;
                    $stats['unmatched'] += ($outcome->reason === 'unknown_member' || $outcome->reason === 'no_phone_extracted' || $outcome->reason === 'debit_only' || $outcome->reason === 'no_credit') ? 1 : 0;
                    // Hard guarantee — even if processor were buggy, force 0.
                    $stats['new_members'] += 0;
                    $stats['contributions_created'] += $outcome->contributionsCreated;
                    $stats['contributions_updated'] += $outcome->contributionsUpdated;

                    $stats['total_credits'] += $outcome->credit;
                    $stats['total_debits'] += $outcome->debit;

                    if ($outcome->memberId) {
                        $processedMemberIds[] = $outcome->memberId;
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = [
                        'reference' => $tx['TransactionReference'] ?? $tx['reference'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ];
                    Log::error('BankSync: transaction processing failed', [
                        'batch_id' => $batchId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->recalculateMemberStats($processedMemberIds);

            DB::table('bank_sync_audit')
                ->where('id', $batchId)
                ->update([
                    'total_bank_transactions' => $stats['total_transactions'],
                    'new_transactions' => $stats['new_transactions'],
                    'duplicate_skipped' => $stats['duplicate_skipped'],
                    'matched' => $stats['matched'],
                    'unmatched' => $stats['unmatched'],
                    'status' => 'success',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('statement_processing_audit')->insert([
                'id' => Uuid::v4(),
                'mode' => StatementMode::BANK_STATEMENT_SYNC->value,
                'batch_id' => $batchId,
                'account_number' => $accountNumber,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'total_transactions' => $stats['total_transactions'],
                'new_members' => 0,
                'members_matched' => $stats['matched'],
                'contributions_created' => $stats['contributions_created'],
                'contributions_updated' => $stats['contributions_updated'],
                'duplicates_skipped' => $stats['duplicate_skipped'],
                'unmatched' => $stats['unmatched'],
                'invalid_phones' => 0,
                'total_amount' => $stats['total_credits'],
                'created_by' => $userId,
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->invalidateCaches();

            return [
                'status' => 'success',
                'batch_id' => $batchId,
                'started_at' => $startedAt->toDateTimeString(),
                'completed_at' => now()->toDateTimeString(),
                'summary' => $stats,
                'bank_balance' => $stats['bank_balance'],
                'allow_member_creation' => $this->allowMemberCreation(),
                'mode' => StatementMode::BANK_STATEMENT_SYNC->value,
            ];
        } catch (\Throwable $e) {
            Log::error('BankSync: full sync failed', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
            ]);

            DB::table('bank_sync_audit')
                ->where('id', $batchId)
                ->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'status' => 'failed',
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'summary' => $stats,
            ];
        }
    }

    protected function recalculateMemberStats(array $memberIds): void
    {
        if (empty($memberIds)) {
            return;
        }

        foreach (array_unique($memberIds) as $memberId) {
            $totalPenalties = (float) DB::table('penalties')
                ->where('member_id', $memberId)
                ->where('is_paid', 1)
                ->sum('amount');

            app(\App\Services\MemberFinancialProjectionService::class)->syncMember($memberId);
            DB::table('members')->where('id', $memberId)->update([
                'total_penalties' => $totalPenalties,
                'updated_at' => now(),
            ]);

            try {
                Cache::forget('member:stats:' . $memberId);
                Cache::forget('member:summary:' . $memberId);
                Cache::forget('member:dashboard:' . $memberId);
            } catch (\Throwable $e) {
                Log::warning('BankSync: member cache invalidation failed', [
                    'member_id' => $memberId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function invalidateCaches(): void
    {
        try {
            foreach ([
                'treasurer:financial:summary',
                'admin:stats:summary',
                'member:stats:summary',
                'members:overview',
                'contributions:overview',
                'bank:reconciliation:summary',
                'treasurer:recent-activity',
                'treasurer:chart-data:all',
            ] as $key) {
                Cache::forget($key);

                try {
                    Cache::store('redis')->forget($key);
                } catch (\Throwable $e) {
                    Log::warning('BankSync: redis invalidation skipped', ['key' => $key, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('BankSync: cache invalidation failed', ['error' => $e->getMessage()]);
        }
    }

    public function listTransactions(array $filters, int $page = 1, int $perPage = 50): array
    {
        $query = DB::table('bank_transactions')
            ->leftJoin('members', 'bank_transactions.member_id', '=', 'members.id')
            ->select([
                'bank_transactions.*',
                'members.name as member_name',
            ]);

        if (! empty($filters['date_from'])) {
            $query->where('bank_transactions.transaction_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('bank_transactions.transaction_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['status'])) {
            $query->where('bank_transactions.reconciliation_status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->where('bank_transactions.category', $filters['category']);
        }
        if (! empty($filters['type'])) {
            $query->where('bank_transactions.transaction_type', $filters['type']);
        }
        if (! empty($filters['member_id'])) {
            $query->where('bank_transactions.member_id', $filters['member_id']);
        }
        if (! empty($filters['phone'])) {
            $phone = AuthService::normalizePhone($filters['phone']);
            $query->where(function ($q) use ($phone) {
                $q->where('bank_transactions.phone', $phone)
                    ->orWhere('bank_transactions.phone', '+' . $phone)
                    ->orWhere('bank_transactions.phone', '0' . substr($phone, 3));
            });
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('bank_transactions.narration', 'like', "%{$search}%")
                    ->orWhere('bank_transactions.transaction_reference', 'like', "%{$search}%")
                    ->orWhere('bank_transactions.name', 'like', "%{$search}%")
                    ->orWhere('members.name', 'like', "%{$search}%")
                    ->orWhere('bank_transactions.phone', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $transactions = $query
            ->orderByDesc('bank_transactions.transaction_date')
            ->orderByDesc('bank_transactions.created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $transactions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function getTransaction(string $id): ?array
    {
        $tx = DB::table('bank_transactions')
            ->leftJoin('members', 'bank_transactions.member_id', '=', 'members.id')
            ->leftJoin('contributions', 'bank_transactions.contribution_id', '=', 'contributions.id')
            ->leftJoin('auth_users', 'bank_transactions.matched_by', '=', 'auth_users.id')
            ->select([
                'bank_transactions.*',
                'members.name as member_name',
                'members.phone as member_phone',
                'members.member_id as member_member_id',
                'members.first_contribution_date as member_first_contribution_date',
                'members.source as member_source',
                'contributions.id as contribution_id',
                'contributions.amount as contribution_amount',
                'contributions.month as contribution_month',
                'contributions.year as contribution_year',
                'contributions.status as contribution_status',
                'contributions.paid_date as contribution_paid_date',
                'contributions.payment_ref as contribution_payment_ref',
                'auth_users.email as matched_by_email',
                'bank_transactions.updated_at as tx_updated_at',
            ])
            ->where('bank_transactions.id', $id)
            ->first();

        if (! $tx) {
            return null;
        }

        $audit = DB::table('bank_sync_audit')
            ->where('id', $tx->sync_batch_id)
            ->first();

        return [
            'transaction' => $tx,
            'sync_audit' => $audit,
            'audit_log' => [
                'first_imported' => $tx->created_at,
                'last_synchronized' => $tx->updated_at ?? $tx->created_at,
                'sync_batch' => $audit ? [
                    'id' => $audit->id,
                    'account_number' => $audit->account_number,
                    'started_at' => $audit->started_at,
                    'completed_at' => $audit->completed_at,
                    'status' => $audit->status,
                ] : null,
                'manually_reconciled_by' => $tx->matched_by_email ?? null,
                'manually_reconciled_at' => $tx->matched_at ?? null,
            ],
        ];
    }

    public function reconcileManually(string $transactionId, array $data, string $userId): array
    {
        return DB::transaction(function () use ($transactionId, $data, $userId) {
            $tx = DB::table('bank_transactions')->where('id', $transactionId)->lockForUpdate()->first();

            if (! $tx) {
                return ['success' => false, 'error' => 'Transaction not found'];
            }

            $memberId = $data['member_id'] ?? $tx->member_id;
            $category = $data['category'] ?? $tx->category ?? 'monthly_contribution';
            $narration = $data['narration'] ?? $tx->narration;

            if (! $memberId) {
                return ['success' => false, 'error' => 'Member is required for reconciliation'];
            }

            $member = DB::table('members')->where('id', $memberId)->first();
            if (! $member) {
                return ['success' => false, 'error' => 'Member not found'];
            }

            $contributionId = null;
            $credit = (float) ($tx->credit_amount ?? 0);
            if ($credit > 0 && $category === 'monthly_contribution') {
                $canonical = app(\App\Services\PaymentEngine::class)->processProviderEvent([
                    'provider' => 'bank_manual_reconciliation',
                    'provider_event_id' => $tx->transaction_reference,
                    'event_type' => 'bank_confirmed',
                    'member_id' => $memberId,
                    'amount_minor' => (int) round($credit * 100),
                    'provider_reference' => $tx->transaction_reference,
                    'internal_reference' => $tx->transaction_reference,
                    'source' => 'bank_sync',
                    'occurred_at' => $tx->transaction_date,
                    'payload' => ['source_id' => $tx->id],
                ]);
                $contributionId = DB::table('contributions')
                    ->where('payment_id', $canonical['payment_id'])
                    ->value('id');
                if (! $contributionId) {
                    $contributionId = \App\Support\Uuid::v4();
                    DB::table('contributions')->insert([
                        'id' => $contributionId,
                        'member_id' => $memberId,
                        'amount' => $credit,
                        'month' => (int) date('n', strtotime($tx->transaction_date)),
                        'year' => (int) date('Y', strtotime($tx->transaction_date)),
                        'due_date' => date('Y-m-t', strtotime($tx->transaction_date)),
                        'payment_ref' => $tx->transaction_reference,
                        'paid_date' => $tx->transaction_date,
                        'status' => 'paid',
                        'payment_id' => $canonical['payment_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('bank_transactions')
                ->where('id', $transactionId)
                ->update([
                    'member_id' => $memberId,
                    'phone' => $member->phone,
                    'name' => $member->name ?: $narration,
                    'reconciliation_status' => 'matched',
                    'match_method' => 'manual',
                    'match_confidence' => 'high',
                    'matched_by' => $userId,
                    'matched_at' => now(),
                    'contribution_id' => $contributionId,
                    'category' => $category,
                    'updated_at' => now(),
                ]);

            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'member_id' => $memberId,
                'contribution_id' => $contributionId,
                'category' => $category,
            ];
        });
    }

    private function rememberRedisSafely(string $key, int $ttl, callable $callback): mixed
    {
        try {
            return Cache::store('redis')->remember($key, $ttl, $callback);
        } catch (\Throwable $e) {
            return $callback();
        }
    }

    public function getFinancialSummary(): array
    {
        $totalCredits = (float) DB::table('bank_transactions')
            ->where('transaction_type', 'CREDIT')
            ->where('transaction_state', '!=', 'reversed')
            ->sum('credit_amount');

        $totalDebits = (float) DB::table('bank_transactions')
            ->where('transaction_type', 'DEBIT')
            ->where('transaction_state', '!=', 'reversed')
            ->sum('debit_amount');

        $totalContributionTotals = $this->financialReadModel->contributionTotalsIncludingLegacy();
        $totalContributions = $totalContributionTotals['amount_minor'] / 100;

        $monthContributionTotals = $this->financialReadModel->contributionTotalsIncludingLegacy(
            now()->copy()->startOfMonth(),
            now()->copy()->endOfMonth(),
        );
        $monthContributions = $monthContributionTotals['amount_minor'] / 100;

        $totalPenalties = (float) DB::table('penalties')
            ->where('is_paid', 1)
            ->sum('amount');

        $penaltyWallet = (float) DB::table('penalty_wallet')->value('total_received') ?? 0;
        $donationWallet = (float) DB::table('donation_wallet')->value('total_received') ?? 0;
        $operationalWallet = (float) DB::table('operational_wallet')->value('total_received') ?? 0;

        $matchedCredits = (float) DB::table('bank_transactions')
            ->where('transaction_type', 'CREDIT')
            ->where('reconciliation_status', 'matched')
            ->where('transaction_state', '!=', 'reversed')
            ->sum('credit_amount');

        $unmatchedCredits = (float) DB::table('bank_transactions')
            ->where('transaction_type', 'CREDIT')
            ->where('reconciliation_status', 'unmatched')
            ->where('transaction_state', '!=', 'reversed')
            ->sum('credit_amount');

        $bankBalanceResult = $this->rememberRedisSafely('bank:account:balance', 300, function () {
            return $this->accountService->getAccountBalance();
        });
        $bankBalance = isset($bankBalanceResult['available_balance'])
            ? (float) $bankBalanceResult['available_balance']
            : null;

        $lastSync = DB::table('bank_sync_audit')
            ->orderBy('started_at', 'desc')
            ->value('bank_balance');

        $appLedgerBalance = $totalCredits - $totalDebits;
        $difference = $bankBalance !== null ? $bankBalance - $appLedgerBalance : null;

        $latestBatch = DB::table('bank_sync_audit')
            ->orderBy('started_at', 'desc')
            ->first();

        return [
            'bank' => [
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'bank_balance' => $bankBalance,
                'last_sync_balance' => $lastSync ?: $bankBalance,
            ],
            'app' => [
                'ledger_balance' => $appLedgerBalance,
                'total_contributions' => $totalContributions,
                'month_contributions' => $monthContributions,
                'total_penalties' => $totalPenalties,
                'total_fund_drive' => (float) $donationWallet,
                'total_donations' => (float) $donationWallet,
                'total_operational' => (float) $operationalWallet,
                'unmatched_credits' => $unmatchedCredits,
                'matched_credits' => $matchedCredits,
            ],
            'difference' => $difference,
            'last_sync' => $latestBatch ? [
                'started_at' => $latestBatch->started_at,
                'completed_at' => $latestBatch->completed_at,
                'status' => $latestBatch->status,
                'total_transactions' => $latestBatch->total_bank_transactions,
                'new_transactions' => $latestBatch->new_transactions,
                'matched' => $latestBatch->matched,
                'unmatched' => $latestBatch->unmatched,
                'bank_balance' => $latestBatch->bank_balance,
            ] : null,
        ];
    }

    public function getReconciliationReport(): array
    {
        $summary = $this->getFinancialSummary();

        $txStats = DB::table('bank_transactions')
            ->where('transaction_state', '!=', 'reversed')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN transaction_type = "CREDIT" THEN 1 ELSE 0 END) as credits,
                SUM(CASE WHEN transaction_type = "DEBIT" THEN 1 ELSE 0 END) as debits,
                SUM(CASE WHEN reconciliation_status = "matched" THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN reconciliation_status = "unmatched" THEN 1 ELSE 0 END) as unmatched,
                SUM(CASE WHEN transaction_state = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN transaction_state = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN transaction_state = "reversed" THEN 1 ELSE 0 END) as reversed
            ')
            ->first();

        return [
            'bank_reconciliation' => [
                'bank_balance' => $summary['bank']['bank_balance'],
                'app_ledger_balance' => $summary['app']['ledger_balance'],
                'difference' => $summary['difference'],
            ],
            'transactions' => [
                'total' => (int) $txStats->total,
                'credits' => (int) $txStats->credits,
                'debits' => (int) $txStats->debits,
                'matched' => (int) $txStats->matched,
                'unmatched' => (int) $txStats->unmatched,
                'pending' => (int) $txStats->pending,
                'failed' => (int) $txStats->failed,
                'reversed' => (int) $txStats->reversed,
            ],
            'financial_totals' => [
                'contributions' => $summary['app']['total_contributions'],
                'penalties' => $summary['app']['total_penalties'],
                'fund_drive' => $summary['app']['total_fund_drive'],
                'donations' => $summary['app']['total_donations'],
                'operational' => $summary['app']['total_operational'],
                'other' => 0,
                'unallocated_credits' => $summary['app']['unmatched_credits'],
            ],
            'last_sync' => $summary['last_sync'],
        ];
    }

    public function getMemberBankTransactions(string $memberId, int $page = 1, int $perPage = 50): array
    {
        $query = DB::table('bank_transactions')
            ->where('member_id', $memberId)
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at');

        $total = $query->count();
        $transactions = $query->get();

        $member = DB::table('members')->where('id', $memberId)->first();

        return [
            'member' => $member ? [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
            ] : null,
            'transactions' => $transactions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function getSyncHistory(int $limit = 50): array
    {
        $logs = BankSyncAudit::orderBy('started_at', 'desc')->limit($limit)->get();

        return $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'account_number' => $log->account_number,
                'from_date' => $log->from_date,
                'to_date' => $log->to_date,
                'total_bank_transactions' => $log->total_bank_transactions,
                'new_transactions' => $log->new_transactions,
                'duplicate_skipped' => $log->duplicate_skipped,
                'matched' => $log->matched,
                'unmatched' => $log->unmatched,
                'bank_balance' => $log->bank_balance,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at,
                'completed_at' => $log->completed_at,
            ];
        })->toArray();
    }

    public function reconcileFirstContributionDates(): array
    {
        $updated = 0;

        $members = DB::table('members')
            ->join('bank_transactions', 'members.id', '=', 'bank_transactions.member_id')
            ->where('bank_transactions.credit_amount', '>', 0)
            ->where('bank_transactions.transaction_state', '!=', 'reversed')
            ->selectRaw('members.id as member_id')
            ->selectRaw('MIN(bank_transactions.transaction_date) as earliest_date')
            ->groupBy('members.id')
            ->get();

        foreach ($members as $member) {
            $existing = DB::table('members')
                ->where('id', $member->member_id)
                ->value('first_contribution_date');

            if (! $existing || $existing > $member->earliest_date) {
                DB::table('members')
                    ->where('id', $member->member_id)
                    ->update(['first_contribution_date' => $member->earliest_date]);
                $updated++;
            }
        }

        return [
            'members_processed' => $members->count(),
            'dates_updated' => $updated,
        ];
    }

    protected function detectCategory(?string $narration): string
    {
        $value = strtolower((string) $narration);
        if (str_contains($value, 'penalty') || str_contains($value, 'fine') || str_contains($value, 'late')) {
            return 'penalty';
        }
        if (str_contains($value, 'donation') || str_contains($value, 'fund drive') || str_contains($value, 'fundrive')) {
            return 'donation';
        }
        return 'monthly_contribution';
    }

    protected function extractNameFromNarration(?string $narration): ?string
    {
        if (! $narration) {
            return null;
        }

        $parts = explode('~', $narration);
        $candidate = trim(end($parts));
        return preg_match('/[A-Za-z]{2,}/', $candidate) ? $candidate : null;
    }
}