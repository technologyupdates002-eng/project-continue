<?php

namespace Tests\Feature\Banking;

use App\Services\JwtService;
use App\Services\BankSyncService;
use App\Models\CoopTransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoopBankTest extends TestCase
{
    use RefreshDatabase;

    protected array $adminHeaders = [];
    protected array $memberHeaders = [];
    protected array $invalidHeaders = ['Authorization' => 'Bearer invalid'];

    protected function setUp(): void
    {
        parent::setUp();

        $adminToken = JwtService::encode(JwtService::buildPayload(
            '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            '254712345678@welfare.local',
            ['member', 'admin', 'super_admin']
        ));
        $this->adminHeaders = ['Authorization' => "Bearer {$adminToken}"];

        $memberToken = JwtService::encode(JwtService::buildPayload(
            'member-user-1',
            '254723456789@welfare.local',
            ['member']
        ));
        $this->memberHeaders = ['Authorization' => "Bearer {$memberToken}"];
    }

    public function test_bank_routes_require_auth(): void
    {
        $this->postJson('/api/banking/coop/transfers/b2c')->assertStatus(401);
        $this->getJson('/api/banking/coop/account/balance')->assertStatus(401);
    }

    public function test_callback_routes_are_public(): void
    {
        $response = $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'test-ref',
            'Status' => 'SUCCESS',
        ]);

        $response->assertStatus(200);
    }

    public function test_b2c_transfer_requires_staff_role(): void
    {
        $response = $this->postJson('/api/banking/coop/transfers/b2c', [
            'member_id' => 'member-1',
            'amount' => 100,
            'phone' => '254712345678',
        ], $this->memberHeaders);

        $response->assertStatus(403);
    }

    public function test_b2c_transfer_creates_transfer_record(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response([
                'MessageCode' => '0',
                'MessageDescription' => 'Success',
                'TransactionId' => 'TXN12345',
            ], 200),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        // Set up penalty wallet with sufficient balance
        DB::table('penalty_wallet')->insert([
            'id' => \App\Support\Uuid::v4(),
            'total_balance' => 1000,
            'total_received' => 1000,
            'total_withdrawn' => 0,
        ]);

        $response = $this->postJson('/api/banking/coop/transfers/b2c', [
            'member_id' => 'member-1',
            'amount' => 100,
            'phone_number' => '254712345678',
            'withdrawal_id' => 'test-withdrawal-1',
            'wallet_type' => 'penalty',
            'narration' => 'Test withdrawal',
        ], $this->adminHeaders);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('coop_transfer_requests', [
            'transfer_type' => 'B2C_MPESA',
            'internal_reference' => 'test-withdrawal-1',
            'status' => 'processing',
        ]);
    }

    public function test_transfer_status_endpoint(): void
    {
        CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'STK-TEST-001',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'successful',
            'destination_account' => '254712345678',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'withdrawal-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/banking/coop/transfers/STK-TEST-001/status', $this->adminHeaders);

        $response->assertStatus(200)
            ->assertJsonPath('data.message_reference', 'STK-TEST-001');
    }

    public function test_account_balance_requires_staff(): void
    {
        $response = $this->getJson('/api/banking/coop/account/balance', $this->memberHeaders);

        $response->assertStatus(403);
    }

    public function test_account_balance_returns_mock_in_simulation_mode(): void
    {
        $response = $this->getJson('/api/banking/coop/account/balance', $this->adminHeaders);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_callback_invalid_reference_returns_200(): void
    {
        $response = $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'NONEXISTENT-REF',
            'Status' => 'SUCCESS',
            'TransactionId' => 'TXN999',
            'Amount' => 100,
        ]);

        $response->assertStatus(200);
    }

    public function test_callback_success_updates_transfer_status(): void
    {
        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'TEST-CALLBACK-001',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'destination_account' => '254712345678',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'withdrawal-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'TEST-CALLBACK-001',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'TransactionId' => 'TXN-CALLBACK-001',
            'Amount' => 100,
            'Currency' => 'KES',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('coop_transfer_requests', [
            'id' => $transfer->id,
            'status' => 'successful',
        ]);
    }

    public function test_callback_idempotent(): void
    {
        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'TEST-DUP-001',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'destination_account' => '254712345678',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'withdrawal-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = [
            'MessageReference' => 'TEST-DUP-001',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'TransactionId' => 'TXN-DUP-001',
            'Amount' => 100,
        ];

        $this->postJson('/api/banking/coop/callback/transfer', $callback);
        $this->postJson('/api/banking/coop/callback/transfer', $callback);

        $this->assertEquals(1, DB::table('coop_transfer_responses')
            ->where('transaction_id', 'TXN-DUP-001')
            ->count());
    }

    public function test_message_reference_truncated_to_28_chars(): void
    {
        $ref = \App\Support\Uuid::v4();
        $type = 'B2C';
        $timestamp = gmdate('ymdHis');
        $shortId = substr(substr($ref, 0, 8), -4);
        $random = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $generated = substr("{$type}{$timestamp}{$shortId}{$random}", 0, 28);

        $this->assertLessThanOrEqual(28, strlen($generated));
    }

    public function test_concurrent_b2c_withdrawals_are_blocked_by_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $wallet = DB::table('donation_wallet')->first();
        $this->assertGreaterThanOrEqual(100000, (float) $wallet->total_balance);

        $walletService->reserveFunds(
            'donation',
            'withdrawal-1',
            80000,
            'donation_withdrawals',
            'First reservation'
        );

        $this->expectException(\App\Exceptions\HttpException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        $walletService->reserveFunds(
            'donation',
            'withdrawal-2',
            80000,
            'donation_withdrawals',
            'Second reservation should fail'
        );
    }

    public function test_two_smaller_withdrawals_can_both_reserve(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $result1 = $walletService->reserveFunds(
            'donation',
            'withdrawal-1',
            50000,
            'donation_withdrawals',
            'First reservation'
        );
        $this->assertTrue($result1['success']);

        $result2 = $walletService->reserveFunds(
            'donation',
            'withdrawal-2',
            50000,
            'donation_withdrawals',
            'Second reservation'
        );
        $this->assertTrue($result2['success']);

        $this->assertEquals(0, (float) $result2['available_balance']);
    }

    public function test_failed_transfer_releases_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $walletService->reserveFunds(
            'donation',
            'failed-withdrawal',
            80000,
            'donation_withdrawals',
            'Reservation for failed transfer'
        );

        $released = $walletService->releaseReservedFunds(
            'donation',
            'failed-withdrawal',
            80000,
            'donation_withdrawals',
            'Transfer failed, releasing reservation'
        );

        $this->assertTrue($released['released']);
        $this->assertEquals(100000, (float) $released['available_balance']);
    }

    public function test_successful_transfer_settles_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $walletService->reserveFunds(
            'donation',
            'success-withdrawal',
            80000,
            'donation_withdrawals',
            'Reservation for successful transfer'
        );

        $settled = $walletService->settleReservedFunds(
            'donation',
            'success-withdrawal',
            80000,
            'donation_withdrawals',
            'Transfer completed, settling reservation'
        );

        $this->assertTrue($settled['settled']);
        $this->assertEquals(20000, (float) $settled['total_balance']);
        $this->assertEquals(0, (float) $settled['reserved_balance']);
        $this->assertEquals(20000, (float) $settled['available_balance']);
    }

    public function test_duplicate_reservation_prevented(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $walletService->reserveFunds(
            'donation',
            'dup-ref',
            50000,
            'donation_withdrawals',
            'First reservation'
        );

        $this->expectException(\App\Exceptions\HttpException::class);
        $this->expectExceptionMessage('Funds already reserved');

        $walletService->reserveFunds(
            'donation',
            'dup-ref',
            50000,
            'donation_withdrawals',
            'Duplicate reservation'
        );
    }

    public function test_stk_callback_does_not_credit_wallet_on_http_200(): void
    {
        $walletService = app(\App\Services\WalletService::class);

        $initialBalance = (float) DB::table('donation_wallet')->value('total_balance') ?? 0;

        $stkPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-merchant',
                    'CheckoutRequestID' => 'ws_NOT_REAL_CALLBACK',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'TESTRECEIPT'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/banking/coop/callback/stk', $stkPayload);

        $finalBalance = (float) DB::table('donation_wallet')->value('total_balance') ?? 0;

        $this->assertEquals($initialBalance, $finalBalance, 'Wallet balance must not change for callback with no matching payment');
    }

    public function test_b2c_callback_failure_releases_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $walletService->reserveFunds(
            'donation',
            'failed-transfer-ref',
            50000,
            'donation_withdrawals',
            'Reservation for failed B2C transfer'
        );

        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'transfer-msg-ref',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'submitted',
            'destination_account' => '254712345678',
            'amount' => 50000,
            'currency' => 'KES',
            'narration_internal' => 'Test withdrawal',
            'internal_reference' => 'failed-transfer-ref',
            'internal_table' => 'donation_withdrawals',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'transfer-msg-ref',
            'Status' => 'FAILED',
            'StatusCode' => '1',
            'StatusDescription' => 'Transfer failed',
            'TransactionId' => 'TXN-FAILED-001',
        ]);

        $wallet = DB::table('donation_wallet')->first();
        $this->assertEquals(0, (float) $wallet->reserved_balance);
    }

    public function test_admin_can_list_all_pending_statement_unmatched_transactions_by_page(): void
    {
        $transactionId = \App\Support\Uuid::v4();
        DB::table('bank_unmatched_transactions')->insert([
            'id' => $transactionId,
            'bank_reference' => 'STATEMENT-UNMATCHED-1',
            'transaction_date' => '2026-09-01',
            'amount' => 1200,
            'credit_amount' => 1200,
            'debit_amount' => 0,
            'payer_name' => 'Unknown payer',
            'phone' => '0712345678',
            'narration' => 'Statement transfer',
            'reason' => 'unknown_member',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/banking/coop/unmatched-transactions?page=1&per_page=50', $this->adminHeaders)
            ->assertOk()
            ->assertJsonPath('data.0.id', $transactionId)
            ->assertJsonPath('data.0.bank_reference', 'STATEMENT-UNMATCHED-1')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_member_cannot_list_statement_unmatched_transactions(): void
    {
        $this->getJson('/api/banking/coop/unmatched-transactions', $this->memberHeaders)
            ->assertForbidden();
    }

    public function test_invalid_member_id_does_not_resolve_statement_unmatched_transaction(): void
    {
        $transactionId = \App\Support\Uuid::v4();
        DB::table('bank_unmatched_transactions')->insert([
            'id' => $transactionId,
            'bank_reference' => 'STATEMENT-UNMATCHED-2',
            'amount' => 1200,
            'reason' => 'unknown_member',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(
            "/api/banking/coop/unmatched-transactions/{$transactionId}/reconcile",
            ['member_id' => 'not-a-member-id'],
            $this->adminHeaders
        )->assertUnprocessable();

        $this->assertDatabaseHas('bank_unmatched_transactions', [
            'id' => $transactionId,
            'status' => 'pending',
            'resolved_at' => null,
        ]);
    }

    public function test_admin_can_reconcile_statement_transaction_by_member_id(): void
    {
        $memberId = \App\Support\Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Reconciliation Member',
            'phone' => '254700000001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transactionId = \App\Support\Uuid::v4();
        $bankTransactionId = \App\Support\Uuid::v4();
        DB::table('bank_unmatched_transactions')->insert([
            'id' => $transactionId,
            'bank_reference' => 'STATEMENT-UNMATCHED-3',
            'transaction_date' => '2026-09-01',
            'amount' => 1200,
            'credit_amount' => 1200,
            'debit_amount' => 0,
            'reason' => 'unknown_member',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('bank_transactions')->insert([
            'id' => $bankTransactionId,
            'phone' => '',
            'amount' => 1200,
            'transaction_date' => '2026-09-01',
            'month' => 9,
            'year' => 2026,
            'transaction_reference' => 'STATEMENT-UNMATCHED-3',
            'credit_amount' => 1200,
            'debit_amount' => 0,
            'reconciliation_status' => 'unmatched',
            'created_at' => now(),
        ]);

        $bankSyncService = \Mockery::mock(BankSyncService::class);
        $bankSyncService->shouldReceive('reconcileManually')
            ->once()
            ->with(
                $bankTransactionId,
                ['member_id' => $memberId, 'category' => 'monthly_contribution'],
                '05ade1a2-2aba-425c-b298-3b27f1d2c13b'
            )
            ->andReturn(['success' => true, 'transaction_id' => $bankTransactionId]);
        $this->app->instance(BankSyncService::class, $bankSyncService);

        $this->postJson(
            "/api/banking/coop/unmatched-transactions/{$transactionId}/reconcile",
            ['member_id' => $memberId],
            $this->adminHeaders
        )
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('bank_unmatched_transactions', [
            'id' => $transactionId,
            'status' => 'matched',
            'resolved_by' => '05ade1a2-2aba-425c-b298-3b27f1d2c13b',
            'resolution_notes' => 'Matched to member ' . $memberId,
        ]);
    }
}
