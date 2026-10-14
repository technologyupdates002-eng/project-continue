<?php

namespace Tests\Feature\Payments;

use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Services\ContributionObligationService;
use App\Services\JwtService;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MonthlyContributionPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected array $adminHeaders = [];

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

    private function createMember(): string
    {
        $memberId = \App\Support\Uuid::v4();
        $phone = '+2547' . substr($memberId, 0, 8);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $memberId,
            'name' => 'Contribution Test Member',
            'phone' => $phone,
            'member_id' => 'MC' . $memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    public function test_monthly_contribution_stk_creates_pending_record(): void
    {
        $memberId = $this->createMember();

        $response = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'accountReference' => 'CONTRIBUTION-' . $memberId,
            'transactionDesc' => 'Monthly contribution',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'amount' => 300,
            'status' => 'pending',
        ]);
    }

    public function test_monthly_contribution_callback_marks_paid(): void
    {
        $memberId = $this->createMember();

        $pushResponse = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $checkoutId = $pushResponse->json('checkoutRequestId');

        $this->postJson('/api/payments/stk-callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'mc-merchant-1',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MC_RECEIPT1'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'status' => 'paid',
            'payment_ref' => 'MC_RECEIPT1',
        ]);
    }

    public function test_monthly_contribution_does_not_credit_wallet(): void
    {
        $memberId = $this->createMember();

        $pushResponse = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $checkoutId = $pushResponse->json('checkoutRequestId');

        $this->postJson('/api/payments/stk-callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'mc-no-wallet',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MC_NOWALLET'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'mpesa_receipt' => 'MC_NOWALLET',
            'wallet_type' => 'donation',
            'direction' => 'credit',
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'mpesa_receipt' => 'MC_NOWALLET',
            'wallet_type' => 'penalty',
            'direction' => 'credit',
        ]);

        $this->assertDatabaseMissing('wallet_transactions', [
            'mpesa_receipt' => 'MC_NOWALLET',
            'wallet_type' => 'operational',
            'direction' => 'credit',
        ]);
    }

    public function test_monthly_contribution_requires_bank_status_success_before_marking_paid(): void
    {
        $memberId = $this->createMember();

        $checkoutId = 'ws_CO_STATUS_CHECK_' . uniqid();
        DB::table('contributions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => $memberId,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'due_date' => now()->format('Y-m-d'),
            'amount' => 300,
            'status' => 'pending',
            'stk_checkout_request_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountService = Mockery::mock(CoopBankAccountService::class);
        $accountService->shouldReceive('queryStkStatus')
            ->once()
            ->with('BANK-STATUS-TEST')
            ->andReturn(['status' => 'PENDING']);

        $paymentService = new PaymentService(
            app(CoopBankTransactionService::class),
            $accountService,
            app(CoopBankTransferService::class),
            app(WalletService::class),
            app(PaymentLifecycleService::class),
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class)
        );

        $paymentService->processCallback([
            'CheckoutRequestID' => $checkoutId,
            'MerchantRequestID' => 'BANK-STATUS-TEST',
            'ResultCode' => 0,
            'ResultDesc' => 'Success',
            'CallbackMetadata' => [
                'Item' => [
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'MC_PENDING_API'],
                ],
            ],
        ]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'status' => 'pending',
            'payment_ref' => null,
        ]);
    }

    public function test_monthly_contribution_duplicate_callback_idempotent(): void
    {
        $memberId = $this->createMember();

        $pushResponse = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $checkoutId = $pushResponse->json('checkoutRequestId');

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'mc-dup-1',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'MC_DUP1'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/payments/stk-callback', $callback);
        $this->postJson('/api/payments/stk-callback', $callback);

        $paidCount = DB::table('contributions')
            ->where('stk_checkout_request_id', $checkoutId)
            ->where('status', 'paid')
            ->count();

        $this->assertEquals(1, $paidCount, 'Duplicate callback does not create duplicate paid records');
    }

    public function test_stk_reuses_zero_amount_pending_contribution_and_updates_amount(): void
    {
        $memberId = $this->createMember();

        DB::table('contributions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => $memberId,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'due_date' => now()->format('Y-m-d'),
            'amount' => 0,
            'status' => 'pending',
            'stk_checkout_request_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'amount' => 300,
            'status' => 'pending',
        ]);
    }

    public function test_stk_reuses_zero_amount_partial_contribution_and_updates_amount(): void
    {
        $memberId = $this->createMember();

        DB::table('contributions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => $memberId,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'due_date' => now()->format('Y-m-d'),
            'amount' => 0,
            'status' => 'partial',
            'stk_checkout_request_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'amount' => 300,
            'status' => 'partial',
        ]);
    }

    public function test_stk_reuses_nonzero_partial_contribution_and_preserves_amount(): void
    {
        $memberId = $this->createMember();

        DB::table('contributions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => $memberId,
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'due_date' => now()->format('Y-m-d'),
            'amount' => 150,
            'status' => 'partial',
            'stk_checkout_request_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254712345678',
            'paymentType' => 'monthly_contribution',
        ], $this->adminHeaders);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('contributions', [
            'member_id' => $memberId,
            'amount' => 150,
            'status' => 'partial',
        ]);
    }
}
