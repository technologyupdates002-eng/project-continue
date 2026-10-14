<?php

namespace Tests\Feature\Payments;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FundDriveLipaMdogoMdogoTest extends TestCase
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
            'name' => 'Fund Drive Test Member',
            'phone' => $phone,
            'member_id' => 'FD' . $memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function createCampaign(float $amount = 100000): string
    {
        $campaignId = \App\Support\Uuid::v4();

        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Lipa Dogna Mdogo Test Drive',
            'description' => 'Test campaign for partial payments',
            'amount' => $amount,
            'active' => 1,
            'goal_type' => 'fixed',
            'target_total' => 500000,
            'allow_partial' => 1,
            'total_collected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $campaignId;
    }

    public function test_fund_drive_partial_payment_updates_all_trackers(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(100000); // per-member target KES 100,000

        $initialCollected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected');
        $this->assertEquals(0, $initialCollected);

        // STK push creates pending donation record
        $pushResponse = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 10,
            'phone' => '+254712345678',
            'accountReference' => 'DONATION-' . $campaignId,
            'transactionDesc' => 'Donation payment',
            'paymentType' => 'donation',
            'campaignId' => $campaignId,
        ], $this->adminHeaders);

        $pushResponse->assertStatus(200)->assertJson(['success' => true]);
        $checkoutId = $pushResponse->json('checkoutRequestId');

        // Verify pending record exists
        $pendingRecord = DB::table('donation_payment_records')
            ->where('mpesa_transaction_id', $checkoutId)
            ->first();
        $this->assertNotNull($pendingRecord);
        $this->assertEquals('pending', $pendingRecord->status);
        $this->assertEquals(10, (float) $pendingRecord->amount);

        // STK callback arrives (success)
        $mpesaReceipt = 'RECEIPT_KES10_FD_' . time();
        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-merchant-' . $checkoutId,
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 10],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $mpesaReceipt],
                            ['Name' => 'TransactionDate', 'Value' => (int) now()->format('YmdHis')],
                        ],
                    ],
                ],
            ],
        ]);

        // 1. Payment record must be verified
        $verifiedRecord = DB::table('donation_payment_records')
            ->where('mpesa_transaction_id', $checkoutId)
            ->first();
        $this->assertEquals('verified', $verifiedRecord->status);
        $this->assertEquals($mpesaReceipt, $verifiedRecord->payment_ref);
        $this->assertNotNull($verifiedRecord->verified_at);

        // 2. Donation wallet must be credited (exactly once)
        $walletTxCount = DB::table('wallet_transactions')
            ->where('mpesa_receipt', $mpesaReceipt)
            ->where('wallet_type', 'donation')
            ->where('direction', 'credit')
            ->count();
        $this->assertEquals(1, $walletTxCount, 'Wallet credited exactly once');

        // 3. Fund Drive total_collected must be incremented
        $collectedAfter = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');
        $this->assertEquals(10, $collectedAfter, 'Fund Drive total_collected incremented by 10');

        // 4. Duplicate callback must NOT double-credit
        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-merchant-' . $checkoutId,
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 10],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $mpesaReceipt],
                        ],
                    ],
                ],
            ],
        ]);

        $collectedAfterDup = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');
        $this->assertEquals(10, $collectedAfterDup, 'Duplicate callback does not double-increment');

        $walletTxCountAfterDup = DB::table('wallet_transactions')
            ->where('mpesa_receipt', $mpesaReceipt)
            ->where('wallet_type', 'donation')
            ->where('direction', 'credit')
            ->count();
        $this->assertEquals(1, $walletTxCountAfterDup, 'Duplicate callback does not double-credit wallet');
    }

    public function test_fund_drive_progress_reflects_verified_payments_only(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(100);

        // Create a verified payment (KES 10)
        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 10,
            'status' => 'verified',
            'mpesa_transaction_id' => 'ws_CO_VERIFIED1',
            'payment_ref' => 'RCVD_VERIFIED1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a pending payment (KES 20) — should NOT count
        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 20,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_PENDING1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a failed payment (KES 5) — should NOT count
        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 5,
            'status' => 'failed',
            'mpesa_transaction_id' => 'ws_CO_FAILED1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Query only verified records (simulating frontend loadProgress after fix)
        $records = DB::table('donation_payment_records')
            ->where('member_id', $memberId)
            ->where('campaign_id', $campaignId)
            ->get();

        $paidSoFar = $records
            ->filter(fn($r) => $r->status === 'verified' || $r->status === 'paid')
            ->sum('amount');

        $this->assertEquals(10, (float) $paidSoFar, 'Only verified payments count toward progress');
    }

    public function test_fund_drive_callback_updates_total_collected(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(500);

        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 100,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_CAMPTEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'camp-test-merchant',
                    'CheckoutRequestID' => 'ws_CO_CAMPTEST',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT_CAMPTEST'],
                        ],
                    ],
                ],
            ],
        ]);

        $collected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');

        $this->assertEquals(100, $collected, 'Campaign total_collected updated on callback');
    }

    public function test_failed_callback_does_not_update_total_collected(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(500);

        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 10,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_FAILFD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'fail-merchant',
                    'CheckoutRequestID' => 'ws_CO_FAILFD',
                    'ResultCode' => 1,
                    'ResultDesc' => 'Insufficient balance',
                    'CallbackMetadata' => [
                        'Item' => [],
                    ],
                ],
            ],
        ]);

        $collected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');
        $this->assertEquals(0, $collected, 'Failed callback does not increment total_collected');

        $record = DB::table('donation_payment_records')
            ->where('mpesa_transaction_id', 'ws_CO_FAILFD')
            ->first();
        $this->assertEquals('failed', $record->status);
    }

    public function test_fund_drive_callback_updates_per_member_campaign_contributions(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(1000); // per-member target KES 1,000

        $pushResponse = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 100,
            'phone' => '+254712345678',
            'accountReference' => 'DONATION-' . $campaignId,
            'transactionDesc' => 'Donation payment',
            'paymentType' => 'donation',
            'campaignId' => $campaignId,
        ], $this->adminHeaders);

        $pushResponse->assertStatus(200)->assertJson(['success' => true]);
        $checkoutId = $pushResponse->json('checkoutRequestId');

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'cc-merchant-' . $checkoutId,
                    'CheckoutRequestID' => $checkoutId,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 100],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT_CC_' . time()],
                        ],
                    ],
                ],
            ],
        ]);

        $contribution = DB::table('campaign_contributions')
            ->where('campaign_id', $campaignId)
            ->where('member_id', $memberId)
            ->first();

        $this->assertNotNull($contribution, 'campaign_contributions record created');
        $this->assertEquals(100, (float) $contribution->total_contributed, 'Per-member contribution total matches payment amount');
        $this->assertEquals(1000, (float) $contribution->target_amount, 'Target amount is campaign per-member target');
        $this->assertEquals(900, (float) $contribution->remaining, 'Remaining is target minus contributed');
        $this->assertEquals('active', $contribution->status, 'Status is active when target not yet reached');
        $this->assertNotNull($contribution->last_payment_at, 'last_payment_at is set');
    }

    public function test_fund_drive_multiple_partial_payments_accumulate_per_member(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(500); // per-member target KES 500

        for ($i = 1; $i <= 3; $i++) {
            $pushResponse = $this->postJson('/api/payments/stk-push', [
                'member_id' => $memberId,
                'amount' => 100,
                'phone' => '+254712345678',
                'accountReference' => 'DONATION-' . $campaignId,
                'transactionDesc' => 'Donation payment',
                'paymentType' => 'donation',
                'campaignId' => $campaignId,
            ], $this->adminHeaders);

            $checkoutId = $pushResponse->json('checkoutRequestId');

            $this->postJson('/api/banking/coop/callback/stk', [
                'Body' => [
                    'stkCallback' => [
                        'MerchantRequestID' => 'multi-merchant-' . $i,
                        'CheckoutRequestID' => $checkoutId,
                        'ResultCode' => 0,
                        'ResultDesc' => 'Success',
                        'CallbackMetadata' => [
                            'Item' => [
                                ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT_MULTI_' . $i],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        $contribution = DB::table('campaign_contributions')
            ->where('campaign_id', $campaignId)
            ->where('member_id', $memberId)
            ->first();

        $this->assertNotNull($contribution);
        $this->assertEquals(300, (float) $contribution->total_contributed, 'Three payments of KES 100 accumulate to 300');
        $this->assertEquals(200, (float) $contribution->remaining, 'Remaining is 500 - 300 = 200');
        $this->assertEquals('active', $contribution->status, 'Still active since target not reached');

        $totalCollected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');
        $this->assertEquals(300, $totalCollected, 'Campaign total_collected accumulated');
    }

    public function test_fund_drive_per_member_tracking_isolated_among_members(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(1000);

        $memberId2 = \App\Support\Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId2,
            'user_id' => $memberId2,
            'name' => 'Fund Drive Test Member 2',
            'phone' => '+254712345679',
            'member_id' => 'FD2' . $memberId2,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $push1 = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 500,
            'phone' => '+254712345678',
            'accountReference' => 'DONATION-' . $campaignId,
            'transactionDesc' => 'Donation payment',
            'paymentType' => 'donation',
            'campaignId' => $campaignId,
        ], $this->adminHeaders);

        $checkout1 = $push1->json('checkoutRequestId');
        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'iso-1',
                    'CheckoutRequestID' => $checkout1,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT_ISO_1'],
                        ],
                    ],
                ],
            ],
        ]);

        $push2 = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId2,
            'amount' => 300,
            'phone' => '+254712345679',
            'accountReference' => 'DONATION-' . $campaignId,
            'transactionDesc' => 'Donation payment',
            'paymentType' => 'donation',
            'campaignId' => $campaignId,
        ], $this->adminHeaders);

        $checkout2 = $push2->json('checkoutRequestId');
        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'iso-2',
                    'CheckoutRequestID' => $checkout2,
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT_ISO_2'],
                        ],
                    ],
                ],
            ],
        ]);

        $cc1 = DB::table('campaign_contributions')
            ->where('campaign_id', $campaignId)
            ->where('member_id', $memberId)
            ->first();

        $cc2 = DB::table('campaign_contributions')
            ->where('campaign_id', $campaignId)
            ->where('member_id', $memberId2)
            ->first();

        $this->assertNotNull($cc1);
        $this->assertNotNull($cc2);
        $this->assertEquals(500, (float) $cc1->total_contributed, 'Member 1 contributed 500');
        $this->assertEquals(500, (float) $cc1->remaining, 'Member 1 remaining is 500');
        $this->assertEquals('active', $cc1->status, 'Member 1 still active (500 < 1000 target)');

        $this->assertEquals(300, (float) $cc2->total_contributed, 'Member 2 contributed 300');
        $this->assertEquals(700, (float) $cc2->remaining, 'Member 2 remaining is 700');
        $this->assertEquals('active', $cc2->status, 'Member 2 still active (300 < 1000 target)');

        $totalCollected = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected');
        $this->assertEquals(800, $totalCollected, 'Campaign total_collected is 500 + 300 = 800');
    }
}
