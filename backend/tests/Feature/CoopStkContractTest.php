<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoopStkContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_stk_uses_documented_endpoints_and_no_callback_url(): void
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000066',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Contract Member',
            'phone' => '+254700000066',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test-key');
        config()->set('coop_bank.consumer_secret', 'test-secret');
        Http::fake([
            'https://openapi.co-opbank.co.ke/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ], 200),
            'https://openapi.co-opbank.co.ke/FT/stk/1.0.0' => Http::response([
                'MessageCode' => '0',
                'MessageDescription' => 'Accepted',
                'CheckoutRequestID' => 'COOP-CHECKOUT-1',
            ], 200),
            'https://openapi.co-opbank.co.ke/Enquiry/STK/1.0.0/' => Http::response([
                'Status' => 'SUCCESS',
                'MessageDescription' => 'Completed',
                'MpesaReceiptNumber' => 'RECEIPT-1',
            ], 200),
        ]);

        $service = app(CoopBankTransactionService::class);
        $initiation = $service->initiateStkPush([
            'member_id' => $memberId,
            'amount' => 300,
            'phone' => '+254700000066',
            'narration' => 'KIRINYAGA HEALTH CARE WORKERS',
            'internal_reference' => 'LOCAL-STK-CONTRACT-1',
            'payment_type' => 'monthly_contribution',
        ]);
        $status = $service->queryStkStatus($initiation['message_reference']);

        Http::assertSent(function (Request $request): bool {
            if (str_ends_with($request->url(), '/FT/stk/1.0.0')) {
                return ! array_key_exists('CallBackUrl', $request->data())
                    && $request->data()['OperatorCode'] === 'KIRINYAGA'
                    && $request->data()['TransactionCurrency'] === 'KES';
            }

            return true;
        });
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/Enquiry/STK/1.0.0/'));
        $this->assertSame('success', $status['status']);
        $this->assertSame('RECEIPT-1', $status['receipt']);
    }

    public function test_accepted_for_processing_remains_pending(): void
    {
        config()->set('coop_bank.enable_real_calls', true);
        config()->set('coop_bank.consumer_key', 'test-key');
        config()->set('coop_bank.consumer_secret', 'test-secret');

        Http::fake([
            'https://openapi.co-opbank.co.ke/token' => Http::response([
                'access_token' => 'test-token',
                'expires_in' => 3600,
            ], 200),
            'https://openapi.co-opbank.co.ke/Enquiry/STK/1.0.0/' => Http::response([
                'MessageCode' => '0',
                'MessageDescription' => 'REQUEST ACCEPTED FOR PROCESSING',
            ], 200),
        ]);

        $status = app(CoopBankAccountService::class)->queryStkStatus('STK-ACCEPTED-1');

        $this->assertSame('PENDING', $status['status']);
    }
}
