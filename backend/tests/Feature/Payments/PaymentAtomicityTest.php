<?php

namespace Tests\Feature\Payments;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentAtomicityTest extends TestCase
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

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $memberId,
            'name' => 'Atomicity Test Member',
            'phone' => '+2547' . substr($memberId, 0, 8),
            'member_id' => 'AT' . $memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    public function test_fund_drive_callback_is_atomic(): void
    {
        $memberId = $this->createMember();

        $campaignId = \App\Support\Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Atomic Test Drive',
            'description' => 'Atomic test',
            'amount' => 1000,
            'active' => 1,
            'goal_type' => 'fixed',
            'target_total' => 5000,
            'allow_partial' => 1,
            'total_collected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recordId = \App\Support\Uuid::v4();
        $checkoutId = 'ws_CO_ATOMIC_TEST';

        DB::table('donation_payment_records')->insert([
            'id' => $recordId,
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 50,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'atomic-merchant',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ATOMIC_RECPT'],
                        ],
                    ],
                ],
            ],
        ]);

        // All three must be updated atomically:
        // 1. Payment record verified
        $this->assertEquals('verified', DB::table('donation_payment_records')
            ->where('id', $recordId)->value('status'));

        // 2. Campaign total incremented
        $this->assertEquals(50, (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected'));

        // 3. Wallet transaction created
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'direction' => 'credit',
            'reference_id' => $recordId,
        ]);
    }

    public function test_all_three_payment_types_remain_separate(): void
    {
        $memberId = $this->createMember();

        // Monthly Contribution
        $contributionId = \App\Support\Uuid::v4();
        DB::table('contributions')->insert([
            'id' => $contributionId,
            'member_id' => $memberId,
            'amount' => 300,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
            'due_date' => now()->format('Y-m-d'),
            'status' => 'pending',
            'stk_checkout_request_id' => 'ws_CO_CONTRIB_001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Penalty
        $penaltyId = \App\Support\Uuid::v4();
        DB::table('penalty_payment_records')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_PENALTY_001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fund Drive
        $campaignId = \App\Support\Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Separate Flows Test',
            'description' => 'Test',
            'amount' => 1000,
            'active' => 1,
            'goal_type' => 'fixed',
            'target_total' => 5000,
            'allow_partial' => 1,
            'total_collected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $donationId = \App\Support\Uuid::v4();
        DB::table('donation_payment_records')->insert([
            'id' => $donationId,
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 100,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_DONATION_001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Process all three callbacks
        $this->postJson('/api/payments/stk-callback', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'sep-1',
                    'CheckoutRequestID' => 'ws_CO_CONTRIB_001',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SEP_CONTRIB'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'sep-2',
                    'CheckoutRequestID' => 'ws_CO_PENALTY_001',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SEP_PENALTY'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'sep-3',
                    'CheckoutRequestID' => 'ws_CO_DONATION_001',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SEP_DONATION'],
                        ],
                    ],
                ],
            ],
        ]);

        // 1. Monthly contribution is marked paid (no wallet credit)
        $this->assertEquals('paid', DB::table('contributions')
            ->where('id', $contributionId)->value('status'));

        // 2. Penalty wallet is credited (not donation wallet)
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'penalty',
            'mpesa_receipt' => 'SEP_PENALTY',
            'direction' => 'credit',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_type' => 'donation',
            'mpesa_receipt' => 'SEP_PENALTY',
        ]);

        // 3. Donation wallet is credited and campaign total incremented
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'mpesa_receipt' => 'SEP_DONATION',
            'direction' => 'credit',
        ]);
        $this->assertEquals(100, (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected'));

        // 4. No cross-contamination: contribution has no wallet, donation doesn't pay penalties
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_type' => 'donation',
            'mpesa_receipt' => 'SEP_CONTRIB',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', [
            'wallet_type' => 'penalty',
            'mpesa_receipt' => 'SEP_DONATION',
        ]);
    }

    public function test_fund_drive_callback_atomicity_rolls_back_on_failure(): void
    {
        $memberId = $this->createMember();

        $campaignId = \App\Support\Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Atomicity Failure Drive',
            'description' => 'Test',
            'amount' => 500,
            'active' => 1,
            'goal_type' => 'fixed',
            'target_total' => 5000,
            'allow_partial' => 1,
            'total_collected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recordId = \App\Support\Uuid::v4();
        $checkoutId = 'ws_CO_ATOMIC_FAIL';

        DB::table('donation_payment_records')->insert([
            'id' => $recordId,
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 50,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $initialCollected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'atomic-fail-merchant',
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 1,
                    'ResultDesc' => 'Insufficient balance',
                    'CallbackMetadata' => [
                        'Item' => [],
                    ],
                ],
            ],
        ]);

        $record = DB::table('donation_payment_records')->where('id', $recordId)->first();
        $collected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');

        $this->assertEquals('failed', $record->status, 'Failed callback marks payment as failed');
        $this->assertEquals($initialCollected, $collected, 'On failure, total_collected must remain unchanged (no increment)');

        $walletTxCount = DB::table('wallet_transactions')
            ->where('mpesa_receipt', 'ATOMIC_FAIL_RECPT')
            ->count();
        $this->assertEquals(0, $walletTxCount, 'No wallet transaction created on failed callback');

        $campaignContribution = DB::table('campaign_contributions')
            ->where('campaign_id', $campaignId)
            ->where('member_id', $memberId)
            ->first();
        $this->assertNull($campaignContribution, 'No campaign_contribution on failed callback');
    }
}
