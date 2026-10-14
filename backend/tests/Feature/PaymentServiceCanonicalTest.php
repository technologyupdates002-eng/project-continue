<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\ContributionObligationService;
use App\Services\PaymentEngine;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentService;
use App\Services\Statement\ContributionAllocationService;
use App\Services\WalletService;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentServiceCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_monthly_stk_status_polls_finalize_canonical_payment_once(): void
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000088',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'STK Canonical Member',
            'phone' => '+254700000088',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checkoutId = 'ws_CO_canonical_1001';
        DB::table('contributions')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'month' => 9,
            'year' => 2026,
            'due_date' => '2026-09-30',
            'amount' => 300,
            'status' => 'pending',
            'stk_checkout_request_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $engine = app(PaymentEngine::class);
        $engine->createPendingPayment($memberId, 'mpesa', $checkoutId, $checkoutId, 30000);
        $stk = $this->createMock(CoopBankTransactionService::class);
        $stk->expects($this->once())->method('queryStkStatus')->with($checkoutId)->willReturn([
            'status' => 'success',
            'provider_status' => 'SUCCESS',
            'message' => 'Completed',
            'response' => ['Status' => 'SUCCESS'],
        ]);
        $service = new PaymentService(
            $stk,
            $this->createMock(CoopBankAccountService::class),
            $this->createMock(CoopBankTransferService::class),
            $this->createMock(WalletService::class),
            $this->createMock(PaymentLifecycleService::class),
            $engine,
            null,
            app(ContributionAllocationService::class),
            app(ContributionObligationService::class),
        );

        $identity = ['member_id' => $memberId, 'roles' => ['member']];
        $service->getStatus($identity, $checkoutId);
        $service->getStatus($identity, $checkoutId);

        $this->assertSame(1, DB::table('payments')->where('member_id', $memberId)->count());
        $this->assertSame('confirmed', DB::table('payments')->where('member_id', $memberId)->value('status'));
        $this->assertSame(1, DB::table('payment_status_checks')->where('payment_id', DB::table('payments')->where('member_id', $memberId)->value('id'))->count());
        $this->assertSame(1, DB::table('contribution_allocations')->where('member_id', $memberId)->count());
    }
}
