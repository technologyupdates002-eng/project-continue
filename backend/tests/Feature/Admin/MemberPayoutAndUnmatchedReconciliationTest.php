<?php

namespace Tests\Feature\Admin;

use App\Services\BankSyncService;
use App\Services\JwtService;
use App\Services\MemberPayoutService;
use App\Services\PaymentLifecycleService;
use App\Services\SmsService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MemberPayoutAndUnmatchedReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private array $headers;
    private string $userId = '05ade1a2-2aba-425c-b298-3b27f1d2c13b';

    protected function setUp(): void
    {
        parent::setUp();

        $token = JwtService::encode(JwtService::buildPayload(
            $this->userId,
            'treasurer@welfare.local',
            ['member', 'treasurer']
        ));
        $this->headers = ['Authorization' => "Bearer {$token}"];
    }

    public function test_member_payout_uses_eligible_dropdown_and_stays_in_history_after_payment(): void
    {
        $memberId = Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Jane Wanjiku',
            'phone' => '0712345678',
            'member_id' => 'JW-10',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/admin/payouts/eligible?payout_type=wedding', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $memberId)
            ->assertJsonPath('data.0.member_code', 'JW-10')
            ->assertJsonPath('data.0.eligible_amount', 0);

        $this->postJson('/api/admin/payouts', [
            'member_id' => $memberId,
            'amount' => 25000,
            'payout_type' => 'wedding',
            'wallet_type' => 'operational',
        ], $this->headers)->assertUnprocessable();

        DB::table('organization_settings')->insert([
            'id' => Uuid::v4(),
            'payout_rules' => json_encode(['wedding' => 25000]),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/admin/payouts/eligible?payout_type=wedding', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.eligible_amount', 25000);

        $response = $this->postJson('/api/admin/payouts', [
            'member_id' => $memberId,
            'amount' => 25000,
            'payout_type' => 'wedding',
            'reason' => 'Wedding support',
            'wallet_type' => 'operational',
        ], $this->headers)->assertCreated();

        $payoutId = $response->json('data.id');
        $withdrawalId = $response->json('data.withdrawal_id');
        $this->assertDatabaseHas('payouts', [
            'id' => $payoutId,
            'member_id' => $memberId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('operational_withdrawals', [
            'id' => $withdrawalId,
            'status' => 'pending',
        ]);
        $this->postJson('/api/admin/payouts', [
            'member_id' => $memberId,
            'amount' => 25000,
            'payout_type' => 'wedding',
            'wallet_type' => 'operational',
        ], $this->headers)->assertStatus(409);

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('send')
            ->once()
            ->with('0712345678', Mockery::type('string'), 'member_payout', $payoutId)
            ->andReturn(['success' => false, 'error' => 'test provider failure']);
        $this->app->instance(SmsService::class, $sms);
        app(MemberPayoutService::class)->markWithdrawalCompleted($withdrawalId);

        $this->getJson('/api/admin/payouts/eligible?payout_type=wedding', $this->headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/payouts', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.member_id', $memberId)
            ->assertJsonPath('data.0.member_name', 'Jane Wanjiku')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.reference', 'PAY-' . strtoupper(substr($payoutId, 0, 8)));
    }

    public function test_unmatched_match_types_dispatch_canonical_handlers_and_record_audit(): void
    {
        $memberId = Uuid::v4();
        $bankTransactionId = Uuid::v4();
        $unmatchedId = Uuid::v4();
        $reference = 'BANK-MATCH-TEST-1';
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'George Kamau',
            'phone' => '0712345679',
            'member_id' => 'GK-11',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('bank_transactions')->insert([
            'id' => $bankTransactionId,
            'member_id' => null,
            'phone' => '0712345679',
            'amount' => 500,
            'transaction_date' => now()->toDateString(),
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
            'transaction_reference' => $reference,
            'credit_amount' => 500,
            'debit_amount' => 0,
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);
        DB::table('bank_unmatched_transactions')->insert([
            'id' => $unmatchedId,
            'bank_reference' => $reference,
            'transaction_date' => now()->toDateString(),
            'amount' => 500,
            'credit_amount' => 500,
            'debit_amount' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bankSync = Mockery::mock(BankSyncService::class);
        $bankSync->shouldReceive('reconcileManually')
            ->once()
            ->with($bankTransactionId, [
                'member_id' => $memberId,
                'category' => 'monthly_contribution',
            ], $this->userId)
            ->andReturn([
                'success' => true,
                'transaction_id' => $bankTransactionId,
                'member_id' => $memberId,
                'contribution_id' => 'contribution-test-id',
            ]);
        $this->app->instance(BankSyncService::class, $bankSync);

        $this->postJson("/api/banking/coop/unmatched-transactions/{$unmatchedId}/reconcile", [
            'match_type' => 'contribution',
            'member_id' => $memberId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.match_type', 'contribution')
            ->assertJsonPath('data.target_id', 'contribution-test-id');

        $this->assertDatabaseHas('bank_unmatched_transactions', [
            'id' => $unmatchedId,
            'status' => 'matched',
            'resolved_by' => $this->userId,
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'id' => $bankTransactionId,
            'reconciliation_status' => 'matched',
            'category' => 'monthly_contribution',
        ]);
        $this->postJson("/api/banking/coop/unmatched-transactions/{$unmatchedId}/reconcile", [
            'match_type' => 'contribution',
            'member_id' => $memberId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('idempotent', true);
        $audit = json_decode((string) DB::table('bank_unmatched_transactions')->where('id', $unmatchedId)->value('resolution_notes'), true);
        $this->assertSame($reference, $audit['bank_reference']);

        $createStatementRows = function (string $reference, float $credit, float $debit): array {
            $bankId = Uuid::v4();
            $unmatchedRowId = Uuid::v4();
            $amount = $credit > 0 ? $credit : $debit;

            DB::table('bank_transactions')->insert([
                'id' => $bankId,
                'member_id' => null,
                'phone' => '0712345679',
                'amount' => $amount,
                'transaction_date' => now()->toDateString(),
                'month' => (int) now()->format('n'),
                'year' => (int) now()->format('Y'),
                'transaction_reference' => $reference,
                'credit_amount' => $credit,
                'debit_amount' => $debit,
                'reconciliation_status' => 'unmatched',
                'created_at' => now(),
            ]);
            DB::table('bank_unmatched_transactions')->insert([
                'id' => $unmatchedRowId,
                'bank_reference' => $reference,
                'transaction_date' => now()->toDateString(),
                'amount' => $amount,
                'credit_amount' => $credit,
                'debit_amount' => $debit,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$bankId, $unmatchedRowId];
        };

        [$payoutBankId, $payoutUnmatchedId] = $createStatementRows('BANK-PAYOUT-TEST', 0, 500);

        $this->postJson("/api/banking/coop/unmatched-transactions/{$payoutUnmatchedId}/reconcile", [
            'match_type' => 'payout',
            'member_id' => $memberId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.match_type', 'payout')
            ->assertJsonPath('data.target_id', $memberId)
            ->assertJsonPath('data.member_id', $memberId);
        $this->assertDatabaseHas('bank_transactions', [
            'id' => $payoutBankId,
            'reconciliation_status' => 'matched',
            'category' => 'payout',
        ]);

        $penaltyId = Uuid::v4();
        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 300,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);
        $penaltyReference = 'BANK-PENALTY-TEST';
        [$penaltyBankId, $penaltyUnmatchedId] = $createStatementRows($penaltyReference, 300, 0);

        $campaignId = Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Reconciliation Test Drive',
            'amount' => 300,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fundriveReference = 'BANK-FUNDRIVE-TEST';
        [$fundriveBankId, $fundriveUnmatchedId] = $createStatementRows($fundriveReference, 300, 0);

        $lifecycle = Mockery::mock(PaymentLifecycleService::class);
        $lifecycle->shouldReceive('postPayment')
            ->once()
            ->with('penalty', $memberId, 300.0, $penaltyReference, $penaltyId, 'bank_manual_reconciliation', null, $penaltyReference)
            ->andReturn(['success' => true]);
        $lifecycle->shouldReceive('postPayment')
            ->once()
            ->with('fund_drive', $memberId, 300.0, $fundriveReference, $campaignId, 'bank_manual_reconciliation', null, $fundriveReference)
            ->andReturn(['success' => true]);
        $this->app->instance(PaymentLifecycleService::class, $lifecycle);

        $this->postJson("/api/banking/coop/unmatched-transactions/{$penaltyUnmatchedId}/reconcile", [
            'match_type' => 'penalty',
            'member_id' => $memberId,
            'penalty_id' => $penaltyId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.match_type', 'penalty')
            ->assertJsonPath('data.target_id', $penaltyId);

        $this->postJson("/api/banking/coop/unmatched-transactions/{$fundriveUnmatchedId}/reconcile", [
            'match_type' => 'fundrive',
            'member_id' => $memberId,
            'campaign_id' => $campaignId,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('data.match_type', 'fundrive')
            ->assertJsonPath('data.target_id', $campaignId);

        foreach ([
            [$payoutBankId, $payoutUnmatchedId, 'payout'],
            [$penaltyBankId, $penaltyUnmatchedId, 'penalty'],
            [$fundriveBankId, $fundriveUnmatchedId, 'fundrive'],
        ] as [$matchedBankId, $matchedUnmatchedId, $matchType]) {
            $this->assertDatabaseHas('bank_transactions', [
                'id' => $matchedBankId,
                'reconciliation_status' => 'matched',
            ]);
            $this->assertDatabaseHas('bank_unmatched_transactions', [
                'id' => $matchedUnmatchedId,
                'status' => 'matched',
            ]);
            $matchAudit = json_decode((string) DB::table('bank_unmatched_transactions')
                ->where('id', $matchedUnmatchedId)
                ->value('resolution_notes'), true);
            $this->assertSame($matchType, $matchAudit['match_type']);
        }
    }
}
