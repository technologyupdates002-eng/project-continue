<?php

namespace Tests\Feature\Payments;

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

class OperationalPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('members')->updateOrInsert(
            ['id' => 'system'],
            [
                'user_id' => 'system',
                'name' => 'System',
                'phone' => '',
                'member_id' => 'SYS',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function createPendingOperationalPayment(): array
    {
        $checkoutId = 'ws_OPT_'.bin2hex(random_bytes(12));
        $providerReference = 'OPT_'.bin2hex(random_bytes(8));
        $transferId = Uuid::v4();

        DB::table('operational_payment_records')->insert([
            'id' => Uuid::v4(),
            'member_id' => 'system',
            'amount' => 1000,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'source' => 'stk_push',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payment = app(PaymentEngine::class)->createPendingPayment(
            'system',
            'mpesa',
            $providerReference,
            $checkoutId,
            100000,
            null,
            'operational',
        );

        DB::table('coop_transfer_requests')->insert([
            'id' => $transferId,
            'message_reference' => $providerReference,
            'transfer_type' => 'STK',
            'status' => 'timeout',
            'amount' => 1000,
            'internal_reference' => "operational-system-{$checkoutId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'checkout_id' => $checkoutId,
            'provider_reference' => $providerReference,
            'transfer_id' => $transferId,
            'payment_id' => $payment['payment_id'],
        ];
    }

    private function paymentService(): PaymentService
    {
        return new PaymentService(
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
    }

    public function test_confirmed_operational_stk_settles_wallet(): void
    {
        $payment = $this->createPendingOperationalPayment();

        $result = $this->paymentService()->processStkBankStatus(
            $payment['transfer_id'],
            ['status' => 'SUCCESS', 'message' => 'FULL SUCCESS'],
        );

        $this->assertSame('successful', $result['status']);
        $this->assertSame('confirmed', DB::table('payments')->where('id', $payment['payment_id'])->value('status'));
        $this->assertDatabaseHas('operational_payment_records', [
            'mpesa_transaction_id' => $payment['checkout_id'],
            'status' => 'verified',
            'payment_ref' => $payment['provider_reference'],
        ]);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'operational',
            'direction' => 'credit',
            'mpesa_receipt' => $payment['provider_reference'],
            'reference_table' => 'operational_payment_records',
            'gross_amount' => 1000,
        ]);
        $this->assertSame(1000.0, (float) DB::table('operational_wallet')->value('total_balance'));
    }

    public function test_failed_operational_stk_marks_payment_record_failed_without_credit(): void
    {
        $payment = $this->createPendingOperationalPayment();

        $result = $this->paymentService()->processStkBankStatus(
            $payment['transfer_id'],
            ['status' => 'FAILED', 'message' => 'Insufficient funds'],
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', DB::table('payments')->where('id', $payment['payment_id'])->value('status'));
        $this->assertDatabaseHas('operational_payment_records', [
            'mpesa_transaction_id' => $payment['checkout_id'],
            'status' => 'failed',
        ]);
        $this->assertSame(0, DB::table('wallet_transactions')
            ->where('wallet_type', 'operational')
            ->where('direction', 'credit')
            ->count());
        $this->assertSame(0.0, (float) (DB::table('operational_wallet')->value('total_balance') ?? 0));
    }

    public function test_repeated_operational_success_does_not_duplicate_credit(): void
    {
        $payment = $this->createPendingOperationalPayment();
        $service = $this->paymentService();
        $providerStatus = ['status' => 'SUCCESS', 'message' => 'FULL SUCCESS'];

        $service->processStkBankStatus($payment['transfer_id'], $providerStatus);
        $service->processStkBankStatus($payment['transfer_id'], $providerStatus);

        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('wallet_type', 'operational')
            ->where('direction', 'credit')
            ->where('reference_id', DB::table('operational_payment_records')
                ->where('mpesa_transaction_id', $payment['checkout_id'])
                ->value('id'))
            ->where('reference_table', 'operational_payment_records')
            ->count());
        $this->assertSame(1000.0, (float) DB::table('operational_wallet')->value('total_balance'));
    }
}
