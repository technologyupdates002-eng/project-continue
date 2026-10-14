<?php

namespace Tests\Feature\Payments;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltyPaymentTest extends TestCase
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
            'name' => 'Penalty Test Member',
            'phone' => $phone,
            'member_id' => 'PN' . $memberId,
            'is_active' => 1,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }

    public function test_penalty_stk_creates_pending_record(): void
    {
        $memberId = $this->createMember();

        $response = $this->postJson('/api/payments/stk-push', [
            'member_id' => $memberId,
            'amount' => 500,
            'phone' => '+254712345678',
            'accountReference' => 'PENALTY-TEST',
            'transactionDesc' => 'Penalty payment',
            'paymentType' => 'penalty',
        ], $this->adminHeaders);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseHas('penalty_payment_records', [
            'member_id' => $memberId,
            'amount' => 500,
            'status' => 'pending',
        ]);
    }

}
