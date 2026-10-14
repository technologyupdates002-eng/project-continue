<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\ContributionObligationService;
use App\Services\ContributionReconciliationService;
use App\Services\ImportService;
use App\Services\JwtService;
use App\Services\PaymentService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Services\Statement\StatementContext;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * FINAL CONTRIBUTION ENGINE REBUILD — Acceptance Tests
 *
 * Validates the single authoritative contribution allocation engine
 * across all three payment channels:
 *   CHANNEL A — Bank Statement Import
 *   CHANNEL B — Bank Statement Sync
 *   CHANNEL C — STK Push
 */
class ContributionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $phone, ?string $name = 'Test Member', ?string $firstContributionDate = null): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => ltrim($phone, '+') . '@welfare.local',
            'phone' => $phone,
            'encrypted_password' => AuthService::hashPassword('Secret123'),
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
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'first_contribution_date' => $firstContributionDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function seedWelfareSettings(float $monthly = 300): void
    {
        DB::table('welfare_settings')->insert([
            'id' => Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => $monthly,
            'contribution_due_day' => 15,
            'penalty_amount' => 50,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ========================================================================
    // ALLOCATION ENGINE CORE TESTS
    // ========================================================================

    /** TEST 1: 300 payment → 1 month */
    public function test_300_payment_allocates_one_month(): void
    {
        $memberId = $this->createMember('+254700000001', 'Test 300');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 300, '2025-01-01', 'TXN-300');

        $this->assertEquals(1, $result->monthsFullyPaid);
        $this->assertEquals(0.0, $result->creditRemaining);
        $this->assertEquals('2025-02-28', $result->nextDueDate);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(1, $paid);
    }

    /** TEST 2: 600 payment → 2 months */
    public function test_600_payment_allocates_two_months(): void
    {
        $memberId = $this->createMember('+254700000002', 'Test 600');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-600');

        $this->assertEquals(2, $result->monthsFullyPaid);
        $this->assertEquals(0.0, $result->creditRemaining);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(2, $paid);
    }

    /** TEST 3: 900 payment → 3 months */
    public function test_900_payment_allocates_three_months(): void
    {
        $memberId = $this->createMember('+254700000003', 'Test 900');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 900, '2025-01-01', 'TXN-900');

        $this->assertEquals(3, $result->monthsFullyPaid);
        $this->assertEquals(0.0, $result->creditRemaining);
    }

    /** TEST 4: 3600 payment → 12 months */
    public function test_3600_payment_allocates_twelve_months(): void
    {
        $memberId = $this->createMember('+254700000004', 'Test 3600');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 3600, '2025-01-01', 'TXN-3600');

        $this->assertEquals(12, $result->monthsFullyPaid);
        $this->assertEquals(0.0, $result->creditRemaining);
        $this->assertEquals('2026-01-31', $result->nextDueDate);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(12, $paid);
    }

    /** TEST 5: 450 payment → 1 full month + 150 partial */
    public function test_450_payment_one_full_month_plus_partial(): void
    {
        $memberId = $this->createMember('+254700000005', 'Test 450');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 450, '2025-01-01', 'TXN-450');

        $this->assertEquals(1, $result->monthsFullyPaid);
        $this->assertEquals(150.0, $result->creditRemaining);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $partial = DB::table('contributions')->where('member_id', $memberId)->where('status', 'partial')->count();
        $this->assertEquals(1, $paid);
        $this->assertEquals(1, $partial);
    }

    // ========================================================================
    // PREPAYMENT & OVERDUE TESTS
    // ========================================================================

    /** TEST 6: Prepaid months are never overdue for the covered period */
    public function test_prepaid_months_never_overdue(): void
    {
        $memberId = $this->createMember('+254700000006', 'Test Prepaid', '2026-01-01');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $svc->allocatePayment($memberId, 3600, '2026-01-10', 'TXN-PREPAID');

        $obligation = app(ContributionObligationService::class);
        $summary = $obligation->memberSummary($memberId);

        $this->assertEquals(12, $summary['paid_months']);
        $this->assertEquals(0, $summary['overdue_months']);
        $this->assertFalse($summary['is_overdue']);
    }

    /** TEST 16: Historical 3600 payment → 12 obligations allocated */
    public function test_historical_3600_payment_twelve_obligations(): void
    {
        $memberId = $this->createMember('+254700000016', 'Test Hist', '2026-01-01');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 3600, '2026-06-15', 'TXN-HIST-3600');

        $this->assertEquals(12, $result->monthsFullyPaid);

        $obligation = app(ContributionObligationService::class);
        $summary = $obligation->memberSummary($memberId);

        $this->assertEquals(12, $summary['paid_months']);
        $this->assertEquals(0, $summary['overdue_months']);
    }

    /** TEST 17: Prepaid through December → Oct/Nov/Dec not overdue */
    public function test_prepaid_through_december_not_overdue(): void
    {
        $memberId = $this->createMember('+254700000017', 'Test Pre Dec', '2026-01-01');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $svc->allocatePayment($memberId, 3600, '2026-01-10', 'TXN-PRE-DEC');

        $obligation = app(ContributionObligationService::class);
        $summary = $obligation->memberSummary($memberId);

        $this->assertEquals(12, $summary['paid_months']);
        $this->assertEquals(0, $summary['overdue_months']);
        $this->assertFalse($summary['is_overdue']);
    }

    /** TEST 18: Next due date follows final allocated obligation */
    public function test_next_due_date_follows_final_obligation(): void
    {
        $memberId = $this->createMember('+254700000018', 'Test Next Due', '2026-01-01');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $svc->allocatePayment($memberId, 3600, '2026-01-10', 'TXN-NEXT-DUE');

        $obligation = app(ContributionObligationService::class);
        $nextDue = $obligation->nextDueDate($memberId);

        $this->assertEquals('2027-01-31', $nextDue->format('Y-m-d'));
    }

    // ========================================================================
    // IDEMPOTENCY TESTS
    // ========================================================================

    /** TEST 13: Same STK success queried 10 times → exactly one payment */
    public function test_idempotent_allocation_same_payment_queried_multiple_times(): void
    {
        $memberId = $this->createMember('+254700000013', 'Test Idempotent');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);

        for ($i = 0; $i < 10; $i++) {
            $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-IDEM-600');
        }

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(2, $paid, '10 calls must still produce only 2 paid months');
    }

    /** TEST 14: STK success followed by bank sync of same payment → no duplicate */
    public function test_stk_then_bank_sync_no_duplicate(): void
    {
        $memberId = $this->createMember('+254700000014', 'Test STK+Sync');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-STK-SYNC');

        $processor = app(StatementProcessor::class);
        $ctx = new StatementContext(batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1');

        $outcome = $processor->process([
            'TransactionReference' => 'TXN-STK-SYNC',
            'TransactionDate' => '2025-01-01',
            'Narration' => 'Confirm. TEST STK+SYNC 0700000014',
            'CreditAmount' => 600,
            'DebitAmount' => 0,
        ], StatementMode::BANK_STATEMENT_SYNC, $ctx);

        $this->assertEquals('matched_existing', $outcome->reason);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(2, $paid);
    }

    /** TEST 15: Bank import followed by bank sync of same transaction → no duplicate */
    public function test_bank_import_then_bank_sync_no_duplicate(): void
    {
        $memberId = $this->createMember('+254700000015', 'Test Imp+Sync');
        $this->seedWelfareSettings(300);

        $importSvc = app(ImportService::class);
        $importSvc->importMemberStatement([
            [
                'trans_date' => '2025-01-01',
                'value_date' => '2025-01-01',
                'transaction_details' => 'Confirm. TEST IMP+SYNC 0700000015',
                'reference_no' => 'TXN-IMP-SYNC',
                'credit' => 600,
                'debit' => 0,
                'book_balance' => 0,
            ],
        ], 'test', 'test.xlsx', 'admin-1');

        $processor = app(StatementProcessor::class);
        $ctx = new StatementContext(batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1');

        $outcome = $processor->process([
            'TransactionReference' => 'TXN-IMP-SYNC',
            'TransactionDate' => '2025-01-01',
            'Narration' => 'Confirm. TEST IMP+SYNC 0700000015',
            'CreditAmount' => 600,
            'DebitAmount' => 0,
        ], StatementMode::BANK_STATEMENT_SYNC, $ctx);

        $this->assertEquals('duplicate', $outcome->reason);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(2, $paid);
    }

    // ========================================================================
    // CHANNEL A — BANK STATEMENT IMPORT
    // ========================================================================

    /** TEST 7: Bank import unknown member → member created */
    public function test_bank_import_creates_unknown_member(): void
    {
        $processor = app(StatementProcessor::class);
        $ctx = new StatementContext(batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1');

        $outcome = $processor->process([
            'TransactionReference' => 'IMP-UNKNOWN-001',
            'TransactionDate' => '2025-01-01',
            'Narration' => 'Confirm. JOHN DOE 0712345678',
            'CreditAmount' => 1000,
            'DebitAmount' => 0,
        ], StatementMode::TREASURER_STATEMENT_IMPORT, $ctx);

        $this->assertTrue($outcome->wasMatched);
        $this->assertEquals(1, $outcome->newMembers);
        $this->assertEquals(1, DB::table('members')->where('phone', '+254712345678')->count());
    }

    // ========================================================================
    // CHANNEL B — BANK STATEMENT SYNC
    // ========================================================================

    /** TEST 8: Bank sync unknown member → NOT created, unmatched recorded */
    public function test_bank_sync_does_not_create_unknown_member(): void
    {
        $processor = app(StatementProcessor::class);
        $ctx = new StatementContext(batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1');

        $outcome = $processor->process([
            'TransactionReference' => 'SYNC-UNKNOWN-001',
            'TransactionDate' => '2025-01-01',
            'Narration' => 'Confirm. UNKNOWN USER 0799999999',
            'CreditAmount' => 1000,
            'DebitAmount' => 0,
        ], StatementMode::BANK_STATEMENT_SYNC, $ctx);

        $this->assertEquals(0, $outcome->newMembers);
        $this->assertEquals(1, DB::table('bank_unmatched_transactions')->count());
        $this->assertEquals(0, DB::table('members')->where('phone', '+254799999999')->count());
    }

    // ========================================================================
    // CHANNEL C — STK PUSH
    // ========================================================================

    /** TEST 9: STK authenticated member → payment assigned to member_id, phone NOT primary */
    public function test_stk_uses_authenticated_member_id(): void
    {
        $memberId = $this->createMember('+254700000009', 'Test STK Auth');
        $this->seedWelfareSettings(300);

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-push', [
                'member_id' => $memberId,
                'amount' => 600,
                'phone' => '+254712345678',
                'accountReference' => 'CONTRIBUTION-' . $memberId,
                'paymentType' => 'monthly_contribution',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $contribution = DB::table('contributions')->where('member_id', $memberId)->first();
        $this->assertNotNull($contribution);
        $this->assertEquals($memberId, $contribution->member_id);
    }

    /** TEST 10: STK 1037 → PENDING, no financial posting */
    public function test_stk_1037_remains_pending_no_posting(): void
    {
        $memberId = $this->createMember('+254700000010', 'Test 1037');
        $this->seedWelfareSettings(300);

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-push', [
                'member_id' => $memberId,
                'amount' => 300,
                'phone' => '+254712345678',
                'accountReference' => 'CONTRIBUTION-1037',
                'paymentType' => 'monthly_contribution',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $checkoutId = $response->json('checkoutRequestId');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-callback', [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => 'mc-1037-test',
                        'CheckoutRequestID' => $checkoutId,
                        'ResultCode' => 1037,
                        'ResultDesc' => 'No response from user.',
                        'CallbackMetadata' => [],
                    ],
                ],
            ]);

        $contribution = DB::table('contributions')->where('stk_checkout_request_id', $checkoutId)->first();
        $this->assertNotNull($contribution);
        $this->assertEquals('pending', $contribution->status);
        $this->assertNull($contribution->paid_date);
    }

    /** TEST 11: STK final SUCCESS 300 → exactly one month */
    public function test_stk_success_300_exactly_one_month(): void
    {
        $memberId = $this->createMember('+254700000011', 'Test STK 300');
        $this->seedWelfareSettings(300);

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-push', [
                'member_id' => $memberId,
                'amount' => 300,
                'phone' => '+254712345678',
                'accountReference' => 'CONTRIBUTION-STK-300',
                'paymentType' => 'monthly_contribution',
            ]);

        $checkoutId = $response->json('checkoutRequestId');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-callback', [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => 'mc-stk-300',
                        'CheckoutRequestID' => $checkoutId,
                        'ResultCode' => 0,
                        'ResultDesc' => 'Success',
                        'CallbackMetadata' => [
                            'Item' => [
                                ['Name' => 'MpesaReceiptNumber', 'Value' => 'STK300RECEIPT'],
                            ],
                        ],
                    ],
                ],
            ]);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(1, $paid);
    }

    /** TEST 12: STK final SUCCESS 600 → exactly two months */
    public function test_stk_success_600_exactly_two_months(): void
    {
        $memberId = $this->createMember('+254700000012', 'Test STK 600');
        $this->seedWelfareSettings(300);

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-push', [
                'member_id' => $memberId,
                'amount' => 600,
                'phone' => '+254712345678',
                'accountReference' => 'CONTRIBUTION-STK-600',
                'paymentType' => 'monthly_contribution',
            ]);

        $checkoutId = $response->json('checkoutRequestId');

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson('/api/payments/stk-callback', [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => 'mc-stk-600',
                        'CheckoutRequestID' => $checkoutId,
                        'ResultCode' => 0,
                        'ResultDesc' => 'Success',
                        'CallbackMetadata' => [
                            'Item' => [
                                ['Name' => 'MpesaReceiptNumber', 'Value' => 'STK600RECEIPT'],
                            ],
                        ],
                    ],
                ],
            ]);

        $paid = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')->count();
        $this->assertEquals(2, $paid);
    }

    // ========================================================================
    // RECONCILIATION TESTS
    // ========================================================================

    /** TEST: Reconcile member re-allocates through authoritative engine */
    public function test_reconcile_member_reallocates_through_authoritative_engine(): void
    {
        $memberId = $this->createMember('+254700000020', 'Test Recon', '2026-01-01');
        $this->seedWelfareSettings(300);

        DB::table('bank_transactions')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'phone' => '+254700000020',
            'name' => 'Test Recon',
            'amount' => 3600,
            'transaction_date' => '2026-01-15',
            'month' => 1,
            'year' => 2026,
            'transaction_reference' => 'RECON-3600',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 3600,
            'debit_amount' => 0,
            'narration' => 'Reconciliation test',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'created_at' => now(),
        ]);

        $svc = app(ContributionReconciliationService::class);
        $result = $svc->reconcileMember($memberId, false);

        $this->assertTrue($result['fixed']);
        $this->assertEquals(3600.0, $result['total_paid']);

        $obligation = app(ContributionObligationService::class);
        $summary = $obligation->memberSummary($memberId);
        $this->assertEquals(12, $summary['paid_months']);
    }

    /** TEST: Reconciliation respects chronological order */
    public function test_reconciliation_respects_chronological_order(): void
    {
        $memberId = $this->createMember('+254700000021', 'Test Chrono', '2026-01-01');
        $this->seedWelfareSettings(300);

        DB::table('bank_transactions')->insert([
            ['id' => Uuid::v4(), 'member_id' => $memberId, 'phone' => '+254700000021', 'name' => 'Test Chrono',
             'amount' => 600, 'transaction_date' => '2026-03-15', 'month' => 3, 'year' => 2026,
             'transaction_reference' => 'CHRONO-1', 'transaction_type' => 'CREDIT', 'credit_amount' => 600,
             'debit_amount' => 0, 'narration' => 'Test', 'source' => 'test',
             'reconciliation_status' => 'matched', 'created_at' => now()],
            ['id' => Uuid::v4(), 'member_id' => $memberId, 'phone' => '+254700000021', 'name' => 'Test Chrono',
             'amount' => 300, 'transaction_date' => '2026-01-15', 'month' => 1, 'year' => 2026,
             'transaction_reference' => 'CHRONO-2', 'transaction_type' => 'CREDIT', 'credit_amount' => 300,
             'debit_amount' => 0, 'narration' => 'Test', 'source' => 'test',
             'reconciliation_status' => 'matched', 'created_at' => now()],
        ]);

        $svc = app(ContributionReconciliationService::class);
        $result = $svc->reconcileMember($memberId, false);

        $this->assertEquals(3, $result['paid_months']);
        $this->assertTrue($result['fixed']);

        $months = $result['months_covered'];
        $this->assertEquals(2026, $months[0]['year']);
        $this->assertEquals(1, $months[0]['month']);
        $this->assertEquals(2026, $months[1]['year']);
        $this->assertEquals(2, $months[1]['month']);
        $this->assertEquals(2026, $months[2]['year']);
        $this->assertEquals(3, $months[2]['month']);
    }

    // ========================================================================
    // PAYMENT RECORD IDEMPOTENCY
    // ========================================================================

    public function test_allocation_creates_payment_record(): void
    {
        $memberId = $this->createMember('+254700000030', 'Test Payment Rec');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-PAY-REC');

        $payment = DB::table('payments')->where('transaction_ref', 'TXN-PAY-REC')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(600, $payment->amount);
        $this->assertEquals($memberId, $payment->member_id);
    }

    public function test_allocation_skips_existing_payment_record(): void
    {
        $memberId = $this->createMember('+254700000031', 'Test Skip Pay Rec');
        $this->seedWelfareSettings(300);

        $svc = app(ContributionAllocationService::class);
        $result1 = $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-SKIP-PAY');
        $result2 = $svc->allocatePayment($memberId, 600, '2025-01-01', 'TXN-SKIP-PAY');

        $count = DB::table('payments')->where('transaction_ref', 'TXN-SKIP-PAY')->count();
        $this->assertEquals(1, $count);
        $this->assertEquals(2, $result1->monthsFullyPaid);
        $this->assertEquals(2, $result2->monthsFullyPaid);
    }

    // ========================================================================
    // FRONTEND HARDCODED 300 REMOVED
    // ========================================================================

    public function test_frontend_library_does_not_hardcode_300(): void
    {
        $path = base_path('../frontend/src/lib/contributions.ts');
        if (!file_exists($path)) {
            $path = base_path('frontend/src/lib/contributions.ts');
        }
        $this->assertFileExists($path, 'Frontend contributions library should exist');
        $content = file_get_contents($path);
        $this->assertStringNotContainsString('Math.floor(totalPaid / 300)', $content, 'Frontend must not inline hardcoded 300 in calculations');
        $this->assertStringNotContainsString('totalPaid / 300', $content, 'Frontend must not inline hardcoded 300 in calculations');
    }
}
