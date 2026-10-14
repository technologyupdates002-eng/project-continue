<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Services\ContributionObligationService;
use App\Services\PaymentEngine;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\WalletService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StkStatusPollingTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000077',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Polling Member',
            'phone' => '+254700000077',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function service(CoopBankAccountService $accountService): PaymentService
    {
        return new PaymentService(
            $this->createMock(CoopBankTransactionService::class),
            $accountService,
            $this->createMock(CoopBankTransferService::class),
            $this->createMock(WalletService::class),
            $this->createMock(PaymentLifecycleService::class),
            app(PaymentEngine::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );
    }

    public function test_successful_status_poll_finalizes_once_and_repeated_poll_is_local(): void
    {
        $memberId = $this->createMember();
        $payment = app(PaymentEngine::class)->createPendingPayment($memberId, 'mpesa', 'COOP-STK-STATUS-1', 'LOCAL-STATUS-1', 30000);
        $accountService = $this->createMock(CoopBankAccountService::class);
        $accountService->expects($this->once())->method('queryStkStatus')->with('COOP-STK-STATUS-1')->willReturn([
            'status' => 'success',
            'provider_status' => 'SUCCESS',
            'message' => 'Completed',
            'response' => ['Status' => 'SUCCESS'],
        ]);
        $service = $this->service($accountService);
        $identity = ['member_id' => $memberId, 'roles' => ['member']];

        $first = $service->getStatus($identity, $payment['payment_id']);
        $second = $service->getStatus($identity, $payment['payment_id']);

        $this->assertSame('confirmed', $first['status']);
        $this->assertSame('confirmed', $second['status']);
        $this->assertSame(1, DB::table('contribution_allocations')->where('payment_id', $payment['payment_id'])->count());
    }

    public function test_failed_status_poll_does_not_allocate(): void
    {
        $memberId = $this->createMember();
        $payment = app(PaymentEngine::class)->createPendingPayment($memberId, 'mpesa', 'COOP-STK-STATUS-2', 'LOCAL-STATUS-2', 30000);
        $accountService = $this->createMock(CoopBankAccountService::class);
        $accountService->expects($this->once())->method('queryStkStatus')->willReturn([
            'status' => 'failed',
            'provider_status' => 'FAILED',
            'message' => 'Declined',
            'response' => ['Status' => 'FAILED'],
        ]);

        $result = $this->service($accountService)->getStatus(['member_id' => $memberId, 'roles' => ['member']], $payment['payment_id']);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, DB::table('contribution_allocations')->where('payment_id', $payment['payment_id'])->count());
    }

    public function test_successful_penalty_status_uses_lifecycle_settlement_once(): void
    {
        $memberId = $this->createMember();
        $payment = app(PaymentEngine::class)->createPendingPayment(
            $memberId,
            'mpesa',
            'COOP-STK-PENALTY-1',
            'LOCAL-PENALTY-1',
            50000,
            null,
            'penalty',
            'penalty-1',
        );
        $accountService = $this->createMock(CoopBankAccountService::class);
        $accountService->expects($this->once())->method('queryStkStatus')->willReturn([
            'status' => 'success',
            'provider_status' => 'SUCCESS',
            'message' => 'Completed',
            'response' => ['Status' => 'SUCCESS'],
        ]);
        $lifecycle = $this->createMock(PaymentLifecycleService::class);
        $lifecycle->expects($this->once())->method('postPayment')->with(
            'penalty',
            $memberId,
            500.0,
            'COOP-STK-PENALTY-1',
            'penalty-1',
            'stk_status',
            $payment['payment_id'],
        );
        $service = new PaymentService(
            $this->createMock(CoopBankTransactionService::class),
            $accountService,
            $this->createMock(CoopBankTransferService::class),
            $this->createMock(WalletService::class),
            $lifecycle,
            app(PaymentEngine::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );

        $result = $service->getStatus(['member_id' => $memberId, 'roles' => ['member']], $payment['payment_id']);

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame(0, DB::table('contribution_allocations')->where('payment_id', $payment['payment_id'])->count());
    }

    public function test_confirmed_operational_payment_settles_wallet_once_on_status_poll(): void
    {
        $memberId = $this->createMember();
        $checkoutId = 'COOP-STK-OP-CHECKOUT-1';
        $providerReference = 'COOP-STK-OP-RECEIPT-1';

        DB::table('operational_payment_records')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 1000,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'source' => 'stk_push',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = app(PaymentEngine::class)->createPendingPayment(
            $memberId,
            'mpesa',
            $providerReference,
            $checkoutId,
            100000,
            null,
            'operational',
        );
        app(PaymentEngine::class)->updateProviderStatus(
            $payment['payment_id'],
            'success',
            'Provider confirmed',
            ['TransactionId' => $providerReference],
        );

        $service = new PaymentService(
            $this->createMock(CoopBankTransactionService::class),
            $this->createMock(CoopBankAccountService::class),
            $this->createMock(CoopBankTransferService::class),
            app(WalletService::class),
            app(PaymentLifecycleService::class),
            app(PaymentEngine::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );
        $identity = ['member_id' => $memberId, 'roles' => ['member']];

        $first = $service->getStatus($identity, $payment['payment_id']);
        $second = $service->getStatus($identity, $payment['payment_id']);

        $this->assertSame('confirmed', $first['status']);
        $this->assertSame('confirmed', $second['status']);
        $this->assertDatabaseHas('operational_payment_records', [
            'mpesa_transaction_id' => $checkoutId,
            'status' => 'verified',
            'payment_ref' => $providerReference,
        ]);
        $this->assertDatabaseHas('operational_wallet', [
            'total_balance' => 1000,
            'total_received' => 1000,
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('wallet_type', 'operational')
            ->where('direction', 'credit')
            ->where('reference_id', DB::table('operational_payment_records')->where('mpesa_transaction_id', $checkoutId)->value('id'))
            ->where('reference_table', 'operational_payment_records')
            ->count());
    }

    public function test_late_success_after_transfer_timeout_settles_general_donation_once(): void
    {
        $memberId = $this->createMember();
        $checkoutId = 'COOP-STK-DON-CHECKOUT-1';
        $providerReference = 'COOP-STK-DON-RECEIPT-1';
        $paymentRecordId = Uuid::v4();
        $transferId = Uuid::v4();

        DB::table('donation_payment_records')->insert([
            'id' => $paymentRecordId,
            'member_id' => $memberId,
            'campaign_id' => null,
            'amount' => 50,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = app(PaymentEngine::class)->createPendingPayment(
            $memberId,
            'mpesa',
            $providerReference,
            $checkoutId,
            5000,
            null,
            'donation',
        );
        DB::table('coop_transfer_requests')->insert([
            'id' => $transferId,
            'message_reference' => $providerReference,
            'transfer_type' => 'STK',
            'status' => 'timeout',
            'amount' => 50,
            'internal_reference' => "donation-{$memberId}-{$checkoutId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new PaymentService(
            $this->createMock(CoopBankTransactionService::class),
            $this->createMock(CoopBankAccountService::class),
            $this->createMock(CoopBankTransferService::class),
            app(WalletService::class),
            app(PaymentLifecycleService::class),
            app(PaymentEngine::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );

        $providerResult = ['status' => 'SUCCESS', 'message' => 'FULL SUCCESS'];
        $first = $service->processStkBankStatus($transferId, $providerResult);
        $second = $service->processStkBankStatus($transferId, $providerResult);

        $this->assertSame('successful', $first['status']);
        $this->assertSame('successful', $second['status']);
        $this->assertSame('confirmed', DB::table('payments')->where('id', $payment['payment_id'])->value('status'));
        $this->assertDatabaseHas('donation_payment_records', [
            'id' => $paymentRecordId,
            'status' => 'verified',
        ]);
        $this->assertDatabaseHas('coop_transfer_requests', [
            'id' => $transferId,
            'status' => 'successful',
        ]);
        $this->assertSame(50.0, (float) DB::table('donation_wallet')->value('total_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('wallet_type', 'donation')
            ->where('direction', 'credit')
            ->where('reference_id', $paymentRecordId)
            ->where('reference_table', 'donation_payment_records')
            ->count());
    }

    public function test_scheduled_reconciliation_recovers_stk_timeout_after_retry_limit(): void
    {
        $memberId = $this->createMember();
        $checkoutId = 'COOP-STK-DON-CHECKOUT-2';
        $providerReference = 'COOP-STK-DON-RECEIPT-2';
        $paymentRecordId = Uuid::v4();
        $transferId = Uuid::v4();

        DB::table('donation_payment_records')->insert([
            'id' => $paymentRecordId,
            'member_id' => $memberId,
            'campaign_id' => null,
            'amount' => 75,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payment = app(PaymentEngine::class)->createPendingPayment(
            $memberId,
            'mpesa',
            $providerReference,
            $checkoutId,
            7500,
            null,
            'donation',
        );
        DB::table('coop_transfer_requests')->insert([
            'id' => $transferId,
            'message_reference' => $providerReference,
            'transfer_type' => 'STK',
            'status' => 'timeout',
            'amount' => 75,
            'retry_count' => 8,
            'internal_reference' => "donation-{$memberId}-{$checkoutId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountService = $this->createMock(CoopBankAccountService::class);
        $accountService->expects($this->once())
            ->method('queryStkStatus')
            ->with($providerReference)
            ->willReturn(['status' => 'SUCCESS', 'message' => 'FULL SUCCESS']);
        $this->app->instance(CoopBankAccountService::class, $accountService);

        $this->artisan('coop:reconcile-transfers')->assertExitCode(0);

        $this->assertSame('confirmed', DB::table('payments')->where('id', $payment['payment_id'])->value('status'));
        $this->assertDatabaseHas('coop_transfer_requests', [
            'id' => $transferId,
            'status' => 'successful',
        ]);
        $this->assertSame(75.0, (float) DB::table('donation_wallet')->value('total_balance'));
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('wallet_type', 'donation')
            ->where('direction', 'credit')
            ->where('reference_id', $paymentRecordId)
            ->where('reference_table', 'donation_payment_records')
            ->count());
    }
}
