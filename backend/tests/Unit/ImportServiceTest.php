<?php

namespace Tests\Unit;

use App\Services\AuthService;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportService();
    }

    public function test_extract_phone_from_details(): void
    {
        $processor = app(\App\Services\Statement\StatementProcessor::class);
        $this->assertSame('0712345678', $this->invokeMethod($processor, 'extractPhone', ['Payment from JOHN DOE 0712345678']));
        $this->assertSame('254712345678', $this->invokeMethod($processor, 'extractPhone', ['Payment from JOHN DOE 254712345678']));
        $this->assertSame('+254712345678', $this->invokeMethod($processor, 'extractPhone', ['+254712345678 Payment']));
        $this->assertNull($this->invokeMethod($processor, 'extractPhone', ['No phone here']));
    }

    public function test_extract_name_from_details(): void
    {
        $processor = app(\App\Services\Statement\StatementProcessor::class);
        // "Confirm. JOHN DOE 0712345678" -> "JOHN DOE"
        $this->assertSame('JOHN DOE', $this->invokeMethod($processor, 'extractName', ['Confirm. JOHN DOE 0712345678', null]));
        // "Confirm. JANE SMITH Payment" -> "JANE SMITH Payment" (alpha-only)
        $this->assertSame('JANE SMITH Payment', $this->invokeMethod($processor, 'extractName', ['JANE SMITH Payment', null]));
        // "M-PESA JOHN KAMAU 254711111111" -> "JOHN KAMAU"
        $this->assertSame('JOHN KAMAU', $this->invokeMethod($processor, 'extractName', ['M-PESA JOHN KAMAU 254711111111', null]));
        // "Payment from JOHN KAMAU 0712345678" -> "JOHN KAMAU"
        $this->assertSame('JOHN KAMAU', $this->invokeMethod($processor, 'extractName', ['Payment from JOHN KAMAU 0712345678', null]));
        $this->assertNull($this->invokeMethod($processor, 'extractName', [null, null]));
    }

    public function test_import_creates_new_member_with_default_password(): void
    {
        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. JOHN DOE 0712345678',
                'reference_no' => 'REF-001',
                'credit' => 1000,
                'debit' => 0,
                'book_balance' => 5000,
            ],
        ];

        $result = $this->service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, $result['new_members']);
        // KSh 1000 at KSh 300/mo = 3 fully paid months + 1 partial row (300 credit remaining).
        $this->assertEquals(4, $result['contributions_created'], 'Three paid months plus one partial month row');
        $this->assertEquals(1000, $result['total_amount']);
        $this->assertEquals(1, $result['transactions_imported']);

        $importRow = DB::table('member_import_rows')->where('batch_id', $result['batch_id'])->first();
        $this->assertEquals('Member2026', $importRow->default_password);
        $this->assertEquals(1, $importRow->auth_user_created);
        $this->assertEquals(1, $importRow->member_dashboard_created);

        $this->assertDatabaseHas('auth_users', [
            'email' => '254712345678@welfare.local',
            'must_change_password' => 1,
        ]);

        $this->assertDatabaseHas('members', [
            'phone' => '+254712345678',
            'name' => 'JOHN DOE',
        ]);

        $this->assertDatabaseHas('user_roles', [
            'role' => 'member',
        ]);

        // KSh 1000 paid against KSh 300 monthly = 3 paid months (900) + KSh 100 partial credit row.
        $this->assertEquals(1000.0, (float) DB::table('contributions')->sum('amount'));
        $paidCount = DB::table('contributions')->where('status', 'paid')->count();
        $this->assertEquals(3, $paidCount, 'KSh 1000 at KSh 300/mo = 3 fully paid months');
        $partialCount = DB::table('contributions')->where('status', 'partial')->count();
        $this->assertEquals(1, $partialCount, 'KSh 100 credit remaining must be persisted as a partial row');

        $member = DB::table('members')->where('phone', '+254712345678')->first();
        $this->assertEquals(900, $member->total_contributions);
    }

    public function test_import_matches_existing_member(): void
    {
        $userId = \App\Support\Uuid::v4();
        $memberId = \App\Support\Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => '254712345678@welfare.local',
            'phone' => '+254712345678',
            'encrypted_password' => AuthService::hashPassword('oldpass'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Existing User',
            'phone' => '+254712345678',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 500,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contributions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 500,
            'month' => 12,
            'year' => 2024,
            'due_date' => '2024-12-31',
            'status' => 'paid',
            'paid_date' => '2024-12-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. JOHN DOE 0712345678',
                'reference_no' => 'REF-002',
                'credit' => 2000,
                'debit' => 0,
                'book_balance' => 7000,
            ],
        ];

        $result = $this->service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(0, $result['new_members']);
        $this->assertEquals(1, $result['matched_existing']);
        // New allocation: existing 500 in Dec 2024 + KSh 2000 in Jan 2025 = 6 paid months (300×6) plus a KSh 200 partial
        $this->assertEquals(7, $result['contributions_created'], 'Six paid months plus one partial row');
        $this->assertEquals(2000, $result['total_amount']);

        $member = DB::table('members')->where('id', $memberId)->first();
        // Total_contributions reflects fully paid rows only: 500 (existing) + 6×300 (new) = 2300.
        $this->assertEquals(2300, (float) $member->total_contributions);
    }

    public function test_import_skips_duplicate_transactions(): void
    {
        $txId = \App\Support\Uuid::v4();
        DB::table('bank_transactions')->insert([
            'id' => $txId,
            'bank_name' => 'coop',
            'member_id' => null,
            'phone' => '+254712345678',
            'name' => 'Test',
            'amount' => 1000,
            'transaction_date' => '2025-01-15',
            'month' => 1,
            'year' => 2025,
            'transaction_reference' => 'REF-DUP-001',
            'transaction_type' => 'CREDIT',
            'credit_amount' => 1000,
            'debit_amount' => 0,
            'narration' => 'Test',
            'source' => 'test',
            'reconciliation_status' => 'matched',
            'member_matched_at' => now(),
            'created_at' => now(),
        ]);

        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Confirm. JOHN DOE 0712345678',
                'reference_no' => 'REF-DUP-001',
                'credit' => 1000,
                'debit' => 0,
                'book_balance' => 5000,
            ],
        ];

        $result = $this->service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, $result['duplicates_skipped']);
        $this->assertEquals(0, $result['new_members']);
        $this->assertEquals(0, $result['contributions_created']);

        $this->assertEquals(1, DB::table('bank_transactions')->where('transaction_reference', 'REF-DUP-001')->count());
    }

    public function test_import_marks_debit_as_unmatched(): void
    {
        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Withdrawal 0712345678',
                'reference_no' => 'REF-DEBIT-001',
                'credit' => 0,
                'debit' => 500,
                'book_balance' => 4500,
            ],
        ];

        $result = $this->service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, $result['unmatched']);
        $this->assertEquals(0, $result['new_members']);
        $this->assertEquals(0, $result['contributions_created']);

        $importRow = DB::table('member_import_rows')->where('batch_id', $result['batch_id'])->first();
        $this->assertEquals('unmatched', $importRow->match_status);
    }

    public function test_import_skips_rows_without_phone(): void
    {
        $rows = [
            [
                'trans_date' => '2025-01-15',
                'value_date' => '2025-01-15',
                'transaction_details' => 'Some payment without phone',
                'reference_no' => 'REF-NOPHONE',
                'credit' => 1000,
                'debit' => 0,
                'book_balance' => 5000,
            ],
        ];

        $result = $this->service->importMemberStatement($rows, 'test', 'test.xlsx', 'admin-1');

        $this->assertEquals(1, $result['invalid_phones']);
        $this->assertEquals(0, $result['new_members']);
    }

    public function test_login_with_default_password_sets_must_change_password(): void
    {
        $userId = \App\Support\Uuid::v4();
        $hashedPassword = AuthService::hashPassword('Member2026');

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => '254712345678@welfare.local',
            'phone' => '+254712345678',
            'encrypted_password' => $hashedPassword,
            'must_change_password' => 1,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => \App\Support\Uuid::v4(),
            'user_id' => $userId,
            'name' => 'Test User',
            'phone' => '+254712345678',
            'member_id' => \App\Support\Uuid::v4(),
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = DB::table('auth_users')->where('id', $userId)->first();
        $session = AuthService::issueSession((array) $user);

        $this->assertTrue($session['user']['must_change_password']);
        $this->assertStringContainsString('.', $session['access_token']);
    }

    public function test_change_password_clears_must_change_password(): void
    {
        $userId = \App\Support\Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => '254712345678@welfare.local',
            'phone' => '+254712345678',
            'encrypted_password' => AuthService::hashPassword('Member2026'),
            'must_change_password' => 1,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controller = new \App\Http\Controllers\AuthController();
        $request = \Illuminate\Http\Request::create('/auth/v1/password', 'POST', [
            'current_password' => 'Member2026',
            'new_password' => 'NewPass123',
        ]);
        $request->attributes->set('identity', [
            'user_id' => $userId,
            'email' => '254712345678@welfare.local',
            'roles' => ['member'],
        ]);

        $response = $controller->changePassword($request);
        $this->assertEquals(200, $response->getStatusCode());

        $user = DB::table('auth_users')->where('id', $userId)->first();
        $this->assertEquals(0, $user->must_change_password);
        $this->assertTrue(AuthService::verifyPassword('NewPass123', $user->encrypted_password));
    }

    private function invokeMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
