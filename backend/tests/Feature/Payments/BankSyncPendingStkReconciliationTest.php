<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\CoopTransferRequest;
use App\Services\AuthService;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Services\ContributionObligationService;
use App\Services\PaymentEngine;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\Statement\StatementContext;
use App\Services\Statement\StatementMode;
use App\Services\Statement\StatementProcessor;
use App\Services\WalletService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BankSyncPendingStkReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $phone = '+254700000077'): string
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
            'name' => 'Delayed STK Member',
            'phone' => $phone,
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function createCampaign(): string
    {
        $campaignId = Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Delayed STK Fund Drive',
            'description' => 'Reconciliation regression test',
            'amount' => 10000,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'goal_type' => 'fixed',
            'target_total' => 10000,
            'allow_partial' => 1,
            'total_collected' => 0,
        ]);

        return $campaignId;
    }

    private function createPendingStk(
        string $memberId,
        string $phone,
        string $paymentType,
        int $amountMinor,
        ?string $relatedEntityId = null,
        ?string $checkoutId = null,
    ): array {
        $checkoutId ??= 'ws_CO_' . bin2hex(random_bytes(12));
        $providerReference = 'STK-' . strtoupper(bin2hex(random_bytes(5)));
        $payment = app(PaymentEngine::class)->createPendingPayment(
            $memberId,
            'mpesa',
            $checkoutId,
            $checkoutId,
            $amountMinor,
            now()->toDateTimeString(),
            $paymentType,
            $relatedEntityId,
        );
        app(PaymentEngine::class)->updateProviderReference($payment['payment_id'], $providerReference, $checkoutId);

        if ($paymentType === 'donation') {
            DB::table('donation_payment_records')->insert([
                'id' => Uuid::v4(),
                'campaign_id' => $relatedEntityId,
                'member_id' => $memberId,
                'amount' => $amountMinor / 100,
                'status' => 'pending',
                'mpesa_transaction_id' => $checkoutId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $transfer = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => $providerReference,
            'transfer_type' => 'STK',
            'status' => 'timeout',
            'destination_account' => $phone,
            'amount' => $amountMinor / 100,
            'currency' => 'KES',
            'narration_internal' => 'STK payment',
            'narration_bank' => 'STK payment',
            'internal_reference' => $paymentType . '-' . $memberId . '-' . $checkoutId,
            'internal_table' => 'donation_payment_records',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'payment_id' => $payment['payment_id'],
            'checkout_id' => $checkoutId,
            'provider_reference' => $providerReference,
            'transfer_id' => $transfer->id,
        ];
    }

    private function processBankCredit(string $phone, string $reference, float $amount, string $narration): object
    {
        return app(StatementProcessor::class)->process([
            'TransactionReference' => $reference,
            'TransactionDate' => now()->toDateTimeString(),
            'Narration' => $narration . ' ' . $phone,
            'CreditAmount' => $amount,
            'DebitAmount' => 0,
        ], StatementMode::BANK_STATEMENT_SYNC, new StatementContext(
            batchId: Uuid::v4(),
            accountNumber: 'TEST-ACCOUNT',
            syncUserId: 'test-admin',
        ));
    }

    public function test_bank_sync_confirms_original_donation_and_delayed_status_poll_is_idempotent(): void
    {
        $phone = '+254700000077';
        $memberId = $this->createMember($phone);
        $campaignId = $this->createCampaign();
        $stk = $this->createPendingStk($memberId, $phone, 'donation', 60000, $campaignId);

        $outcome = $this->processBankCredit('0700000077', 'BANK-STK-DONATION-1', 600, 'FUND DRIVE');

        $this->assertSame('matched_pending_stk', $outcome->reason);
        $this->assertSame('confirmed', DB::table('payments')->where('id', $stk['payment_id'])->value('status'));
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->count());
        $this->assertSame('successful', DB::table('coop_transfer_requests')->where('id', $stk['transfer_id'])->value('status'));
        $this->assertSame('verified', DB::table('donation_payment_records')->where('mpesa_transaction_id', $stk['provider_reference'])->value('status'));
        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_type', 'donation')->where('direction', 'credit')->count());
        $this->assertSame(600.0, (float) DB::table('donation_campaigns')->where('id', $campaignId)->value('total_collected'));

        $statusService = app(PaymentService::class);
        $status = $statusService->getStatus(['member_id' => $memberId, 'roles' => ['member']], $stk['checkout_id']);
        $this->assertSame('confirmed', $status['status']);
        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_type', 'donation')->where('direction', 'credit')->count());
        $this->assertSame(600.0, (float) DB::table('donation_campaigns')->where('id', $campaignId)->value('total_collected'));

        $duplicate = $this->processBankCredit('0700000077', 'BANK-STK-DONATION-1', 600, 'FUND DRIVE');
        $this->assertSame('duplicate', $duplicate->reason);
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->count());
    }

    public function test_ambiguous_stk_candidates_create_exception_without_creating_bank_payment(): void
    {
        $phone = '+254700000078';
        $memberId = $this->createMember($phone);
        $this->createPendingStk($memberId, $phone, 'donation', 60000, null, 'ws_CO_aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->createPendingStk($memberId, $phone, 'donation', 60000, null, 'ws_CO_bbbbbbbbbbbbbbbbbbbbbbbb');

        $outcome = $this->processBankCredit('0700000078', 'BANK-STK-AMBIGUOUS-1', 600, 'DONATION');

        $this->assertSame('ambiguous_stk_bank_match', $outcome->reason);
        $this->assertSame(2, DB::table('payments')->where('member_id', $memberId)->count());
        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'ambiguous_stk_bank_match')->count());
        $this->assertSame(0, DB::table('contribution_allocations')->where('member_id', $memberId)->count());
    }

    public function test_monthly_stk_bank_sync_projects_canonical_allocations_to_legacy_contributions(): void
    {
        $phone = '+254700000079';
        $memberId = $this->createMember($phone);
        DB::table('welfare_settings')->insert([
            'id' => Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => 300,
            'contribution_due_day' => 15,
            'penalty_amount' => 100,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $checkoutId = 'ws_CO_cccccccccccccccccccccccc';
        DB::table('contributions')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
            'amount' => 600,
            'status' => 'pending',
            'stk_checkout_request_id' => $checkoutId,
            'due_date' => now()->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stk = $this->createPendingStk($memberId, $phone, 'monthly_contribution', 60000, null, $checkoutId);

        $outcome = $this->processBankCredit('0700000079', 'BANK-STK-MONTHLY-1', 600, 'MONTHLY CONTRIBUTION');

        $this->assertSame('matched_pending_stk', $outcome->reason);
        $this->assertSame('confirmed', DB::table('payments')->where('id', $stk['payment_id'])->value('status'));
        $this->assertSame(2, DB::table('contribution_allocations')->where('payment_id', $stk['payment_id'])->count());
        $this->assertSame(2, DB::table('contributions')->where('payment_id', $stk['payment_id'])->where('status', 'paid')->count());
        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->count());
    }

    public function test_sync_queue_does_not_run_delayed_stk_retries_inline(): void
    {
        $memberId = $this->createMember('+254700000080');
        $stkClient = $this->createMock(CoopBankTransactionService::class);
        $stkClient->expects($this->once())->method('initiateStkPush')->willReturn([
            'success' => true,
            'transfer_id' => Uuid::v4(),
            'message_reference' => 'STK-SYNC-QUEUE-1',
            'checkout_request_id' => 'BANK-CHECKOUT-1',
        ]);
        Queue::fake();
        config(['queue.default' => 'sync']);

        $service = new PaymentService(
            $stkClient,
            $this->createMock(CoopBankAccountService::class),
            $this->createMock(CoopBankTransferService::class),
            $this->createMock(WalletService::class),
            $this->createMock(PaymentLifecycleService::class),
            app(PaymentEngine::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );

        $service->initiateStkPush(
            ['member_id' => $memberId, 'roles' => ['member']],
            [
                'member_id' => $memberId,
                'phone' => '254700000080',
                'amount' => 300,
                'paymentType' => 'monthly_contribution',
            ],
        );

        Queue::assertNothingPushed();
        $this->assertSame(
            'STK-SYNC-QUEUE-1',
            DB::table('payments')->where('member_id', $memberId)->value('provider_reference'),
        );
    }
}
