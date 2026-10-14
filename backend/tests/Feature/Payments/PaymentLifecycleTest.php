<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\JwtService;
use App\Services\PaymentLifecycleService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $phone = null): string
    {
        $memberId = \App\Support\Uuid::v4();
        $phone = $phone ?: '+2547' . substr($memberId, 0, 8);

        DB::table('auth_users')->insert([
            'id' => \App\Support\Uuid::v4(),
            'email' => $phone . '@test.local',
            'phone' => $phone,
            'encrypted_password' => password_hash('Secret123', PASSWORD_BCRYPT),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => \App\Support\Uuid::v4(),
            'name' => 'Lifecycle Test Member',
            'phone' => $phone,
            'member_id' => $memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function seedWelfareSettings(float $monthly = 300): void
    {
        DB::table('welfare_settings')->insert([
            'id' => \App\Support\Uuid::v4(),
            'name' => 'Welfare',
            'monthly_contribution_amount' => $monthly,
            'contribution_due_day' => 15,
            'penalty_amount' => 100,
            'penalty_grace_days' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFundDrive(float $amount = 10000, bool $allowPartial = true): string
    {
        $campaignId = \App\Support\Uuid::v4();
        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Medical Support Campaign',
            'description' => 'Test fund drive',
            'amount' => $amount,
            'active' => 1,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'goal_type' => 'fixed',
            'target_total' => $amount,
            'allow_partial' => $allowPartial ? 1 : 0,
            'total_collected' => 0,
        ]);
        return $campaignId;
    }

    // ========================================================================
    // FUND DRIVE TESTS
    // ========================================================================

    public function test_fund_drive_full_payment_completes_campaign(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(10000);
        $lifecycle = app(PaymentLifecycleService::class);

        $result = $lifecycle->postPayment('donation', $memberId, 10000, 'RECEIPT-FULL', $campaignId, 'stk_push');

        $this->assertTrue($result['success']);
        $this->assertEquals(10000, $result['total_contributed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals('completed', $result['status']);

        $campaign = DB::table('donation_campaigns')->where('id', $campaignId)->first();
        $this->assertEquals(10000, (float) $campaign->total_collected);
    }

    public function test_fund_drive_partial_payment_reduces_balance(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(10000);
        $lifecycle = app(PaymentLifecycleService::class);

        $result1 = $lifecycle->postPayment('donation', $memberId, 1000, 'RECEIPT-PART-1', $campaignId, 'stk_push');
        $this->assertTrue($result1['success']);
        $this->assertEquals(1000, $result1['total_contributed']);
        $this->assertEquals(9000, $result1['remaining']);
        $this->assertEquals('active', $result1['status']);

        $result2 = $lifecycle->postPayment('donation', $memberId, 2500, 'RECEIPT-PART-2', $campaignId, 'stk_push');
        $this->assertTrue($result2['success']);
        $this->assertEquals(3500, $result2['total_contributed']);
        $this->assertEquals(6500, $result2['remaining']);
        $this->assertEquals('active', $result2['status']);
    }

    public function test_fund_drive_lipa_mdogo_mdogo_series(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(10000);
        $lifecycle = app(PaymentLifecycleService::class);

        $lifecycle->postPayment('donation', $memberId, 1000, 'RECEIPT-LMM-1', $campaignId, 'stk_push');
        $lifecycle->postPayment('donation', $memberId, 2500, 'RECEIPT-LMM-2', $campaignId, 'stk_push');
        $result = $lifecycle->postPayment('donation', $memberId, 6500, 'RECEIPT-LMM-3', $campaignId, 'stk_push');

        $this->assertEquals(10000, $result['total_contributed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals('completed', $result['status']);
    }

    public function test_fund_drive_overpayment_accepts_only_required_amount(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(500);
        $lifecycle = app(PaymentLifecycleService::class);

        $result = $lifecycle->postPayment('donation', $memberId, 800, 'RECEIPT-OVER', $campaignId, 'stk_push');

        $this->assertEquals(500, $result['amount']);
        $this->assertEquals(500, $result['total_contributed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals(300, $result['excess']);
    }

    public function test_fund_drive_wallet_credited_on_success(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(10000);
        $lifecycle = app(PaymentLifecycleService::class);

        $lifecycle->postPayment('donation', $memberId, 3000, 'RECEIPT-WALLET', $campaignId, 'stk_push');

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'direction' => 'credit',
            'mpesa_receipt' => 'RECEIPT-WALLET',
            'gross_amount' => 3000,
        ]);
    }

    public function test_general_donation_without_campaign_updates_wallet_idempotently(): void
    {
        $memberId = $this->createMember();
        $checkoutId = 'DONATION-CHECKOUT-1';
        $providerReference = 'DONATION-RECEIPT-1';
        $recordId = \App\Support\Uuid::v4();

        DB::table('donation_payment_records')->insert([
            'id' => $recordId,
            'campaign_id' => null,
            'member_id' => $memberId,
            'amount' => 50,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lifecycle = app(PaymentLifecycleService::class);
        $first = $lifecycle->postPayment(
            'donation',
            $memberId,
            50,
            $providerReference,
            null,
            'stk_status',
            null,
            $checkoutId,
        );
        $second = $lifecycle->postPayment(
            'donation',
            $memberId,
            50,
            $providerReference,
            null,
            'stk_status',
            null,
            $checkoutId,
        );

        $this->assertTrue($first['success']);
        $this->assertTrue($second['idempotent']);
        $this->assertDatabaseHas('donation_payment_records', [
            'id' => $recordId,
            'status' => 'verified',
            'payment_ref' => $providerReference,
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')
            ->where('wallet_type', 'donation')
            ->where('reference_id', $recordId)
            ->where('reference_table', 'donation_payment_records')
            ->count());
        $this->assertSame(50.0, (float) DB::table('donation_wallet')->value('total_balance'));
    }

    // ========================================================================
    // PENALTY TESTS
    // ========================================================================

    public function test_penalty_full_payment_marks_paid(): void
    {
        $memberId = $this->createMember();
        $penaltyId = \App\Support\Uuid::v4();

        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $lifecycle = app(PaymentLifecycleService::class);
        $result = $lifecycle->postPayment('penalty', $memberId, 500, 'RECEIPT-PEN-FULL', $penaltyId, 'stk_push');

        $this->assertTrue($result['success']);
        $this->assertEquals(500, $result['paid_amount']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertEquals('paid', $result['status']);
    }

    public function test_penalty_partial_payment_marks_partially_paid(): void
    {
        $memberId = $this->createMember();
        $penaltyId = \App\Support\Uuid::v4();

        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $lifecycle = app(PaymentLifecycleService::class);
        $result = $lifecycle->postPayment('penalty', $memberId, 200, 'RECEIPT-PEN-PART', $penaltyId, 'stk_push');

        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['paid_amount']);
        $this->assertEquals(300, $result['remaining']);
        $this->assertEquals('partially_paid', $result['status']);
    }

    public function test_penalty_wallet_credited_on_success(): void
    {
        $memberId = $this->createMember();
        $penaltyId = \App\Support\Uuid::v4();

        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $lifecycle = app(PaymentLifecycleService::class);
        $lifecycle->postPayment('penalty', $memberId, 200, 'RECEIPT-PEN-WALLET', $penaltyId, 'stk_push');

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'penalty',
            'direction' => 'credit',
            'mpesa_receipt' => 'RECEIPT-PEN-WALLET',
            'gross_amount' => 200,
        ]);
    }

    // ========================================================================
    // IDEMPOTENCY TESTS
    // ========================================================================

    public function test_fund_drive_idempotent_same_receipt(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createFundDrive(10000);
        $lifecycle = app(PaymentLifecycleService::class);

        $lifecycle->postPayment('donation', $memberId, 1000, 'RECEIPT-IDEM-FD', $campaignId, 'stk_push');
        $result2 = $lifecycle->postPayment('donation', $memberId, 1000, 'RECEIPT-IDEM-FD', $campaignId, 'stk_push');

        $this->assertTrue($result2['idempotent']);
        $this->assertEquals(1000, $result2['total_contributed']);
    }

    public function test_penalty_idempotent_same_receipt(): void
    {
        $memberId = $this->createMember();
        $penaltyId = \App\Support\Uuid::v4();

        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $lifecycle = app(PaymentLifecycleService::class);
        $lifecycle->postPayment('penalty', $memberId, 500, 'RECEIPT-IDEM-PEN', $penaltyId, 'stk_push');
        $result2 = $lifecycle->postPayment('penalty', $memberId, 500, 'RECEIPT-IDEM-PEN', $penaltyId, 'stk_push');

        $this->assertTrue($result2['idempotent']);
        $this->assertEquals(500, $result2['paid_amount']);
    }

    // ========================================================================
    // MONTHLY CONTRIBUTION TESTS (via lifecycle)
    // ========================================================================

    public function test_monthly_contribution_300_via_lifecycle(): void
    {
        $memberId = $this->createMember();
        $this->seedWelfareSettings(300);
        $lifecycle = app(PaymentLifecycleService::class);

        $result = $lifecycle->postPayment('monthly_contribution', $memberId, 300, 'RECEIPT-MC-300');

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['months_fully_paid']);
    }

    public function test_monthly_contribution_600_via_lifecycle(): void
    {
        $memberId = $this->createMember();
        $this->seedWelfareSettings(300);
        $lifecycle = app(PaymentLifecycleService::class);

        $result = $lifecycle->postPayment('monthly_contribution', $memberId, 600, 'RECEIPT-MC-600');

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['months_fully_paid']);
    }
}
