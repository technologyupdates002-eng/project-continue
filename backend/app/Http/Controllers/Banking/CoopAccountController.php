<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Services\BankSyncService;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoopAccountController extends Controller
{
    public function __construct(
        protected CoopBankAccountService $accountService,
        protected BankSyncService $bankSyncService,
        protected ?CoopBankReconciliationService $reconciliationService = null
    ) {}

    public function balance(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $accountNumber = $request->query('account_number') ?? config('coop_bank.account_number');

        try {
            $result = $this->accountService->getAccountBalance($accountNumber);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'balance_enquiry_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function miniStatement(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $accountNumber = $request->query('account_number') ?? config('coop_bank.account_number');

        try {
            $result = $this->accountService->getMiniStatement($accountNumber);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'mini_statement_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function statement(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $data = $request->validate([
            'account_number' => ['nullable', 'string'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $accountNumber = $data['account_number'] ?? config('coop_bank.account_number');

        try {
            $result = $this->accountService->getStatement($accountNumber, $data['from_date'], $data['to_date']);

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'statement_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function reconciliationStatus(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        try {
            $result = $this->accountService->getReconciliationStatus();

            return response()->json($result, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'reconciliation_status_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function reconcile(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $data = $request->validate([
            'account_number' => ['nullable', 'string'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $accountNumber = $data['account_number'] ?? config('coop_bank.account_number');

        try {
            if ($this->reconciliationService) {
                $result = $this->reconciliationService->reconcileStatement(
                    $accountNumber,
                    $data['from_date'],
                    $data['to_date']
                );
            } else {
                $result = $this->accountService->syncTransactions(
                    $accountNumber,
                    $data['from_date'],
                    $data['to_date']
                );
            }

            return response()->json([
                'success' => true,
                'account_number' => $accountNumber,
                'result' => $result,
            ], 200);
        } catch (\Throwable $e) {
            $status = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'connection failed')
                ? 504
                : 502;

            return response()->json([
                'success' => false,
                'error' => 'reconcile_failed',
                'message' => $status === 504
                    ? 'The Co-op Bank statement service did not respond in time. No transactions were changed; please retry.'
                    : $e->getMessage(),
            ], $status);
        }
    }

    public function syncHistory(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $limit = min((int) $request->query('limit', 50), 200);

        $logs = \App\Models\BankSyncAudit::orderBy('started_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'sync_history' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'account_number' => $log->account_number,
                'from_date' => $log->from_date,
                'to_date' => $log->to_date,
                'total_bank_transactions' => $log->total_bank_transactions,
                'new_transactions' => $log->new_transactions,
                'duplicate_skipped' => $log->duplicate_skipped,
                'matched' => $log->matched,
                'unmatched' => $log->unmatched,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'started_at' => $log->started_at,
                'completed_at' => $log->completed_at,
            ]),
        ], 200);
    }

    public function dashboardSummary(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        try {
            $balance = $this->accountService->getAccountBalance();
            $reconStatus = $this->accountService->getReconciliationStatus();

            $pendingCount = \App\Models\CoopTransferRequest::whereIn('status', ['initiated', 'submitted', 'processing'])->count();
            $successfulToday = \App\Models\CoopTransferRequest::where('status', 'successful')
                ->whereDate('updated_at', today())
                ->count();
            $failedToday = \App\Models\CoopTransferRequest::where('status', 'failed')
                ->whereDate('updated_at', today())
                ->count();

            return response()->json([
                'balance' => [
                    'available' => $balance['available_balance'] ?? 0,
                    'booked' => $balance['booked_balance'] ?? 0,
                    'currency' => $balance['currency'] ?? 'KES',
                ],
                'today' => [
                    'collections' => 0,
                    'disbursements' => $successfulToday,
                    'successful_transfers' => $successfulToday,
                    'failed_transfers' => $failedToday,
                ],
                'pending' => [
                    'count' => $pendingCount,
                    'transfers_by_status' => $reconStatus['transfers_by_status'] ?? [],
                    'recent_transfers' => $reconStatus['recent_transfers'] ?? [],
                ],
                'reconciliation' => [
                    'recent' => $reconStatus['recent_reconciliation'] ?? [],
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'dashboard_summary_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function financialSummary(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        try {
            $userId = $identity['id'] ?? null;
            $summary = $this->bankSyncService->getFinancialSummary();

            return response()->json([
                'success' => true,
                'data' => $summary,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'financial_summary_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function reconciliationReport(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        try {
            $report = $this->bankSyncService->getReconciliationReport();

            return response()->json([
                'success' => true,
                'data' => $report,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'reconciliation_report_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncStatement(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $data = $request->validate([
            'account_number' => ['nullable', 'string'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $accountNumber = $data['account_number'] ?? config('coop_bank.account_number');
        $pageSize = $data['page_size'] ?? 500;
        $userId = $identity['id'] ?? null;

        try {
            $result = $this->bankSyncService->syncFullStatement(
                $accountNumber,
                $data['from_date'],
                $data['to_date'],
                $userId,
                $pageSize
            );

            $status = $result['status'] === 'success' ? 200 : 502;

            return response()->json([
                'success' => $result['status'] === 'success',
                'data' => $result,
            ], $status);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'statement_sync_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function bankTransactionsList(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $filters = $request->only([
            'date_from', 'date_to', 'status', 'category', 'type',
            'member_id', 'phone', 'search', 'bank_name', 'transaction_state',
        ]);

        $page = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 50), 200);

        $result = $this->bankSyncService->listTransactions($filters, $page, $perPage);

        return response()->json([
            'success' => true,
            'transactions' => $result['data'],
            'pagination' => $result['pagination'],
        ], 200);
    }

    public function bankTransactionDetail(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $tx = $this->bankSyncService->getTransaction($id);

        if (! $tx) {
            return response()->json([
                'success' => false,
                'error' => 'transaction_not_found',
                'message' => 'Bank transaction not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $tx,
        ], 200);
    }

    public function bankTransactionReconcile(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $userId = $identity['id'] ?? null;

        $data = $request->validate([
            'member_id' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'narration' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->bankSyncService->reconcileManually($id, $data, $userId);

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'reconciliation_failed',
                    'message' => $result['error'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'manual_reconcile_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function unmatchedStatementTransactions(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $data['per_page'] ?? 50;

        $transactions = DB::table('bank_unmatched_transactions')
            ->whereIn('status', ['pending', 'investigating'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('created_at')
            ->paginate($perPage, [
                'id',
                'bank_reference',
                'transaction_date',
                'amount',
                'credit_amount',
                'debit_amount',
                'payer_name',
                'phone',
                'narration',
                'reason',
                'created_at',
            ], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $transactions->items(),
            'pagination' => [
                'total' => $transactions->total(),
                'per_page' => $transactions->perPage(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    public function reconcileUnmatchedStatementTransaction(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $data = $request->validate([
            'match_type' => ['required', 'string', 'in:contribution,payout,penalty,fundrive'],
            'member_id' => ['required', 'string', 'exists:members,id'],
            'campaign_id' => ['nullable', 'required_if:match_type,fundrive', 'string', 'exists:donation_campaigns,id'],
            'penalty_id' => ['nullable', 'string', 'exists:penalties,id'],
        ]);
        $userId = (string) ($identity['user_id'] ?? '');

        try {
            $result = DB::transaction(function () use ($id, $data, $userId) {
                $unmatched = DB::table('bank_unmatched_transactions')
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $unmatched) {
                    return ['success' => false, 'message' => 'Unmatched statement transaction not found'];
                }

                if (! $unmatched->bank_reference) {
                    return ['success' => false, 'message' => 'The unmatched statement transaction has no bank reference'];
                }

                $matchType = $data['match_type'];
                $requestedTargetId = match ($matchType) {
                    'payout' => $data['member_id'],
                    'fundrive' => $data['campaign_id'],
                    'penalty' => $data['penalty_id'] ?? $data['member_id'],
                    default => $data['member_id'],
                };
                if ($unmatched->status === 'matched') {
                    $audit = json_decode((string) $unmatched->resolution_notes, true) ?: [];
                    if (($audit['match_type'] ?? null) === $data['match_type']
                        && ($audit['requested_target_id'] ?? null) === $requestedTargetId) {
                        return ['success' => true, 'idempotent' => true, 'data' => $audit];
                    }
                    return ['success' => false, 'message' => 'This bank transaction has already been reconciled'];
                }
                if (! in_array($unmatched->status, ['pending', 'investigating'], true)) {
                    return ['success' => false, 'message' => 'Unmatched statement transaction is not available for reconciliation'];
                }

                $bankTransactions = DB::table('bank_transactions')
                    ->where(function ($query) use ($unmatched) {
                        $query->where('transaction_reference', $unmatched->bank_reference)
                            ->orWhere('bank_transaction_reference', $unmatched->bank_reference);
                    })
                    ->where('reconciliation_status', 'unmatched')
                    ->lockForUpdate()
                    ->limit(2)
                    ->get();

                if ($bankTransactions->count() !== 1) {
                    return ['success' => false, 'message' => 'The corresponding bank transaction could not be found'];
                }
                $bankTransaction = $bankTransactions->first();

                $targetId = $data['campaign_id'] ?? $data['penalty_id'] ?? $data['member_id'];
                $member = null;
                $providerReference = (string) $bankTransaction->transaction_reference;
                $credit = (float) ($bankTransaction->credit_amount ?? 0);
                $debit = (float) ($bankTransaction->debit_amount ?? 0);

                if ($matchType === 'payout') {
                    if ($debit <= 0) {
                        return ['success' => false, 'message' => 'A payout must be reconciled against a bank debit'];
                    }
                    $member = DB::table('members')->where('id', $data['member_id'])->first();
                    if (!$member) {
                        return ['success' => false, 'message' => 'Payout member could not be found'];
                    }
                } elseif ($matchType === 'contribution') {
                    if ($credit <= 0) {
                        return ['success' => false, 'message' => 'A contribution must be reconciled against a bank credit'];
                    }
                    $reconciliation = $this->bankSyncService->reconcileManually(
                        $bankTransaction->id,
                        ['member_id' => $data['member_id'], 'category' => 'monthly_contribution'],
                        $userId
                    );
                    if (! $reconciliation['success']) {
                        return ['success' => false, 'message' => $reconciliation['error']];
                    }
                    $member = DB::table('members')->where('id', $data['member_id'])->first();
                    $targetId = $reconciliation['contribution_id'] ?? $data['member_id'];
                } else {
                    if ($credit <= 0) {
                        return ['success' => false, 'message' => 'Penalty and Fundrive payments must be reconciled against a bank credit'];
                    }
                    $member = DB::table('members')->where('id', $data['member_id'])->first();
                    if (!$member) {
                        return ['success' => false, 'message' => 'Member not found'];
                    }

                    if ($matchType === 'penalty') {
                        $penaltyQuery = DB::table('penalties')->where('member_id', $member->id);
                        if (!empty($data['penalty_id'])) {
                            $penaltyQuery->where('id', $data['penalty_id']);
                        } else {
                            $penaltyQuery->where('is_paid', 0)
                                ->whereRaw('amount >= ?', [$credit])
                                ->orderBy('created_at');
                        }
                        $penalty = $penaltyQuery->lockForUpdate()->first();
                        if (!$penalty || (bool) $penalty->is_paid) {
                            return ['success' => false, 'message' => 'No eligible penalty was found for this member and amount'];
                        }
                        $penaltyPaid = (float) DB::table('penalty_payment_records')
                            ->where('penalty_id', $penalty->id)
                            ->sum('amount');
                        if ((float) $penalty->amount - $penaltyPaid + 0.001 < $credit) {
                            return ['success' => false, 'message' => 'Bank credit exceeds the remaining penalty balance'];
                        }
                        $targetId = $penalty->id;
                        $paymentType = 'penalty';
                        $relatedEntityId = $penalty->id;
                    } else {
                        $paymentType = 'fund_drive';
                        $relatedEntityId = $data['campaign_id'];
                        $targetId = $relatedEntityId;
                    }

                    app(\App\Services\PaymentLifecycleService::class)->postPayment(
                        $paymentType,
                        (string) $member->id,
                        $credit,
                        $providerReference,
                        $relatedEntityId,
                        'bank_manual_reconciliation',
                        null,
                        $providerReference
                    );
                }

                DB::table('bank_transactions')->where('id', $bankTransaction->id)->update([
                    'member_id' => $member->id,
                    'phone' => $member->phone,
                    'name' => $member->name,
                    'reconciliation_status' => 'matched',
                    'match_method' => 'manual',
                    'match_confidence' => 'high',
                    'matched_by' => $userId,
                    'matched_at' => now(),
                    'category' => $matchType === 'contribution' ? 'monthly_contribution' : $matchType,
                    'updated_at' => now(),
                ]);

                $audit = [
                    'matched_by' => $userId,
                    'matched_at' => now()->toIso8601String(),
                    'match_type' => $matchType,
                    'target_id' => $targetId,
                    'requested_target_id' => $requestedTargetId,
                    'member_id' => $member->id ?? null,
                    'bank_reference' => $unmatched->bank_reference,
                    'amount' => $credit > 0 ? $credit : $debit,
                    'status' => 'matched',
                ];
                DB::table('bank_unmatched_transactions')
                    ->where('id', $id)
                    ->update([
                        'status' => 'matched',
                        'resolved_by' => $userId,
                        'resolved_at' => now(),
                        'resolution_notes' => json_encode($audit),
                        'updated_at' => now(),
                    ]);

                return ['success' => true, 'data' => $audit];
            });

            if (! $result['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'statement_reconciliation_failed',
                    'message' => $result['message'],
                ], 422);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'statement_reconciliation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function memberBankTransactions(Request $request, string $memberId): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffOrAdmin($identity);

        $page = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 50), 200);

        $result = $this->bankSyncService->getMemberBankTransactions($memberId, $page, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 200);
    }

    protected function assertStaffOrAdmin(array $identity): void
    {
        $roles = $identity['roles'] ?? [];

        if (!\App\Services\AuthService::isStaff($roles)) {
            throw new \App\Exceptions\HttpException(403, 'insufficient_permissions', 'Staff or admin access required');
        }
    }
}
