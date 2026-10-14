<?php

namespace Tests\Feature\Payments;

use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankReconciliationService;
use App\Services\JwtService;
use App\Support\Phone;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
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
        $memberId = Uuid::v4();
        $phone = '+2547'.substr($memberId, 0, 8);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $memberId,
            'name' => 'Recon Test Member',
            'phone' => $phone,
            'member_id' => 'RC'.$memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    private function createCampaign(float $amount = 100): string
    {
        $campaignId = Uuid::v4();

        DB::table('donation_campaigns')->insert([
            'id' => $campaignId,
            'title' => 'Recon Test Drive',
            'description' => 'Test campaign for reconciliation',
            'amount' => $amount,
            'active' => 1,
            'goal_type' => 'fixed',
            'target_total' => 5000,
            'allow_partial' => 1,
            'total_collected' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $campaignId;
    }

    public function test_reconciliation_repairs_missed_callback_for_donation(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(100);
        $checkoutId = 'ws_CO_a1b2c3d4e5f6';

        // Create pending donation record (callback was missed)
        DB::table('donation_payment_records')->insert([
            'id' => Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 10,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create CoopTransferRequest that was marked successful by status query
        // but callback was missed
        $transfer = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => 'RECON-TEST-001',
            'transfer_type' => 'STK',
            'status' => 'successful',
            'destination_account' => '254712345678',
            'amount' => 10,
            'currency' => 'KES',
            'narration_internal' => 'Donation to Recon Test Drive',
            'narration_bank' => 'Donation payment',
            'internal_reference' => 'donation-'.$memberId.'-'.$checkoutId,
            'internal_table' => 'donation_payment_records',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Before reconciliation: record is still pending
        $this->assertEquals('pending', DB::table('donation_payment_records')
            ->where('mpesa_transaction_id', $checkoutId)->value('status'));

        $campaignBefore = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected');
        $this->assertEquals(0, $campaignBefore);

        // Run reconciliation
        $reconciliationService = app(CoopBankReconciliationService::class);
        $settled = $reconciliationService->settleSuccessfulTransfer($transfer);

        $this->assertTrue($settled);

        // After reconciliation: record should be verified
        $this->assertEquals('verified', DB::table('donation_payment_records')
            ->where('mpesa_transaction_id', $checkoutId)->value('status'));

        // Campaign total should be incremented
        $campaignAfter = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected');
        $this->assertEquals(10, $campaignAfter);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(100);
        $checkoutId = 'ws_CO_0123456789abcdef';

        DB::table('donation_payment_records')->insert([
            'id' => Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 10,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transfer = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => 'RECON-IDEM-001',
            'transfer_type' => 'STK',
            'status' => 'successful',
            'destination_account' => '254712345678',
            'amount' => 10,
            'currency' => 'KES',
            'narration_internal' => 'Donation',
            'narration_bank' => 'Donation',
            'internal_reference' => 'donation-'.$memberId.'-'.$checkoutId,
            'internal_table' => 'donation_payment_records',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciliationService = app(CoopBankReconciliationService::class);

        // Run twice
        $reconciliationService->settleSuccessfulTransfer($transfer);
        $settledSecond = $reconciliationService->settleSuccessfulTransfer($transfer);

        // Second call should return false (already settled)
        $this->assertFalse($settledSecond);

        // Campaign total should still be 10 (not 20)
        $campaignAfter = (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)->value('total_collected');
        $this->assertEquals(10, $campaignAfter, 'Idempotent: no double-counting');
    }

    public function test_legacy_penalty_reconciliation_links_and_clears_the_matching_penalty(): void
    {
        $memberId = $this->createMember();
        $penaltyId = Uuid::v4();
        $checkoutId = 'ws_CO_9876543210abcdef';

        DB::table('penalties')->insert([
            'id' => $penaltyId,
            'member_id' => $memberId,
            'amount' => 500,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);
        DB::table('penalty_payment_records')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 500,
            'status' => 'pending',
            'mpesa_transaction_id' => $checkoutId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transfer = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => 'PENALTY-RECON-001',
            'transfer_type' => 'STK',
            'status' => 'successful',
            'destination_account' => '254712345678',
            'amount' => 500,
            'currency' => 'KES',
            'narration_internal' => 'Penalty payment',
            'narration_bank' => 'Penalty payment',
            'internal_reference' => $checkoutId,
            'internal_table' => 'penalty_payment_records',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settled = app(CoopBankReconciliationService::class)->settleSuccessfulTransfer($transfer);

        $this->assertTrue($settled);
        $this->assertDatabaseHas('penalty_payment_records', [
            'member_id' => $memberId,
            'penalty_id' => $penaltyId,
            'status' => 'verified',
        ]);
        $this->assertDatabaseHas('penalties', [
            'id' => $penaltyId,
            'is_paid' => 1,
        ]);
    }

    public function test_reconciliation_repairs_wallet_credit_for_already_verified_donation(): void
    {
        $memberId = $this->createMember();
        $campaignId = $this->createCampaign(100);
        $checkoutId = 'ws_CO_fedcba987654';

        // Record already verified
        DB::table('donation_payment_records')->insert([
            'id' => Uuid::v4(),
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'amount' => 10,
            'status' => 'verified',
            'mpesa_transaction_id' => $checkoutId,
            'payment_ref' => 'PRE_VERIFIED_REF',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transfer = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => 'RECON-DONE-001',
            'transfer_type' => 'STK',
            'status' => 'successful',
            'destination_account' => '254712345678',
            'amount' => 10,
            'currency' => 'KES',
            'narration_internal' => 'Donation',
            'narration_bank' => 'Donation',
            'internal_reference' => 'donation-'.$memberId.'-'.$checkoutId,
            'internal_table' => 'donation_payment_records',
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciliationService = app(CoopBankReconciliationService::class);
        $settled = $reconciliationService->settleSuccessfulTransfer($transfer);

        $this->assertTrue($settled);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_type' => 'donation',
            'direction' => 'credit',
            'reference_id' => DB::table('donation_payment_records')
                ->where('mpesa_transaction_id', $checkoutId)
                ->value('id'),
            'reference_table' => 'donation_payment_records',
            'gross_amount' => 10,
        ]);
        $this->assertSame(10.0, (float) DB::table('donation_wallet')->value('total_balance'));
        $this->assertSame(0.0, (float) DB::table('donation_campaigns')
            ->where('id', $campaignId)
            ->value('total_collected'));
    }

    public function test_phone_number_normalization(): void
    {
        $phone = Phone::normalize('0712345678');
        $this->assertEquals('254712345678', $phone);

        $phone = Phone::normalize('254712345678');
        $this->assertEquals('254712345678', $phone);

        $phone = Phone::normalize('+254712345678');
        $this->assertEquals('254712345678', $phone);

        $local = Phone::normalizeLocal('254712345678');
        $this->assertEquals('0712345678', $local);
    }
}
