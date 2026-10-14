<?php

namespace Tests\Feature;

use App\Services\FinancialReadModel;
use App\Services\JwtService;
use App\Services\MemberStatsService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltyWaiverTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_record_partial_penalty_waiver_without_marking_penalty_paid(): void
    {
        $adminId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $adminId,
            'email' => "{$adminId}@welfare.local",
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => null,
            'name' => 'Penalty Waiver Member',
            'phone' => '+254700000111',
            'status' => 'active',
            'is_active' => 1,
        ]);
        DB::table('penalties')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 100,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $token = JwtService::encode(JwtService::buildPayload(
            $adminId,
            "{$adminId}@welfare.local",
            ['admin']
        ));

        $response = $this->withToken($token)
            ->postJson("/api/functions/v1/admin/members/{$memberId}/penalty-waivers", [
                'amount' => 25,
                'reason' => 'Approved hardship request',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount_minor', 2500)
            ->assertJsonPath('data.penalties_outstanding_minor', 7500);

        $this->assertDatabaseHas('penalties', [
            'member_id' => $memberId,
            'is_paid' => 0,
        ]);
        $this->assertDatabaseHas('penalty_waivers', [
            'member_id' => $memberId,
            'amount_minor' => 2500,
            'reason' => 'Approved hardship request',
            'created_by' => $adminId,
        ]);
        $this->assertSame(7500, app(FinancialReadModel::class)->memberOverview($memberId)['penalties_outstanding_minor']);
        $this->assertEquals(75.0, app(MemberStatsService::class)->getStats($memberId)['penalty_outstanding']);
    }

    public function test_penalty_waiver_cannot_exceed_current_outstanding_balance(): void
    {
        $adminId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $adminId,
            'email' => "{$adminId}@welfare.local",
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => null,
            'name' => 'Penalty Waiver Member',
            'phone' => '+254700000112',
            'status' => 'active',
            'is_active' => 1,
        ]);
        DB::table('penalties')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 10,
            'reason' => 'Late',
            'is_paid' => 0,
            'created_at' => now(),
        ]);
        $token = JwtService::encode(JwtService::buildPayload(
            $adminId,
            "{$adminId}@welfare.local",
            ['admin']
        ));

        $this->withToken($token)
            ->postJson("/api/functions/v1/admin/members/{$memberId}/penalty-waivers", [
                'amount' => 10.01,
                'reason' => 'Exceeds current balance',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('penalty_waivers', 0);
        $this->assertDatabaseHas('penalties', [
            'member_id' => $memberId,
            'is_paid' => 0,
        ]);
    }
}
