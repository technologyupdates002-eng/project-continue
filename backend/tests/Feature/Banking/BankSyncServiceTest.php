<?php

namespace Tests\Feature\Banking;

use App\Services\AuthService;
use App\Services\BankSyncService;
use App\Services\FinancialReadModel;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BankSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected array $adminHeaders = [];
    protected string $accountNumber = '01134568843700';

    protected function setUp(): void
    {
        parent::setUp();

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));
        $this->adminHeaders = ['Authorization' => "Bearer {$adminToken}"];
    }

    private function enableBankApi(): void
    {
        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');
    }

    private function createMember(string $phone, string $name = 'Test Member', float $totalContrib = 0): string
    {
        $memberId = \App\Support\Uuid::v4();
        $userId = \App\Support\Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => AuthService::emailForPhone($phone),
            'phone' => $phone,
            'encrypted_password' => \App\Services\AuthService::hashPassword('pass'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => $name,
            'phone' => $phone,
            'member_id' => $memberId,
            'is_active' => 1,
            'status' => 'active',
            'total_contributions' => $totalContrib,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => \App\Support\Uuid::v4(),
            'user_id' => $userId,
            'role' => 'member',
            'is_active' => 1,
        ]);

        return $memberId;
    }

    private function fakeBankPage(array $transactions, string $messageDescription = 'Success'): array
    {
        return [
            'MessageCode' => '0',
            'MessageDescription' => $messageDescription,
            'TotalRecords' => count($transactions),
            'TotalPages' => 1,
            'Transactions' => $transactions,
        ];
    }

    private function makeBankTx(
        string $ref,
        string $narration,
        float $credit,
        float $debit = 0,
        string $date = '2025-01-15',
        string $time = '10:30:00',
        float $bookBalance = 10000
    ): array {
        return [
            'TransactionReference' => $ref,
            'Narration' => $narration,
            'TransactionType' => 'Credit',
            'CreditAmount' => (string) $credit,
            'DebitAmount' => (string) $debit,
            'TransactionDate' => $date,
            'TransactionTime' => $time,
            'ValueDate' => $date,
            'BookBalance' => (string) $bookBalance,
        ];
    }

    // ── Sync: Basic insert ──────────────────────────────────────────────

    public function test_sync_full_statement_inserts_bank_transactions(): void
    {
        $this->enableBankApi();
        $this->createMember('+254712345678', 'JANE DOE');

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-001', 'Confirm. JANE DOE 0712345678', 1000),
            ]), 200),
        ]);

        /** @var BankSyncService $service */
        $service = app(BankSyncService::class);

        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals('success', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['total_transactions']);
        $this->assertGreaterThanOrEqual(1, $result['summary']['new_transactions']);

        $this->assertDatabaseHas('bank_transactions', [
            'transaction_reference' => 'REF-001',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'reconciliation_status' => 'matched',
        ]);
    }

    // ── Sync: Member matching by phone ─────────────────────────────────

    public function test_sync_matches_existing_member_by_phone(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE');

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-002', 'Confirm. JANE DOE 0712345678', 500),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals(1, $result['summary']['matched']);
        $this->assertEquals(0, $result['summary']['unmatched']);
        $this->assertEquals(0, $result['summary']['new_members']);

        $this->assertDatabaseHas('bank_transactions', [
            'transaction_reference' => 'REF-002',
            'member_id' => $memberId,
            'reconciliation_status' => 'matched',
            'match_method' => 'phone_match',
        ]);
    }

    // ── Sync: Unknown phone stays unmatched; no member is created ─────

    public function test_sync_keeps_unseen_phone_unmatched_without_creating_member(): void
    {
        $this->enableBankApi();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-003', 'Confirm. NEW GUY 0799999999', 2000),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals(0, $result['summary']['new_members']);
        $this->assertEquals(1, $result['summary']['unmatched']);
        $this->assertEquals(0, $result['summary']['matched']);

        $this->assertDatabaseMissing('members', [
            'phone' => '+254799999999',
        ]);

        $this->assertDatabaseMissing('auth_users', [
            'email' => '254799999999@welfare.local',
        ]);

        $this->assertDatabaseHas('bank_transactions', [
            'transaction_reference' => 'REF-003',
            'member_id' => null,
            'reconciliation_status' => 'unmatched',
        ]);
    }

    // ── Sync: Contribution creation ─────────────────────────────────────

    public function test_sync_creates_contribution_for_monthly_credit(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE');

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-004', 'M-PESA JANE 0712345678 Contribution', 1000, 0, '2025-01-20'),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals(1, $result['summary']['contributions_created']);

        $bankTx = DB::table('bank_transactions')->where('transaction_reference', 'REF-004')->first();
        $this->assertNotNull($bankTx->contribution_id);
        $payment = DB::table('payments')->where('provider_reference', 'REF-004')->first();
        $this->assertNotNull($payment);
        $this->assertSame(100000, (int) $payment->amount_minor);
        $this->assertSame(100000, (int) DB::table('contribution_allocations')->where('payment_id', $payment->id)->sum('amount_minor'));
        $this->assertSame(1000.0, (float) DB::table('contributions')->where('member_id', $memberId)->sum('amount'));
        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $this->assertSame(100000, $overview['cash_received_minor']);
        $this->assertSame(100000, $overview['allocated_minor']);
    }

    // ── Sync: Idempotency / dedup ─────────────────────────────────────

    public function test_sync_skips_duplicate_transactions(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE', 1000);

        // Pre-insert a bank transaction that will appear again in the sync
        $txRef = 'REF-DUP-001';
        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 5000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => $txRef,
            'bank_transaction_reference' => $txRef,
            'transaction_type' => 'CREDIT',
            'credit_amount' => 5000,
            'debit_amount' => 0,
            'narration' => 'Confirm. JANE DOE 0712345678',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'member_matched_at' => now(),
            'match_method' => 'phone_match',
            'match_confidence' => 'high',
            'matched_by' => null,
            'matched_at' => now(),
            'contribution_id' => null,
            'sync_batch_id' => null,
            'raw_response' => '{}',
            'transaction_state' => 'completed',
            'category' => 'monthly_contribution',
            'bank_fingerprint' => $this->accountNumber . ':' . $txRef,
            'created_at' => now(),
        ]);

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx($txRef, 'Confirm. JANE DOE 0712345678', 5000),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals(1, $result['summary']['duplicate_skipped']);
        $this->assertEquals(0, $result['summary']['new_transactions']);

        // Original record should still exist
        $this->assertEquals(1, DB::table('bank_transactions')->where('transaction_reference', $txRef)->count());
    }

    // ── Sync: Unmatched credit (no phone in narration) ─────────────────

    public function test_sync_marks_credit_without_phone_as_unmatched(): void
    {
        $this->enableBankApi();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-005', 'Cash deposit no phone info', 500),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertEquals(1, $result['summary']['unmatched']);

        $this->assertDatabaseHas('bank_transactions', [
            'transaction_reference' => 'REF-005',
            'reconciliation_status' => 'unmatched',
            'match_method' => null,
            'member_id' => null,
        ]);
    }

    // ── Sync: Debit transactions are stored ─────────────────────────────

    public function test_sync_stores_debit_transactions(): void
    {
        $this->enableBankApi();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('REF-DEBIT-001', 'Withdrawal cash', 0, 2000),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $this->assertDatabaseHas('bank_transactions', [
            'transaction_reference' => 'REF-DEBIT-001',
            'transaction_type' => 'DEBIT',
            'credit_amount' => 0,
            'debit_amount' => 2000,
        ]);
    }

    // ── Sync: Pagination across multiple pages ──────────────────────────

    public function test_sync_handles_pagination(): void
    {
        $this->enableBankApi();

        $page1Txs = [
            $this->makeBankTx('P1-001', 'Confirm. MEMBER ONE 0711111111', 100),
            $this->makeBankTx('P1-002', 'Confirm. MEMBER TWO 0722222222', 200),
        ];
        $page2Txs = [
            $this->makeBankTx('P2-001', 'Confirm. MEMBER THREE 0733333333', 300),
        ];

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::sequence()
                ->push(['MessageCode' => '0', 'MessageDescription' => 'OK', 'TotalPages' => 2, 'Transactions' => $page1Txs])
                ->push(['MessageCode' => '0', 'MessageDescription' => 'OK', 'TotalPages' => 2, 'Transactions' => $page2Txs]),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1', 2);

        $this->assertEquals('success', $result['status']);
        $this->assertGreaterThanOrEqual(3, $result['summary']['total_transactions']);
    }

    // ── Manual reconciliation ───────────────────────────────────────────

    public function test_manual_reconciliation_links_member_and_creates_contribution(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+2547555555555', 'MANUAL TEST');

        $txId = \App\Support\Uuid::v4();
        DB::table('bank_transactions')->insert([
            'id' => $txId,
            'bank_name' => 'coop',
            'member_id' => null,
            'phone' => '',
            'name' => 'UNKNOWN',
            'amount' => 1500,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'MANUAL-REF-001',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1500,
            'debit_amount' => 0,
            'narration' => 'Cash deposit manual',
            'source' => 'manual',
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->reconcileManually($txId, [
            'member_id' => $memberId,
            'category' => 'monthly_contribution',
            'narration' => 'Cash deposit manual',
        ], 'treasurer-1');

        $this->assertTrue($result['success']);
        $this->assertEquals($memberId, $result['member_id']);
        $this->assertNotNull($result['contribution_id']);

        $this->assertDatabaseHas('bank_transactions', [
            'id' => $txId,
            'member_id' => $memberId,
            'reconciliation_status' => 'matched',
            'match_method' => 'manual',
            'contribution_id' => $result['contribution_id'],
        ]);

        $payment = DB::table('payments')->where('provider_reference', 'MANUAL-REF-001')->first();
        $this->assertNotNull($payment);
        $this->assertSame(150000, (int) $payment->amount_minor);
        $this->assertSame(150000, (int) DB::table('contribution_allocations')->where('payment_id', $payment->id)->sum('amount_minor'));
        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $this->assertSame(150000, $overview['cash_received_minor']);
        $this->assertSame(150000, $overview['allocated_minor']);
    }

    // ── Manual reconciliation: missing member ─────────────────────────

    public function test_manual_reconciliation_fails_without_member(): void
    {
        $this->enableBankApi();

        $txId = \App\Support\Uuid::v4();
        DB::table('bank_transactions')->insert([
            'id' => $txId,
            'bank_name' => 'coop',
            'phone' => '',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'NO-MEMBER-REF',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'narration' => 'Test',
            'source' => 'test',
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->reconcileManually($txId, [], 'treasurer-1');

        $this->assertFalse($result['success']);
        $this->assertEquals('Member is required for reconciliation', $result['error']);
    }

    // ── List transactions ───────────────────────────────────────────────

    public function test_list_transactions_returns_paginated_results(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE');

        for ($i = 1; $i <= 3; $i++) {
            DB::table('bank_transactions')->insert([
                'id' => \App\Support\Uuid::v4(),
                'bank_name' => 'coop',
                'member_id' => $memberId,
                'phone' => '+254712345678',
                'name' => 'JANE DOE',
                'amount' => 1000 * $i,
                'transaction_date' => '2025-01-1' . $i,
                'month' => 1,
                'year' => 2025,
                'transaction_reference' => 'LIST-REF-' . $i,
                'transaction_type' => 'CREDIT',
                'credit_amount' => 1000 * $i,
                'debit_amount' => 0,
                'narration' => 'Test transaction ' . $i,
                'source' => 'test',
                'reconciliation_status' => 'matched',
                'match_method' => 'phone_match',
                'created_at' => now(),
            ]);
        }

        $service = app(BankSyncService::class);
        $result = $service->listTransactions([], 1, 50);

        $this->assertEquals(3, $result['pagination']['total']);
        $this->assertCount(3, $result['data']);
  }

    // ── List transactions: filter by status ──────────────────────────

    public function test_list_transactions_filters_by_status(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE');

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'FILTER-MATCHED',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'narration' => 'Matched tx',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'match_method' => 'phone_match',
            'created_at' => now(),
        ]);

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => null,
            'phone' => '',
            'name' => null,
            'amount' => 500,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'FILTER-UNMATCHED',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 500,
            'narration' => 'Unmatched tx',
            'source' => 'test',
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);

        $matched = $service->listTransactions(['status' => 'matched'], 1, 50);
        $this->assertCount(1, $matched['data']);
        $this->assertEquals('FILTER-MATCHED', $matched['data'][0]->transaction_reference);

        $unmatched = $service->listTransactions(['status' => 'unmatched'], 1, 50);
        $this->assertCount(1, $unmatched['data']);
        $this->assertEquals('FILTER-UNMATCHED', $unmatched['data'][0]->transaction_reference);
    }

    // ── Get transaction detail ────────────────────────────────────────

    public function test_get_transaction_returns_detail_with_audit(): void
    {
        $this->enableBankApi();

        $memberId = $this->createMember('+254712345678', 'JANE DOE');
        $batchId = \App\Support\Uuid::v4();

        DB::table('bank_sync_audit')->insert([
            'id' => $batchId,
            'account_number' => $this->accountNumber,
            'from_date' => '2025-01-01',
            'to_date' => '2025-01-31',
            'total_bank_transactions' => 1,
            'new_transactions' => 1,
            'duplicate_skipped' => 0,
            'matched' => 1,
            'unmatched' => 0,
            'status' => 'success',
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contributionId = \App\Support\Uuid::v4();
        DB::table('contributions')->insert([
            'id' => $contributionId,
            'member_id' => $memberId,
            'amount' => 1000,
            'month' => 1,
            'year' => 2025,
            'due_date' => '2025-01-31',
            'payment_ref' => 'REF-TRANSACTION-DETAIL',
            'paid_date' => '2025-01-15',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $txId = \App\Support\Uuid::v4();
        DB::table('bank_transactions')->insert([
            'id' => $txId,
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'REF-TRANSACTION-DETAIL',
            'bank_transaction_reference' => 'REF-TRANSACTION-DETAIL',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'debit_amount' => 0,
            'narration' => 'Test transaction detail',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'member_matched_at' => now(),
            'match_method' => 'phone_match',
            'match_confidence' => 'high',
            'matched_by' => 'admin-1',
            'matched_at' => now(),
            'contribution_id' => $contributionId,
            'sync_batch_id' => $batchId,
            'raw_response' => '{}',
            'transaction_state' => 'completed',
            'category' => 'monthly_contribution',
            'bank_fingerprint' => $this->accountNumber . ':REF-TRANSACTION-DETAIL',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->getTransaction($txId);

        $this->assertNotNull($result);
        $this->assertEquals('matched', $result['transaction']->reconciliation_status);
        $this->assertEquals($memberId, $result['transaction']->member_id);
        $this->assertEquals('JANE DOE', $result['transaction']->member_name);
        $this->assertEquals($contributionId, $result['transaction']->contribution_id);
        $this->assertNotNull($result['sync_audit']);
        $this->assertEquals($batchId, $result['sync_audit']->id);
    }

    // ── Get transaction: not found ────────────────────────────────────

    public function test_get_transaction_returns_null_for_unknown(): void
    {
        $service = app(BankSyncService::class);
        $result = $service->getTransaction('nonexistent-id');
        $this->assertNull($result);
    }

    // ── Financial summary ──────────────────────────────────────────────

    public function test_financial_summary_returns_correct_totals(): void
    {
        $memberId = $this->createMember('+254712345678', 'JANE DOE', 1000);

        $contributionId = \App\Support\Uuid::v4();
        DB::table('contributions')->insert([
            'id' => $contributionId,
            'member_id' => $memberId,
            'amount' => 1000,
            'month' => 1,
            'year' => 2025,
            'due_date' => '2025-01-31',
            'payment_ref' => 'SUMMARY-001',
            'paid_date' => '2025-01-15',
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'SUMMARY-001',
            'bank_transaction_reference' => 'SUMMARY-001',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'narration' => 'JANE CONTRIBUTION 0712345678',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'match_method' => 'phone_match',
            'contribution_id' => $contributionId,
            'created_at' => now(),
        ]);

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => null,
            'phone' => '',
            'name' => 'UNKNOWN',
            'amount' => 500,
            'transaction_date' => '2025-01-16',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'SUMMARY-002',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 500,
            'narration' => 'Unknown deposit',
            'source' => 'test',
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $summary = $service->getFinancialSummary();

        $this->assertEquals(1500, $summary['bank']['total_credits']);
        $this->assertEquals(1000, $summary['app']['total_contributions']);
        $this->assertEquals(1000, $summary['app']['matched_credits']);
        $this->assertEquals(500, $summary['app']['unmatched_credits']);
        $this->assertEquals(1500, $summary['app']['ledger_balance']);
    }

    // ── Get member bank transactions ──────────────────────────────────

    public function test_get_member_bank_transactions(): void
    {
        $memberId = $this->createMember('+254712345678', 'JANE DOE');

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'MEMBER-TX-001',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'narration' => 'JANE CONTRIBUTION 0712345678',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'match_method' => 'phone_match',
            'created_at' => now(),
        ]);

        DB::table('bank_transactions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'bank_name' => 'coop',
            'member_id' => $memberId,
            'phone' => '+254712345678',
            'name' => 'JANE DOE',
            'amount' => 2000,
            'transaction_date' => '2025-02-15',
            'month' => 2,
            'year' => 2025,
            'transaction_reference' => 'MEMBER-TX-002',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 2000,
            'narration' => 'JANE CONTRIBUTION 0712345678 Feb',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'match_method' => 'phone_match',
            'created_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $result = $service->getMemberBankTransactions($memberId);

        $this->assertNotNull($result['member']);
        $this->assertEquals('JANE DOE', $result['member']['name']);
        $this->assertEquals(2, count($result['transactions']));
    }

    // ── Sync history ──────────────────────────────────────────────────

    public function test_get_sync_history(): void
    {
        DB::table('bank_sync_audit')->insert([
            'id' => \App\Support\Uuid::v4(),
            'account_number' => $this->accountNumber,
            'from_date' => '2025-01-01',
            'to_date' => '2025-01-31',
            'total_bank_transactions' => 5,
            'new_transactions' => 3,
            'duplicate_skipped' => 2,
            'matched' => 3,
            'unmatched' => 0,
            'status' => 'success',
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(BankSyncService::class);
        $history = $service->getSyncHistory(50);

        $this->assertCount(1, $history);
        $this->assertEquals('success', $history[0]['status']);
        $this->assertEquals(5, $history[0]['total_bank_transactions']);
    }

    // ── Category detection ────────────────────────────────────────────

    public function test_category_detection(): void
    {
        $service = app(BankSyncService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('detectCategory');
        $method->setAccessible(true);

        $this->assertEquals('penalty', $method->invoke($service, 'Late payment fine 0712345678'));
        $this->assertEquals('penalty', $method->invoke($service, 'FINE imposed 0712345678'));
        $this->assertEquals('donation', $method->invoke($service, 'Fund drive donation 0712345678'));
        $this->assertEquals('donation', $method->invoke($service, 'Donation to fundrive 0712345678'));
        $this->assertEquals('monthly_contribution', $method->invoke($service, 'M-PESA JANE 0712345678'));
    }

    // ── Phone extraction from narration ───────────────────────────────

    public function test_phone_extraction_from_narration(): void
    {
        $this->assertEquals('254712345678', \App\Support\Phone::extractFromNarration('Confirm. JANE DOE 0712345678'));
        $this->assertEquals('254712345678', \App\Support\Phone::extractFromNarration('Payment from 254712345678 JOHN'));
        $this->assertEquals('254712345678', \App\Support\Phone::extractFromNarration('+254712345678 Deposit'));
    }

    // ── Sync stores raw_response ──────────────────────────────────────

    public function test_sync_stores_raw_response(): void
    {
        $this->enableBankApi();

        Http::fake([
            '*/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*AccountFullStatementPaginated*' => Http::response($this->fakeBankPage([
                $this->makeBankTx('RAW-REF-001', 'Confirm. TEST USER 0712345678', 750),
            ]), 200),
        ]);

        $service = app(BankSyncService::class);
        $service->syncFullStatement($this->accountNumber, '2025-01-01', '2025-01-31', 'admin-1');

        $tx = DB::table('bank_transactions')->where('transaction_reference', 'RAW-REF-001')->first();
        $this->assertNotNull($tx);
        $this->assertNotNull($tx->raw_response);
        $this->assertJson($tx->raw_response);
        $this->assertSame('RAW-REF-001', $tx->transaction_reference);
    }

    // ── Name extraction from production ~-delimited narration ─────────

    public function test_name_extraction_from_production_narration(): void
    {
        $service = app(BankSyncService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('extractNameFromNarration');
        $method->setAccessible(true);

        $this->assertEquals('SOFIA GACHOGU', $method->invoke($service, 'UGDMLAYH1J~40088588~254724464300~MPESAC2B_400200~SOFIA GACHOGU '));
        $this->assertEquals('IRENE MATUKU', $method->invoke($service, 'TB704YUF5W~40088588~254718913644~MPESAC2B_400200~IRENE MATUKU '));
        $this->assertEquals('JULIA MAINGI', $method->invoke($service, 'UI14Q4NB6V~40088588~254721294219~MPESAC2B_400200~JULIA MAINGI '));
    }
}
