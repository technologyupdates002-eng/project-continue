<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use App\Jobs\Banking\SyncBankStatement;
use App\Services\WithdrawalApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/treasurer/* — treasurer dashboard, wallets, approvals, expenses,
 * campaigns, memos, settings and reports.
 */
class TreasurerPortalController extends PortalController
{
    private const ROLES = ['treasurer', 'admin', 'chairperson'];

    /** wallet_type => [withdrawal table, signatory table] */
    private const WITHDRAWAL_TABLES = [
        'penalty' => ['penalty_withdrawals', 'withdrawal_signatories'],
        'donation' => ['donation_withdrawals', 'donation_withdrawal_signatories'],
        'operational' => ['operational_withdrawals', 'operational_withdrawal_signatories'],
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->cached('treasurer:dash', function () {
            $w = $this->walletBalances();
            $book = (float) (DB::table('book_balance')->orderByDesc('transaction_date')->orderByDesc('created_at')->value('book_balance') ?? 0);
            $bank = (float) (DB::table('bank_transactions')->whereNotNull('bank_balance')->orderByDesc('transaction_date')->orderByDesc('created_at')->value('bank_balance') ?? 0);
            $pending = 0;
            foreach (self::WITHDRAWAL_TABLES as [$t]) {
                $pending += DB::table($t)->whereNotIn('status', ['completed', 'rejected', 'failed', 'cancelled'])->count();
            }

            return [
                'operational_balance' => $w['operational'],
                'contributions_balance' => $w['contributions'],
                'penalty_balance' => $w['penalty'],
                'donation_balance' => $w['donation'],
                'book_balance' => $book,
                'bank_balance' => $bank,
                'reconciliation_diff' => round($bank - $book, 2),
                'pending_withdrawals' => $pending,
                'pending_expenses' => DB::table('expenses')->where('status', 'pending')->count(),
                'monthly_contributions' => $this->monthlyTrend(),
            ];
        }, 20));
    }

    public function contributions(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $q = DB::table('contributions as c')->leftJoin('members as m', 'm.id', '=', 'c.member_id')
            ->select('c.*', 'm.name as member_name')->orderByDesc('c.year')->orderByDesc('c.month');
        if ($s = $request->query('status')) {
            $q->where('c.status', $s === 'confirmed' ? 'paid' : $s);
        }
        if ($f = $request->query('from')) {
            $q->where('c.paid_date', '>=', $f);
        }
        if ($t = $request->query('to')) {
            $q->where('c.paid_date', '<=', $t);
        }

        return response()->json($this->paginate($q, $request));
    }

    public function adjustContribution(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin']);
        $data = $request->validate(['amount' => 'required|numeric|min:0', 'reason' => 'required|string|max:500']);
        $c = DB::table('contributions')->where('id', $id)->first();
        if (! $c) {
            throw new HttpException(404, 'not_found', 'Contribution not found.');
        }
        // Reuse the audited adjustment service: convert "new amount" into a signed delta.
        $delta = round((float) $data['amount'] - (float) $c->amount, 2);
        if ($delta == 0.0) {
            return response()->json($this->ok('No change needed.'));
        }
        $request->merge(['member_id' => $c->member_id, 'amount' => abs($delta), 'direction' => $delta > 0 ? 'add' : 'reduce', 'category' => 'contribution', 'reason' => mb_substr($data['reason'], 0, 160)]);

        return app(\App\Http\Controllers\Api\ContributionAdjustmentController::class)->store($request);
    }

    public function walletLedger(Request $request, string $wallet): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $q = DB::table('wallet_transactions')->where('wallet_type', $wallet)->orderByDesc('occurred_at')->orderByDesc('created_at');
        $page = $this->paginate($q, $request);
        $page['data'] = array_map(fn ($t) => [
            'id' => $t->id, 'wallet' => $t->wallet_type, 'type' => $t->direction, 'amount' => (float) $t->net_amount,
            'gross_amount' => (float) $t->gross_amount, 'description' => $t->notes ?: $t->source,
            'reference' => $t->mpesa_receipt, 'party' => $t->party_name, 'balance_after' => (float) $t->running_balance,
            'status' => $t->status, 'date' => (string) ($t->occurred_at ?? $t->created_at),
        ], $page['data']);

        return response()->json($page);
    }

    public function wallets(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $out = [];
        foreach (['penalty' => 'penalty_wallet', 'donation' => 'donation_wallet', 'operational' => 'operational_wallet'] as $type => $table) {
            $w = DB::table($table)->first();
            $out[] = [
                'wallet' => $type, 'name' => ucfirst($type).' Wallet', 'balance' => (float) ($w->total_balance ?? 0),
                'total_in' => (float) ($w->total_received ?? 0), 'total_out' => (float) ($w->total_withdrawn ?? 0),
                'last_updated' => (string) ($w->updated_at ?? $w->last_updated ?? ''),
            ];
        }

        return response()->json($out);
    }

    public function withdrawalApprovals(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);
        $rows = [];
        foreach (self::WITHDRAWAL_TABLES as $type => [$table, $sigTable]) {
            foreach (DB::table($table)->orderByDesc('created_at')->limit(100)->get() as $w) {
                $rows[] = (array) $w + [
                    'wallet_type' => $type,
                    'signatories' => DB::table($sigTable)->where('withdrawal_id', $w->id)->get(['signatory_role', 'status', 'approved_at', 'rejected_at']),
                ];
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return response()->json($rows);
    }

    public function decideWithdrawal(Request $request, string $id, string $action): JsonResponse
    {
        $identity = $this->requireAnyRole($request, self::STAFF_ROLES);
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new HttpException(422, 'invalid_action', 'Unknown action.');
        }
        [$walletType, $sigTable] = $this->locateWithdrawal($id);
        $svc = app(WithdrawalApprovalService::class);
        $svc->setIdentity($identity);
        $role = $svc->getUserSignatoryRole((array) $identity['roles']);
        if (! $role) {
            throw new HttpException(403, 'not_signatory', 'Only signatories can approve withdrawals.');
        }

        if ($action === 'reject') {
            $svc->recordRejection($sigTable, $id, $role, (string) $request->input('note', 'Rejected'), $identity);
        } else {
            $svc->validateNotSelfApproved($walletType, $id, $identity['user_id']);
            $svc->validateApprovalStage($walletType, $id, $role);
            $svc->recordApproval($sigTable, $id, $role, ['status' => 'approved', 'approved_at' => now()], $identity);
            $svc->advanceApprovalStage($walletType, $id, $identity);
        }
        $this->audit($request, "withdrawal_{$action}", ['id' => $id, 'wallet' => $walletType]);

        return response()->json($this->ok($action === 'approve' ? 'Approval recorded.' : 'Withdrawal rejected.'));
    }

    private function locateWithdrawal(string $id): array
    {
        foreach (self::WITHDRAWAL_TABLES as $type => [$table, $sig]) {
            if (DB::table($table)->where('id', $id)->exists()) {
                return [$type, $sig];
            }
        }
        throw new HttpException(404, 'not_found', 'Withdrawal not found.');
    }

    public function documents(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('documents as d')->leftJoin('members as m', 'm.id', '=', 'd.member_id')
            ->select('d.*', 'm.name as member_name')->orderByDesc('d.created_at')->limit(300)->get());
    }

    public function downloadDocument(Request $request, string $id)
    {
        $this->requireAnyRole($request, self::ROLES);
        $doc = DB::table('documents')->where('id', $id)->first();
        if (! $doc || ! $doc->file_url) {
            throw new HttpException(404, 'not_found', 'Document not found.');
        }

        return redirect()->away($doc->file_url);
    }

    public function reports(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $today = now()->toDateString();

        return response()->json([
            ['name' => 'Contributions report', 'type' => 'contributions', 'date' => $today, 'size' => 'CSV'],
            ['name' => 'Penalty wallet ledger', 'type' => 'penalty', 'date' => $today, 'size' => 'CSV'],
            ['name' => 'Donation wallet ledger', 'type' => 'donation', 'date' => $today, 'size' => 'CSV'],
            ['name' => 'Operational wallet ledger', 'type' => 'operational', 'date' => $today, 'size' => 'CSV'],
            ['name' => 'Expenses report', 'type' => 'expenses', 'date' => $today, 'size' => 'CSV'],
            ['name' => 'Defaulters report', 'type' => 'defaulters', 'date' => $today, 'size' => 'CSV'],
        ]);
    }

    public function exportReport(Request $request, string $type)
    {
        $this->requireAnyRole($request, self::ROLES);
        $from = $request->query('from');
        $to = $request->query('to');
        $range = function ($q, string $col) use ($from, $to) {
            if ($from) {
                $q->where($col, '>=', $from);
            }
            if ($to) {
                $q->where($col, '<=', $to.' 23:59:59');
            }

            return $q;
        };

        return match ($type) {
            'contributions' => $this->csv('contributions.csv', ['Member', 'Member No', 'Year', 'Month', 'Amount', 'Status', 'Paid'],
                $range(DB::table('contributions as c')->join('members as m', 'm.id', '=', 'c.member_id'), 'c.paid_date')
                    ->orderBy('m.name')->get(['m.name', 'm.member_id', 'c.year', 'c.month', 'c.amount', 'c.status', 'c.paid_date'])),
            'penalty', 'donation', 'operational' => $this->csv("{$type}-wallet.csv", ['Date', 'Direction', 'Source', 'Party', 'Gross', 'Charge', 'Net', 'Balance', 'Receipt'],
                $range(DB::table('wallet_transactions')->where('wallet_type', $type), 'occurred_at')->orderBy('occurred_at')
                    ->get(['occurred_at', 'direction', 'source', 'party_name', 'gross_amount', 'mpesa_charge', 'net_amount', 'running_balance', 'mpesa_receipt'])),
            'expenses' => $this->csv('expenses.csv', ['Date', 'Type', 'Category', 'Amount', 'Recipient', 'Status', 'Reference'],
                $range(DB::table('expenses'), 'created_at')->orderBy('created_at')
                    ->get(['created_at', 'expense_type', 'category', 'amount', 'recipient_name', 'status', 'reference_number'])),
            'defaulters' => $this->csv('defaulters.csv', ['Member', 'Member No', 'Phone', 'Unpaid months', 'Arrears'],
                AdminPortalController::defaultersQuery()->get()->map(fn ($d) => [$d->name, $d->member_id, $d->phone, $d->unpaid_months, $d->arrears])),
            default => throw new HttpException(404, 'not_found', 'Unknown report.'),
        };
    }

    public function importBatches(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return app(\App\Http\Controllers\Api\BankStatementImportHistoryController::class)->index($request);
    }

    public function importBatch(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('member_import_rows')->where('batch_id', $id)->orderBy('row_number')->limit(2000)->get());
    }

    public function bankSyncStatus(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $last = DB::table('bank_sync_audit')->orderByDesc('started_at')->first();

        return response()->json([
            'last_sync' => (string) ($last->completed_at ?? $last->started_at ?? ''),
            'status' => (string) ($last->status ?? 'never'),
            'new_transactions' => (int) ($last->new_transactions ?? 0),
            'matched' => (int) ($last->matched ?? 0),
            'unmatched' => (int) ($last->unmatched ?? 0),
        ]);
    }

    public function triggerBankSync(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin']);
        // Heavy statement pull runs on the queue; the dashboard polls status.
        $account = (string) config('coop_bank.account_number', '');
        if ($account === '') {
            throw new HttpException(422, 'bank_not_configured', 'Bank account number is not configured on the server.');
        }
        $from = (string) (DB::table('bank_sync_audit')->where('status', 'completed')->max('to_date') ?? now()->subDays(30)->toDateString());
        SyncBankStatement::dispatch($account, substr($from, 0, 10), now()->toDateString());

        return response()->json($this->ok('Bank sync started. Results appear in a moment.', ['imported' => 0]), 202);
    }

    public function bankStatement(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $q = DB::table('bank_transactions as b')->leftJoin('members as m', 'm.id', '=', 'b.member_id')
            ->select('b.*', 'm.name as member_name')->orderByDesc('b.transaction_date')->orderByDesc('b.created_at');

        return response()->json($this->paginate($q, $request, 50));
    }

    public function bookBalance(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $entries = DB::table('book_balance')->orderByDesc('transaction_date')->limit(200)->get();

        return response()->json([
            'balance' => (float) ($entries->first()->book_balance ?? 0),
            'last_updated' => (string) ($entries->first()->updated_at ?? ''),
            'entries' => $entries,
        ]);
    }

    public function importBookBalance(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin']);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            $row = @array_combine($header, $r);
            if (! $row || empty($row['date'] ?? $row['transaction_date'] ?? null)) {
                continue;
            }
            $rows[] = [
                'id' => $this->uuid(),
                'transaction_date' => $row['date'] ?? $row['transaction_date'],
                'check_number' => $row['check_number'] ?? ($row['cheque'] ?? null),
                'debit' => (float) ($row['debit'] ?? 0),
                'book_balance' => (float) ($row['book_balance'] ?? ($row['balance'] ?? 0)),
                'reason' => $row['reason'] ?? ($row['description'] ?? null),
                'created_by' => $identity['user_id'],
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        fclose($fh);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('book_balance')->insert($chunk);
        }
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('Book balance imported.', ['imported' => count($rows)]));
    }

    public function bankStatementImport(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin']);

        $identity = $this->identity($request);
        if (! $request->hasFile('file')) {
            // Chunked JSON rows from the browser importer use the existing validated import flow.
            $response = app(\App\Http\Controllers\Api\ImportController::class)
                ->importBankStatement(app(\App\Http\Requests\BankStatementImportRequest::class));
            \App\Observers\FinancialChangeObserver::bump();

            return $response;
        }
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);
        $file = $request->file('file');
        $fh = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($row = @array_combine($header, $r)) {
                $rows[] = $row;
            }
        }
        fclose($fh);
        $result = app(\App\Services\ImportService::class)->importMemberStatement($rows, 'bank_statement', $file->getClientOriginalName(), $identity['user_id']);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(['message' => 'Bank statement imported.', 'imported' => (int) ($result['imported'] ?? count($rows)), 'data' => $result]);
    }

    public function walletReports(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $period = now()->format('M Y');
        $counts = DB::table('wallet_transactions')->selectRaw('wallet_type, COUNT(*) as n')->groupBy('wallet_type')->pluck('n', 'wallet_type');
        $b = $this->walletBalances();

        return response()->json(array_map(fn ($w) => [
            'wallet' => $w, 'transactions' => (int) ($counts[$w] ?? 0), 'balance' => $b[$w], 'period' => $period,
        ], ['penalty', 'donation', 'operational']));
    }

    public function expenses(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->paginate(DB::table('expenses')->orderByDesc('created_at'), $request));
    }

    public function createExpense(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin']);
        $data = $request->validate([
            'expense_type' => 'required|string|max:60', 'category' => 'nullable|string|max:60',
            'amount' => 'required|numeric|min:1', 'description' => 'nullable|string|max:1000',
            'recipient_name' => 'nullable|string|max:150', 'payment_method' => 'nullable|string|max:30',
            'reference_number' => 'nullable|string|max:60', 'wallet_type' => 'nullable|in:penalty,donation,operational',
            'phone_number' => 'nullable|string|max:20',
        ]);
        $id = $this->uuid();
        DB::table('expenses')->insert($data + ['id' => $id, 'status' => 'pending', 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(DB::table('expenses')->where('id', $id)->first(), 201);
    }

    public function decideExpense(Request $request, string $id, string $action): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin', 'chairperson']);
        $e = DB::table('expenses')->where('id', $id)->first();
        if (! $e || $e->status !== 'pending') {
            throw new HttpException(422, 'invalid_state', 'Only pending expenses can be decided.');
        }
        if ($e->created_by === $identity['user_id']) {
            throw new HttpException(403, 'self_approval', 'You cannot approve your own expense.');
        }
        DB::table('expenses')->where('id', $id)->update([
            'status' => $action === 'approve' ? 'approved' : 'rejected',
            'approved_by' => $identity['user_id'], 'approved_at' => now(), 'updated_at' => now(),
        ]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('Expense '.($action === 'approve' ? 'approved' : 'rejected').'.'));
    }

    public function campaigns(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);

        return response()->json(DB::table('donation_campaigns')->orderByDesc('created_at')->get());
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin']);
        $data = $this->campaignData($request, true);
        $id = $this->uuid();
        DB::table('donation_campaigns')->insert($data + ['id' => $id, 'created_by' => $identity['user_id'], 'total_collected' => 0, 'created_at' => now(), 'updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(DB::table('donation_campaigns')->where('id', $id)->first(), 201);
    }

    public function updateCampaign(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin']);
        DB::table('donation_campaigns')->where('id', $id)->update($this->campaignData($request, false) + ['updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(DB::table('donation_campaigns')->where('id', $id)->first());
    }

    public function deleteCampaign(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin']);
        if (DB::table('donation_payment_records')->where('campaign_id', $id)->exists()) {
            // Keep history: campaigns with money attached are closed, not deleted.
            DB::table('donation_campaigns')->where('id', $id)->update(['active' => 0, 'updated_at' => now()]);

            return response()->json($this->ok('Campaign has payments, so it was closed instead of deleted.'));
        }
        DB::table('donation_campaigns')->where('id', $id)->delete();
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('Campaign deleted.'));
    }

    private function campaignData(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $d = $request->validate([
            'title' => "{$req}|string|max:200", 'description' => 'nullable|string',
            'amount' => 'nullable|numeric|min:0', 'target_total' => 'nullable|numeric|min:0',
            'goal_type' => 'nullable|string|max:30', 'allow_partial' => 'nullable|boolean', 'active' => 'nullable|boolean',
        ]);
        if ($create && ! isset($d['active'])) {
            $d['active'] = 1;
        }

        return $d;
    }

    public function memos(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);

        return response()->json(DB::table('memos')->orderByDesc('created_at')->get());
    }

    public function createMemo(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin', 'secretary', 'chairperson']);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'category' => 'nullable|string|max:60', 'content' => 'required|string',
            'recipient_type' => 'nullable|string|max:30', 'status' => 'nullable|in:draft,sent',
        ]);
        $id = $this->uuid();
        $ref = 'KHWWC/MEMO/'.now()->format('Y').'/'.str_pad((string) (DB::table('memos')->whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT);
        DB::table('memos')->insert($data + ['id' => $id, 'reference_number' => $ref, 'status' => $data['status'] ?? 'draft', 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        if (($data['status'] ?? 'draft') === 'sent') {
            $this->deliverMemo($id);
        }

        return response()->json(DB::table('memos')->where('id', $id)->first(), 201);
    }

    public function updateMemo(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin', 'secretary', 'chairperson']);
        $data = $request->validate([
            'title' => 'sometimes|string|max:200', 'category' => 'nullable|string|max:60', 'content' => 'sometimes|string',
            'recipient_type' => 'nullable|string|max:30', 'status' => 'nullable|in:draft,sent',
        ]);
        DB::table('memos')->where('id', $id)->update($data + ['updated_at' => now()]);
        if (($data['status'] ?? null) === 'sent') {
            $this->deliverMemo($id);
        }

        return response()->json(DB::table('memos')->where('id', $id)->first());
    }

    public function deleteMemo(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin', 'secretary']);
        DB::table('memo_recipients')->where('memo_id', $id)->delete();
        DB::table('memos')->where('id', $id)->delete();

        return response()->json($this->ok('Memo deleted.'));
    }

    public function resendMemo(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['treasurer', 'admin', 'secretary', 'chairperson']);
        $this->deliverMemo($id);

        return response()->json($this->ok('Memo sent to all members.'));
    }

    /** Fan-out happens in one INSERT ... SELECT (fast for thousands of members). */
    private function deliverMemo(string $id): void
    {
        DB::table('memos')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        DB::table('memo_recipients')->where('memo_id', $id)->delete();
        DB::statement(
            'INSERT INTO memo_recipients (id, memo_id, member_id, delivered_at, created_at) SELECT UUID(), ?, id, NOW(), NOW() FROM members WHERE is_active = 1',
            [$id]
        );
    }

    public function memoTemplates(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);

        return response()->json(DB::table('memo_templates')->orderBy('name')->get());
    }

    public function settings(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);
        $s = DB::table('organization_settings')->first();

        return response()->json($s ? (array) $s + ['payout_rules' => json_decode((string) $s->payout_rules, true) ?: []] : new \stdClass());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['treasurer', 'admin']);
        $data = $request->validate([
            'organization_name' => 'sometimes|string|max:200', 'organization_address' => 'nullable|string|max:500',
            'organization_email' => 'nullable|email', 'organization_phone' => 'nullable|string|max:30',
            'logo_url' => 'nullable|string|max:500', 'signature_url' => 'nullable|string|max:500',
            'stamp_url' => 'nullable|string|max:500', 'letterhead_template' => 'nullable|string',
            'payout_rules' => 'nullable|array',
        ]);
        if (isset($data['payout_rules'])) {
            $data['payout_rules'] = json_encode($data['payout_rules']);
        }
        $data += ['updated_by' => $identity['user_id'], 'updated_at' => now()];
        if (DB::table('organization_settings')->exists()) {
            DB::table('organization_settings')->limit(1)->update($data);
        } else {
            DB::table('organization_settings')->insert($data + ['id' => $this->uuid()]);
        }

        return response()->json($this->ok('Settings saved.'));
    }
}
