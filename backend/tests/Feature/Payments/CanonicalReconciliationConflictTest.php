<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\CoopBank\CoopBankReconciliationService;
use App\Services\PaymentEngine;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalReconciliationConflictTest extends TestCase
{
    use RefreshDatabase;

    private function member(): string
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000055',
            'encrypted_password' => bcrypt('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Recon Canonical Member',
            'phone' => '+254700000055',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $memberId;
    }

    public function test_reversed_payment_cannot_be_confirmed_by_reconciliation(): void
    {
        $memberId = $this->member();
        $engine = app(PaymentEngine::class);
        $payment = $engine->createPendingPayment($memberId, 'bank', 'RECON-REV-1', 'RECON-REV-1', 30000);
        $engine->reversePayment($payment['payment_id']);

        $result = $engine->updateProviderStatus($payment['payment_id'], 'success', 'Provider reported success after reversal', ['Status' => 'SUCCESS']);

        $this->assertSame('reversed', $result['status']);
        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'provider_status_conflict')->count());
        $this->assertSame(0, DB::table('contribution_allocations')->where('payment_id', $payment['payment_id'])->count());
    }

    public function test_confirmed_payment_reconciliation_conflict_does_not_change_state(): void
    {
        $memberId = $this->member();
        $engine = app(PaymentEngine::class);
        $payment = $engine->processProviderEvent([
            'provider' => 'bank',
            'provider_event_id' => 'RECON-CONF-1',
            'event_type' => 'bank_confirmed',
            'member_id' => $memberId,
            'amount_minor' => 30000,
            'provider_reference' => 'RECON-CONF-1',
        ]);

        $result = $engine->updateProviderStatus($payment['payment_id'], 'failed', 'Conflicting provider status', ['Status' => 'FAILED']);

        $this->assertSame('confirmed', $result['status']);
        $this->assertSame('confirmed', DB::table('payments')->where('id', $payment['payment_id'])->value('status'));
        $this->assertSame(1, DB::table('financial_exceptions')->where('type', 'provider_status_conflict')->count());
    }

    public function test_unknown_reconciliation_reference_is_safe_noop(): void
    {
        $this->assertFalse(app(CoopBankReconciliationService::class)->settleSuccessfulTransfer(
            new \App\Models\CoopTransferRequest([
                'transfer_type' => 'STK',
                'internal_reference' => 'unknown-reference',
                'message_reference' => 'unknown-provider-reference',
            ])
        ));
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('contribution_allocations')->count());
    }
}
