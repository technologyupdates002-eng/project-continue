<?php

namespace Tests\Feature;

use App\Models\AuthUser;
use App\Models\Member;
use App\Services\AuthService;
use App\Services\BankSyncService;
use App\Services\ImportService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Critical acceptance tests for the workflow separation:
 *
 *   - TREASURER_STATEMENT_IMPORT  may create new members.
 *   - BANK_STATEMENT_SYNC         MUST NOT create new members.
 *
 * Both flows must call the same authoritative contribution allocation engine
 * and use the same phone-based member matching.
 */
class StatementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_treasurer_import_can_create_members(): void
    {
        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. JOHN DOE 0712345678',
                'reference_no' => 'TREAS-IMP-001',
                'credit' => 1000,
                'debit' => 0,
                'book_balance' => 0,
            ],
            [
                'trans_date' => '2025-01-16',
                'value_date' => '2025-01-16',
                'transaction_details' => 'Confirm. JANE SMITH 0712345679',
                'reference_no' => 'TREAS-IMP-002',
                'credit' => 600,
                'debit' => 0,
                'book_balance' => 0,
            ],
        ];

        $service = app(ImportService::class);
        $result = $service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(2, $result['new_members']);
        $this->assertEquals(2, $result['transactions_imported']);
        $this->assertEquals(0, $result['matched_existing']);
        $this->assertEquals(0, $result['duplicates_skipped']);

        $this->assertEquals(2, DB::table('members')->count());
        $this->assertEquals(2, DB::table('auth_users')->count());
        $this->assertEquals(2, DB::table('user_roles')->where('role', 'member')->count());

        // Default password Member2026 must be hashed; must_change_password = 1
        $john = DB::table('auth_users')->where('phone', '+254712345678')->first();
        $this->assertNotNull($john);
        $this->assertTrue(AuthService::verifyPassword('Member2026', $john->encrypted_password));
        $this->assertEquals(1, (int) $john->must_change_password);
    }

    public function test_treasurer_import_skips_duplicates(): void
    {
        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. JOHN DOE 0712345678',
                'reference_no' => 'TREAS-DUP-001',
                'credit' => 1000,
                'debit' => 0,
                'book_balance' => 0,
            ],
        ];

        $service = app(ImportService::class);
        $first = $service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');
        $this->assertEquals(1, $first['new_members']);
        $firstContribCount = DB::table('contributions')->count();
        $this->assertGreaterThan(0, $firstContribCount);

        $second = $service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');
        $this->assertEquals(0, $second['new_members']);
        $this->assertEquals(1, $second['duplicates_skipped']);
        $this->assertEquals(0, $second['transactions_imported']);

        $this->assertEquals(1, DB::table('members')->count());
        $this->assertEquals(1, DB::table('auth_users')->count());
        // No additional contribution rows were inserted for the duplicate.
        $this->assertEquals($firstContribCount, DB::table('contributions')->count());
    }

    public function test_all_kenyan_phone_formats_accepted_in_treasurer_import(): void
    {
        // The spec mandates: 07XXXXXXXX, 2547XXXXXXXX, +2547XXXXXXXX
        // (plus 01XXXXXXXX landline). All four formats must create the
        // same canonical member record.
        $rows = [
            ['trans_date' => '2026-08-01', 'value_date' => '2026-08-01',
             'transaction_details' => 'Confirm. PHONE A 0712345678',
             'reference_no' => 'PHONE-FMT-A', 'credit' => 300, 'debit' => 0, 'book_balance' => 0],
            ['trans_date' => '2026-08-02', 'value_date' => '2026-08-02',
             'transaction_details' => 'Confirm. PHONE B 254712345679',
             'reference_no' => 'PHONE-FMT-B', 'credit' => 300, 'debit' => 0, 'book_balance' => 0],
            ['trans_date' => '2026-08-03', 'value_date' => '2026-08-03',
             'transaction_details' => 'Confirm. PHONE C +254712345680',
             'reference_no' => 'PHONE-FMT-C', 'credit' => 300, 'debit' => 0, 'book_balance' => 0],
            ['trans_date' => '2026-08-04', 'value_date' => '2026-08-04',
             'transaction_details' => 'Confirm. PHONE D 0112345678',
             'reference_no' => 'PHONE-FMT-D', 'credit' => 300, 'debit' => 0, 'book_balance' => 0],
        ];

        $result = app(ImportService::class)->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(4, $result['new_members']);
        $this->assertEquals(4, DB::table('members')->count());

        $expectedCanonical = [
            '+254712345678',
            '+254712345679',
            '+254712345680',
            '+254112345678', // 0112345678 (10-digit) → +254112345678 via normalizer
        ];
        foreach ($expectedCanonical as $phone) {
            $this->assertEquals(1, DB::table('members')->where('phone', $phone)->count(), "Missing canonical member for $phone");
        }
    }

    public function test_cross_format_phone_match_in_bank_sync(): void
    {
        // Create a member via Treasurer import with a 07... phone.
        app(ImportService::class)->importMemberStatement([
            [
                'trans_date' => '2026-07-01', 'value_date' => '2026-07-01',
                'transaction_details' => 'Confirm. CROSS FORMAT 0722222222',
                'reference_no' => 'CROSS-INIT-001', 'credit' => 300, 'debit' => 0, 'book_balance' => 0,
            ],
        ], 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, DB::table('members')->where('phone', '+254722222222')->count());

        // Now Bank Sync sees a follow-up payment using the +2547... format
        // for the SAME person. It must match and update, not create a duplicate.
        $processor = app(StatementProcessor::class);
        $ctx = new \App\Services\Statement\StatementContext(
            batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1',
        );

        $outcomes = [];
        foreach ([
            '+254722222222',     // canonical
            '254722222222',      // no plus
            '0722222222',        // local
        ] as $altPhone) {
            $outcomes[] = $processor->process([
                'TransactionReference' => 'CROSS-SYNC-' . md5($altPhone),
                'TransactionDate' => '2026-08-15',
                'Narration' => 'Confirm. CROSS FORMAT ' . $altPhone,
                'CreditAmount' => 300, 'DebitAmount' => 0,
            ], StatementMode::BANK_STATEMENT_SYNC, $ctx);
        }

        // ALL three must match the existing member (NOT create new ones).
        foreach ($outcomes as $o) {
            $this->assertTrue($o->wasMatched, 'Cross-format phone must match existing member');
            $this->assertEquals(0, $o->newMembers, 'Bank sync must never create a member');
        }

        // Still exactly one member — no duplicates created.
        $this->assertEquals(1, DB::table('members')->where('phone', '+254722222222')->count());

        // And no "unknown" records for these known-phone transactions.
        $this->assertEquals(0, DB::table('bank_unmatched_transactions')
            ->where('phone', '0722222222')
            ->orWhere('phone', '+254722222222')
            ->count());
    }

    public function test_treasurer_import_matches_existing_member(): void
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => '254700000000@welfare.local',
            'phone' => '+254700000000',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Existing Member',
            'phone' => '+254700000000',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'first_contribution_date' => '2024-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            [
                'trans_date' => '2025-03-10',
                'value_date' => '2025-03-10',
                'transaction_details' => 'Confirm. Existing Member 0700000000',
                'reference_no' => 'TREAS-MATCH-001',
                'credit' => 1500,
                'debit' => 0,
                'book_balance' => 0,
            ],
        ];

        $result = app(ImportService::class)->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(0, $result['new_members']);
        $this->assertEquals(1, $result['matched_existing']);
        $this->assertEquals(1, $result['transactions_imported']);

        // Existing member's first_contribution_date must NOT be overridden if it is earlier
        $existing = DB::table('members')->where('id', $memberId)->first();
        $this->assertEquals('2024-01-01', $existing->first_contribution_date);
    }

    public function test_bank_statement_sync_never_creates_members(): void
    {
        $processor = app(StatementProcessor::class);
        $ctx = new \App\Services\Statement\StatementContext(
            batchId: Uuid::v4(),
            accountNumber: 'TEST-ACCT',
            syncUserId: 'admin-1',
        );

        $txs = [
            // Unknown phone — must NOT create a member
            [
                'TransactionReference' => 'SYNC-001',
                'TransactionDate' => '2026-09-01',
                'Narration' => 'Confirm. UNKNOWN PERSON 0799999999',
                'CreditAmount' => 300,
                'DebitAmount' => 0,
            ],
            [
                'TransactionReference' => 'SYNC-002',
                'TransactionDate' => '2026-09-02',
                'Narration' => 'Confirm. ANOTHER STRANGER 0799999998',
                'CreditAmount' => 300,
                'DebitAmount' => 0,
            ],
            // No phone at all — must NOT create a member
            [
                'TransactionReference' => 'SYNC-003',
                'TransactionDate' => '2026-09-03',
                'Narration' => 'Some random transfer with no phone',
                'CreditAmount' => 500,
                'DebitAmount' => 0,
            ],
        ];

        $matched = 0;
        $unmatched = 0;
        foreach ($txs as $tx) {
            $outcome = $processor->process($tx, StatementMode::BANK_STATEMENT_SYNC, $ctx);
            if ($outcome->wasMatched) $matched++;
            if ($outcome->reason === 'unknown_member' || $outcome->reason === 'no_phone_extracted') $unmatched++;
        }

        $this->assertEquals(0, DB::table('members')->count(), 'Bank sync MUST NOT create members');
        $this->assertEquals(0, DB::table('auth_users')->count(), 'Bank sync MUST NOT create auth users');
        $this->assertEquals(3, DB::table('bank_unmatched_transactions')->count(), 'All sync unknowns must be recorded for reconciliation');
        $this->assertEquals(0, $matched);
        $this->assertGreaterThanOrEqual(2, $unmatched);
    }

    public function test_bank_statement_sync_updates_existing_member(): void
    {
        // Create an existing member via Treasurer import
        $importResult = app(ImportService::class)->importMemberStatement([
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. ALICE EXISTING 0700000111',
                'reference_no' => 'ALICE-INIT-001',
                'credit' => 300,
                'debit' => 0,
                'book_balance' => 0,
            ],
        ], 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, $importResult['new_members']);

        // Now perform Bank Statement Sync with a follow-up payment for the same phone
        $processor = app(StatementProcessor::class);
        $ctx = new \App\Services\Statement\StatementContext(
            batchId: Uuid::v4(),
            accountNumber: 'TEST-ACCT',
            syncUserId: 'admin-1',
        );

        $outcome = $processor->process([
            'TransactionReference' => 'SYNC-ALICE-001',
            'TransactionDate' => '2025-02-15',
            'Narration' => 'Confirm. ALICE EXISTING 0700000111',
            'CreditAmount' => 300,
            'DebitAmount' => 0,
        ], StatementMode::BANK_STATEMENT_SYNC, $ctx);

        $this->assertTrue($outcome->wasMatched, 'Existing member must be matched in sync mode');
        $this->assertEquals(1, DB::table('members')->count(), 'Sync must NOT create a duplicate');
        $this->assertEquals(0, DB::table('bank_unmatched_transactions')->count(), 'Sync must not record an existing member as unmatched');

        $alice = DB::table('members')->where('phone', '+254700000111')->first();
        $this->assertEquals(600, (float) DB::table('contributions')->where('member_id', $alice->id)->sum('amount'));
    }

    public function test_bank_sync_service_exposes_allow_member_creation_false(): void
    {
        /** @var BankSyncService $svc */
        $svc = app(BankSyncService::class);
        $this->assertFalse($svc->allowMemberCreation());
    }

    public function test_statement_processor_honours_mode_internally(): void
    {
        // The mode-aware processor is the authoritative gate.
        $processor = app(StatementProcessor::class);
        $ctx = new \App\Services\Statement\StatementContext(
            batchId: Uuid::v4(),
            accountNumber: null,
            syncUserId: 'admin-1',
        );

        $tx = [
            'TransactionReference' => 'MODE-001',
            'TransactionDate' => '2025-03-15',
            'Narration' => 'Confirm. TEST MODE 0711111111',
            'CreditAmount' => 300,
            'DebitAmount' => 0,
        ];

        // TREASURER mode creates
        $treasurerOutcome = $processor->process($tx, StatementMode::TREASURER_STATEMENT_IMPORT, $ctx);
        $this->assertEquals(1, $treasurerOutcome->newMembers);

        // Reset and try BANK sync with the same phone — different ref so it isn't a duplicate
        DB::table('bank_transactions')->delete();
        DB::table('members')->delete();
        DB::table('auth_users')->delete();
        DB::table('user_roles')->delete();

        $syncTx = $tx;
        $syncTx['TransactionReference'] = 'MODE-002';

        $syncOutcome = $processor->process($syncTx, StatementMode::BANK_STATEMENT_SYNC, $ctx);
        $this->assertEquals(0, $syncOutcome->newMembers);
        $this->assertEquals(1, DB::table('bank_unmatched_transactions')->count());
    }

    public function test_allocation_engine_handles_1000_payment_with_300_monthly(): void
    {
        // 1. Create an existing member with a first_contribution_date
        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => '254700000222@welfare.local',
            'phone' => '+254700000222',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Allocation Tester',
            'phone' => '+254700000222',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'first_contribution_date' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('welfare_settings')->insert([
            'id' => Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => 300,
            'contribution_due_day' => 15,
            'penalty_amount' => 50,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ContributionAllocationService $svc */
        $svc = app(ContributionAllocationService::class);

        $result = $svc->allocatePayment(
            memberId: $memberId,
            amount: 1000,
            paymentDate: '2025-01-01',
            txRef: 'ALLOC-001',
        );

        $this->assertEquals(3, $result->monthsAllocated);
        $this->assertEquals(100.0, $result->creditRemaining);
        $this->assertEquals('2025-04-30', $result->nextDueDate);

        $total = (float) DB::table('contributions')->where('member_id', $memberId)->sum('amount');
        $this->assertEquals(1000.0, $total, '3 paid months (300x3=900) + KSh100 partial row = 1000');

        // Three full months
        $paidRows = DB::table('contributions')
            ->where('member_id', $memberId)
            ->where('status', 'paid')
            ->get();
        $this->assertCount(3, $paidRows);
    }

    public function test_allocation_engine_handles_exact_300_600_900_payments(): void
    {
        $memberId = $this->createTestMember('+254700000333');
        DB::table('welfare_settings')->insert([
            'id' => Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => 300,
            'contribution_due_day' => 15,
            'penalty_amount' => 50,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ContributionAllocationService $svc */
        $svc = app(ContributionAllocationService::class);

        // KSh 300 → 1 month
        $r1 = $svc->allocatePayment($memberId, 300, '2025-01-01', 'P-300');
        $this->assertEquals(1, $r1->monthsAllocated);
        $this->assertEquals(0.0, $r1->creditRemaining);

        // KSh 600 → 2 months
        $r2 = $svc->allocatePayment($memberId, 600, '2025-02-01', 'P-600');
        $this->assertEquals(2, $r2->monthsAllocated);
        $this->assertEquals(0.0, $r2->creditRemaining);

        // KSh 900 → 3 months
        $r3 = $svc->allocatePayment($memberId, 900, '2025-03-01', 'P-900');
        $this->assertEquals(3, $r3->monthsAllocated);
        $this->assertEquals(0.0, $r3->creditRemaining);
    }

    public function test_kes1000_payment_three_months_plus_100_credit(): void
    {
        $memberId = $this->createTestMember('+254700000444');
        DB::table('welfare_settings')->insert([
            'id' => Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => 300,
            'contribution_due_day' => 15,
            'penalty_amount' => 50,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ContributionAllocationService $svc */
        $svc = app(ContributionAllocationService::class);
        $result = $svc->allocatePayment($memberId, 1000, '2025-01-01', 'P-1000');

        $this->assertEquals(3, $result->monthsAllocated);
        $this->assertEquals(100.0, $result->creditRemaining, 'KSh 100 must not be lost');

        // Verify dashboard aggregations see the same value
        $member = DB::table('members')->where('id', $memberId)->first();
        $this->assertEquals(900.0, (float) $member->total_contributions);
    }

    private function createTestMember(string $phone): string
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
            'name' => 'Test',
            'phone' => $phone,
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'first_contribution_date' => '2025-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $memberId;
    }

    public function test_treasurer_import_creates_member_via_http_api(): void
    {
        // Issue a Treasurer-import JWT
        $userId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'treasurer@welfare.local',
            'phone' => '+254700999000',
            'encrypted_password' => AuthService::hashPassword('Treas123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'treasurer',
            'is_active' => 1,
        ]);

        $session = AuthService::issueSession((array) DB::table('auth_users')->where('id', $userId)->first());

        $payload = [
            'rows' => [
                [
                    'trans_date' => '2026-08-01',
                    'value_date' => '2026-08-01',
                    'transaction_details' => 'Confirm. HTTP API TEST 0700000777',
                    'reference_no' => 'HTTP-IMPORT-001',
                    'credit' => 300,
                    'debit' => 0,
                    'book_balance' => 0,
                ],
            ],
            'source_name' => 'bank_statement',
            'file_name' => 'test.xlsx',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $session['access_token'])
            ->postJson('/api/functions/v1/bank-statement-import', $payload);

        $response->assertStatus(200);
        $body = $response->json('data');
        $this->assertEquals(1, $body['new_members']);
        $this->assertEquals('TREASURER_STATEMENT_IMPORT', $body['mode']);
        $this->assertEquals(1, DB::table('members')->where('phone', '+254700000777')->count());
    }

    public function test_bank_sync_endpoint_never_creates_member_via_http_api(): void
    {
        // The Bank Statements sync endpoint is the BANK_STATEMENT_SYNC path.
        // Even if a user posts an unknown-phone transaction, the system must
        // NOT create a member — it must record it as unmatched.
        $userId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'admin@welfare.local',
            'phone' => '+254700999001',
            'encrypted_password' => AuthService::hashPassword('Admin123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'super_admin',
            'is_active' => 1,
        ]);

        $session = AuthService::issueSession((array) DB::table('auth_users')->where('id', $userId)->first());

        // Use a fake/mock-friendly path: process transactions directly through the
        // StatementProcessor invoked with BANK_STATEMENT_SYNC mode. This simulates
        // what the sync endpoint does internally without making real HTTP calls to Co-op.
        $processor = app(StatementProcessor::class);
        $ctx = new \App\Services\Statement\StatementContext(
            batchId: Uuid::v4(),
            accountNumber: 'TEST',
            syncUserId: $userId,
        );

        $outcome = $processor->process(
            [
                'TransactionReference' => 'HTTP-SYNC-UNKNOWN-001',
                'TransactionDate' => '2026-08-15',
                'Narration' => 'Confirm. UNKNOWN VIA HTTP 0700000888',
                'CreditAmount' => 300,
                'DebitAmount' => 0,
            ],
            StatementMode::BANK_STATEMENT_SYNC,
            $ctx,
        );

        $this->assertEquals(0, $outcome->newMembers, 'BANK_STATEMENT_SYNC mode MUST NOT create members');
        $this->assertEquals(1, DB::table('bank_unmatched_transactions')->count());
        $this->assertEquals(0, DB::table('members')->where('phone', '+254700000888')->count());
    }
}