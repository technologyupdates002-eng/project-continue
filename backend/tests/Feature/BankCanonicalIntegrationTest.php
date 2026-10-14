<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\Statement\StatementContext;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankCanonicalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $phone): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
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
            'name' => 'Bank Member',
            'phone' => $phone,
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function process(array $transaction): object
    {
        return app(StatementProcessor::class)->process(
            $transaction,
            StatementMode::BANK_STATEMENT_SYNC,
            new StatementContext(batchId: Uuid::v4(), accountNumber: 'TEST', syncUserId: 'admin-1'),
        );
    }

    public function test_repeated_bank_transaction_creates_one_canonical_payment(): void
    {
        $memberId = $this->createMember('+254700000123');
        $transaction = [
            'TransactionReference' => 'BANK-CANONICAL-001',
            'TransactionDate' => '2026-09-01',
            'Narration' => 'Confirm. BANK MEMBER 0700000123',
            'CreditAmount' => 300,
            'DebitAmount' => 0,
        ];

        $first = $this->process($transaction);
        $second = $this->process($transaction);

        $this->assertTrue($first->wasMatched);
        $this->assertSame('duplicate', $second->reason);
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->where('provider_reference', 'BANK-CANONICAL-001')->count());
        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_name', 'bank')->where('provider_event_id', 'BANK-CANONICAL-001')->count());
        $this->assertSame(1, DB::table('contribution_allocations')->where('member_id', $memberId)->count());
    }

    public function test_unknown_bank_member_creates_no_member_user_and_open_exception(): void
    {
        $members = DB::table('members')->count();
        $users = DB::table('auth_users')->count();

        $outcome = $this->process([
            'TransactionReference' => 'BANK-UNKNOWN-001',
            'TransactionDate' => '2026-09-02',
            'Narration' => 'Unknown payer 0700000999',
            'CreditAmount' => 300,
            'DebitAmount' => 0,
        ]);

        $this->assertSame('unknown_member', $outcome->reason);
        $this->assertSame($members, DB::table('members')->count());
        $this->assertSame($users, DB::table('auth_users')->count());
        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'unknown_bank_transaction')->count());
    }

    public function test_ambiguous_bank_member_is_not_selected_and_is_reported(): void
    {
        $this->createMember('+254700000124');
        $this->createMember('0700000124');

        $outcome = $this->process([
            'TransactionReference' => 'BANK-AMBIGUOUS-001',
            'TransactionDate' => '2026-09-03',
            'Narration' => 'Ambiguous payer 0700000124',
            'CreditAmount' => 300,
            'DebitAmount' => 0,
        ]);

        $this->assertSame('ambiguous_member', $outcome->reason);
        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'ambiguous_bank_member')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }
}
