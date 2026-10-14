<?php

namespace Tests\Feature\Banking;

use App\Models\CoopTransferRequest;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoopBankFailureTest extends TestCase
{
    use RefreshDatabase;

    protected array $adminHeaders = [];
    protected array $memberHeaders = [];

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

    public function test_token_request_failure_401(): void
    {
        Http::fake([
            '*/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'bad_key');
        config()->set('coop_bank.consumer_secret', 'bad_secret');

        $this->expectException(\App\Exceptions\CoopAuthenticationException::class);

        app(\App\Services\CoopBank\CoopBankClient::class)->getAccessToken();
    }

    public function test_token_request_failure_403(): void
    {
        Http::fake([
            '*/token' => Http::response(['error' => 'access_denied'], 403),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $this->expectException(\App\Exceptions\CoopAuthenticationException::class);

        app(\App\Services\CoopBank\CoopBankClient::class)->getAccessToken();
    }

    public function test_token_malformed_response(): void
    {
        Http::fake([
            '*/token' => Http::response('not json', 200),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $this->expectException(\ErrorException::class);

        app(\App\Services\CoopBank\CoopBankClient::class)->getAccessToken();
    }

    public function test_bank_api_timeout(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $this->expectException(\App\Exceptions\CoopBankException::class);

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', []);
    }

    public function test_bank_api_400_error(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response([
                'MessageCode' => '-2',
                'MessageDescription' => 'Invalid parameter',
            ], 400),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $transferService = app(\App\Services\CoopBank\CoopBankTransferService::class);

        $this->expectException(\App\Exceptions\CoopTransferException::class);

        try {
            $transferService->sendToMpesa(
                'withdrawal-1',
                'penalty_withdrawals',
                ['wallet' => 'test'],
                100,
                '254712345678',
                'Test'
            );
        } catch (\App\Exceptions\CoopTransferException $e) {
            $this->assertEquals('bank_error', $e->getErrorType());
            throw $e;
        }
    }

    public function test_bank_api_404_error(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response(['Message' => 'Not found'], 404),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $result = $client->request('POST', '/FundsTransfer/NonExistent/1.0.0', []);

        $this->assertEquals(404, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_bank_api_429_rate_limit(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response(['Message' => 'Rate limited'], 429),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $result = $client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', []);

        $this->assertEquals(429, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_bank_api_500_error(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response(['Message' => 'Internal error'], 500),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $result = $client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', []);

        $this->assertEquals(500, $result['status']);
        $this->assertFalse($result['success']);
    }

    public function test_bank_api_malformed_json_response(): void
    {
        Http::fake([
            '*/token' => Http::response(['access_token' => 'test', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::response('not json at all', 200),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $result = $client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', []);

        $this->assertEquals(200, $result['status']);
        $this->assertIsArray($result['body']);
    }

    public function test_stk_callback_failed_result_code_credits_no_wallet(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => null,
            'amount' => 100000,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_FAILTEST',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-123',
                    'CheckoutRequestID' => 'ws_CO_FAILTEST',
                    'ResultCode' => 1,
                    'ResultDesc' => 'Insufficient funds',
                    'CallbackMetadata' => ['Item' => []],
                ],
            ],
        ]);

        $this->assertDatabaseHas('donation_payment_records', [
            'mpesa_transaction_id' => 'ws_CO_FAILTEST',
            'status' => 'failed',
        ]);

        $wallet = DB::table('donation_wallet')->first();
        $this->assertEquals(100000, (float) $wallet->total_balance);
    }

    public function test_callback_unknown_reference_returns_200(): void
    {
        $response = $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'UNKNOWN-REF-12345',
            'Status' => 'SUCCESS',
            'TransactionId' => 'TXN-UNKNOWN',
        ]);

        $response->assertStatus(200);
    }

    public function test_callback_missing_message_reference(): void
    {
        $response = $this->postJson('/api/banking/coop/callback/transfer', [
            'Status' => 'SUCCESS',
            'TransactionId' => 'TXN-1',
        ]);

        $response->assertStatus(200);
    }

    public function test_stk_amount_validation(): void
    {
        $stkService = app(\App\Services\CoopBank\CoopBankTransactionService::class);

        $this->expectException(\App\Exceptions\CoopBankException::class);
        $this->expectExceptionMessage('Amount must be greater than 0');

        $stkService->initiateStkPush([
            'phone' => '254712345678',
            'amount' => 0,
            'narration' => 'Test',
            'internal_reference' => 'test-1',
        ]);
    }

    public function test_stk_invalid_phone_validation(): void
    {
        $stkService = app(\App\Services\CoopBank\CoopBankTransactionService::class);

        $this->expectException(\App\Exceptions\CoopBankException::class);
        $this->expectExceptionMessage('Invalid phone number format');

        $stkService->initiateStkPush([
            'phone' => '12345',
            'amount' => 100,
            'narration' => 'Test',
            'internal_reference' => 'test-1',
        ]);
    }

    public function test_transfer_amount_validation(): void
    {
        $transferService = app(\App\Services\CoopBank\CoopBankTransferService::class);

        $this->expectException(\App\Exceptions\CoopTransferException::class);
        $this->expectExceptionMessage('Amount must be greater than 0');

        $transferService->sendToMpesa(
            'ref-1', 'penalty_withdrawals', ['w' => 'p'], 0, '254712345678', 'Test'
        );
    }

    public function test_transfer_amount_too_large(): void
    {
        $transferService = app(\App\Services\CoopBank\CoopBankTransferService::class);

        $this->expectException(\App\Exceptions\CoopTransferException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $transferService->sendToMpesa(
            'ref-1', 'penalty_withdrawals', ['w' => 'p'], 999999999999, '254712345678', 'Test'
        );
    }

    public function test_message_reference_truncation(): void
    {
        $transferService = app(\App\Services\CoopBank\CoopBankTransferService::class);

        $method = new \ReflectionMethod($transferService, 'generateMessageReference');
        $method->setAccessible(true);

        $ref = $method->invoke($transferService, 'B2C', 'some-very-long-internal-id-1234567890');
        $this->assertLessThanOrEqual(28, strlen($ref));
    }

    public function test_stk_message_reference_truncation(): void
    {
        $stkService = app(\App\Services\CoopBank\CoopBankTransactionService::class);

        $method = new \ReflectionMethod($stkService, 'generateMessageReference');
        $method->setAccessible(true);

        $ref = $method->invoke($stkService, 'STK');
        $this->assertLessThanOrEqual(28, strlen($ref));
    }

    public function test_transaction_state_machine_valid_transitions(): void
    {
        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'STATE-TEST-001',
            'transfer_type' => 'STK',
            'status' => 'initiated',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'ref-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($transfer->isPending());
        $this->assertFalse($transfer->isComplete());

        $transfer->update(['status' => 'submitted']);
        $this->assertTrue($transfer->fresh()->isPending());

        $transfer->update(['status' => 'processing']);
        $this->assertTrue($transfer->fresh()->isPending());

        $transfer->update(['status' => 'successful']);
        $this->assertTrue($transfer->fresh()->isComplete());
        $this->assertFalse($transfer->fresh()->isPending());

        $transfer->update(['status' => 'failed']);
        $this->assertTrue($transfer->fresh()->isComplete());
    }

    public function test_transaction_state_machine_terminal_statuses(): void
    {
        foreach (['successful', 'failed', 'reversed', 'timeout'] as $status) {
            $transfer = CoopTransferRequest::create([
                'id' => \App\Support\Uuid::v4(),
                'message_reference' => 'STATE-'.$status,
                'transfer_type' => 'STK',
                'status' => $status,
                'amount' => 100,
                'currency' => 'KES',
                'narration_internal' => 'Test',
                'narration_bank' => 'Test',
                'internal_reference' => 'ref-1',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertTrue($transfer->isComplete(), "Status {$status} should be complete");
            $this->assertFalse($transfer->isPending(), "Status {$status} should not be pending");
        }
    }

    public function test_stk_callback_success_credits_wallet(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $initialBalance = (float) DB::table('donation_wallet')->value('total_balance');

        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => null,
            'amount' => 15000,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_CREDIT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/stk', [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-123',
                    'CheckoutRequestID' => 'ws_CO_CREDIT',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 15000],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'SGKCREDIT01'],
                            ['Name' => 'TransactionDate', 'Value' => (int) now()->format('YmdHis')],
                        ],
                    ],
                ],
            ],
        ]);

        $finalBalance = (float) DB::table('donation_wallet')->value('total_balance');
        $this->assertEquals($initialBalance + 15000, $finalBalance, 'Wallet should be credited on STK success');

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'direction' => 'credit',
            'mpesa_receipt' => 'SGKCREDIT01',
            'net_amount' => 15000,
        ]);
    }

    public function test_b2c_callback_success_settles_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $reservation = $walletService->reserveFunds(
            'donation',
            'success-settle-test',
            50000,
            'donation_withdrawals',
            'Reservation for successful B2C transfer'
        );

        $this->assertTrue($reservation['success']);

        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'b2c-success-ref',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'destination_account' => '254712345678',
            'amount' => 50000,
            'currency' => 'KES',
            'narration_internal' => 'Test withdrawal',
            'narration_bank' => 'Test withdrawal',
            'internal_reference' => 'success-settle-test',
            'internal_table' => 'donation_withdrawals',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'b2c-success-ref',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'TransactionId' => 'TXN-SUCCESS-001',
            'Amount' => 50000,
            'Currency' => 'KES',
        ]);

        $this->assertDatabaseHas('coop_transfer_requests', [
            'id' => $transfer->id,
            'status' => 'successful',
        ]);

        $wallet = DB::table('donation_wallet')->first();
        $this->assertEquals(0, (float) $wallet->reserved_balance);
        $this->assertEquals(50000, (float) $wallet->total_balance);
    }

    public function test_b2c_callback_failure_releases_reservation(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        $walletService->reserveFunds(
            'donation',
            'fail-release-test',
            50000,
            'donation_withdrawals',
            'Reservation for failed B2C transfer'
        );

        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'b2c-fail-ref',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'destination_account' => '254712345678',
            'amount' => 50000,
            'currency' => 'KES',
            'narration_internal' => 'Test withdrawal',
            'narration_bank' => 'Test withdrawal',
            'internal_reference' => 'fail-release-test',
            'internal_table' => 'donation_withdrawals',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/banking/coop/callback/transfer', [
            'MessageReference' => 'b2c-fail-ref',
            'Status' => 'FAILED',
            'StatusCode' => '1',
            'StatusDescription' => 'Insufficient funds',
            'TransactionId' => 'TXN-FAIL-001',
            'Amount' => 50000,
            'Currency' => 'KES',
        ]);

        $this->assertDatabaseHas('coop_transfer_requests', [
            'id' => $transfer->id,
            'status' => 'failed',
        ]);

        $wallet = DB::table('donation_wallet')->first();
        $this->assertEquals(0, (float) $wallet->reserved_balance);
        $this->assertEquals(100000, (float) $wallet->total_balance);
    }

    public function test_callback_duplicate_idempotent(): void
    {
        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'DUP-IDEMPOTENT',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'withdrawal-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = [
            'MessageReference' => 'DUP-IDEMPOTENT',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'TransactionId' => 'TXN-DUP-IDEMP',
        ];

        $this->postJson('/api/banking/coop/callback/transfer', $callback);
        $this->postJson('/api/banking/coop/callback/transfer', $callback);

        $this->assertEquals(1, DB::table('coop_transfer_responses')
            ->where('transaction_id', 'TXN-DUP-IDEMP')
            ->count());

        $this->assertEquals(1, DB::table('coop_transfer_requests')
            ->where('id', $transfer->id)
            ->where('status', 'successful')
            ->count());
    }

    public function test_wallet_transaction_duplicate_prevention(): void
    {
        $walletService = app(\App\Services\WalletService::class);

        $walletService->credit('donation', 'test-member', 50000, 'receipt-1', 'test', null, null, 'First credit');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $walletService->credit('donation', 'test-member', 50000, 'receipt-1', 'test', null, null, 'Duplicate should fail');
    }

    public function test_token_401_triggers_refresh(): void
    {
        Http::fake([
            '*/token' => Http::sequence()
                ->push(['access_token' => 'expired_token', 'expires_in' => 3600], 200)
                ->push(['access_token' => 'new_token', 'expires_in' => 3600], 200),
            '*/FundsTransfer*' => Http::sequence()
                ->push(['MessageCode' => '-8', 'MessageDescription' => 'Auth failure'], 401)
                ->push(['MessageCode' => '0', 'MessageDescription' => 'Success', 'TransactionId' => 'TXN-REFRESH'], 200),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test_key');
        config()->set('coop_bank.consumer_secret', 'test_secret');

        $client = app(\App\Services\CoopBank\CoopBankClient::class);
        $result = $client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', []);

        $this->assertTrue($result['success']);
        $this->assertEquals('TXN-REFRESH', $result['body']['TransactionId']);
    }

    public function test_stk_callback_idempotent_does_not_double_credit(): void
    {
        $walletService = app(\App\Services\WalletService::class);
        $walletService->credit('donation', 'test-member', 100000, 'init-credit', 'test_setup', null, null, 'Initial test credit');

        DB::table('donation_payment_records')->insert([
            'id' => \App\Support\Uuid::v4(),
            'member_id' => null,
            'amount' => 25000,
            'status' => 'pending',
            'mpesa_transaction_id' => 'ws_CO_IDEMPOTENT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'test-123',
                    'CheckoutRequestID' => 'ws_CO_IDEMPOTENT',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RECEIPT-DUP-CB'],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/banking/coop/callback/stk', $callback);
        $this->postJson('/api/banking/coop/callback/stk', $callback);

        $receiptCount = DB::table('wallet_transactions')
            ->where('mpesa_receipt', 'RECEIPT-DUP-CB')
            ->where('direction', 'credit')
            ->count();

        $this->assertEquals(1, $receiptCount, 'Should only credit wallet once for duplicate callback');

        $wallet = DB::table('donation_wallet')->first();
        $this->assertEquals(125000, (float) $wallet->total_balance);
    }

    public function test_callback_missing_fields_handled_gracefully(): void
    {
        $response = $this->postJson('/api/banking/coop/callback/transfer', []);

        $response->assertStatus(200);
    }

    public function test_stk_mobile_number_format_matches_contract(): void
    {
        $stkService = app(\App\Services\CoopBank\CoopBankTransactionService::class);

        $method = new \ReflectionMethod($stkService, 'normalizePhone');
        $method->setAccessible(true);

        $result = $method->invoke($stkService, '254707919065');
        $this->assertEquals('254707919065', $result, 'normalizePhone should return full 254 format for STK');

        $result2 = $method->invoke($stkService, '0710327336');
        $this->assertEquals('254710327336', $result2, 'normalizePhone should convert 0-prefixed to 254 format');
    }

    public function test_stk_stores_full_254_phone_in_transfer_record(): void
    {
        $stkService = app(\App\Services\CoopBank\CoopBankTransactionService::class);

        $method = new \ReflectionMethod($stkService, 'normalizePhone');
        $method->setAccessible(true);

        $phone = $method->invoke($stkService, '254707919065');
        $this->assertTrue(strlen($phone) === 12 && strpos($phone, '254') === 0, 'Phone must be 254XXXXXXXXX format');
    }

    public function test_b2c_mobile_number_format_matches_contract(): void
    {
        $transferService = app(\App\Services\CoopBank\CoopBankTransferService::class);

        $method = new \ReflectionMethod($transferService, 'normalizePhone');
        $method->setAccessible(true);

        $result1 = $method->invoke($transferService, '0710327336');
        $this->assertEquals('0710327336', $result1, 'B2C should preserve 07XX format');

        $result2 = $method->invoke($transferService, '254710327336');
        $this->assertEquals('0710327336', $result2, '254 format should convert to 0-prefixed for B2C');

        $result3 = $method->invoke($transferService, '710327336');
        $this->assertEquals('0710327336', $result3, '9-digit should get 0 prefix for B2C');
    }

    public function test_process_callback_null_transaction_id_deduplication(): void
    {
        $transfer = CoopTransferRequest::create([
            'id' => \App\Support\Uuid::v4(),
            'message_reference' => 'NULL-TXNID-TEST',
            'transfer_type' => 'B2C_MPESA',
            'status' => 'processing',
            'amount' => 100,
            'currency' => 'KES',
            'narration_internal' => 'Test',
            'narration_bank' => 'Test',
            'internal_reference' => 'withdrawal-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $callback1 = [
            'MessageReference' => 'NULL-TXNID-TEST',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'Amount' => 100,
        ];

        $callback2 = [
            'MessageReference' => 'NULL-TXNID-TEST',
            'Status' => 'SUCCESS',
            'StatusCode' => '0',
            'StatusDescription' => 'Success',
            'Amount' => 100,
        ];

        $this->postJson('/api/banking/coop/callback/transfer', $callback1);
        $this->postJson('/api/banking/coop/callback/transfer', $callback2);

        $responseCount = DB::table('coop_transfer_responses')
            ->where('transfer_request_id', $transfer->id)
            ->whereNull('transaction_id')
            ->count();

        $this->assertEquals(1, $responseCount, 'Duplicate callback with NULL TransactionId should create only one response record');
    }
}