<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\FinancialReadModel;
use App\Services\MemberStatsService;
use App\Services\MemberFinancialProjectionService;
use App\Services\PaymentEngine;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalFinancialConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_surfaces_agree_on_cash_allocation_and_unapplied_amount(): void
    {
        config()->set('cache.default', 'array');
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000044',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert(['id' => Uuid::v4(), 'user_id' => $userId, 'role' => 'member', 'is_active' => 1]);
        DB::table('user_roles')->insert(['id' => Uuid::v4(), 'user_id' => $userId, 'role' => 'super_admin', 'is_active' => 1]);
        DB::table('members')->insert([
            'id' => $memberId, 'user_id' => $userId, 'name' => 'Consistency Member',
            'phone' => '+254700000044', 'member_id' => $memberId, 'status' => 'active',
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $payment = app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'manual', 'provider_event_id' => 'CONSISTENCY-1000',
            'event_type' => 'manual_confirmed', 'member_id' => $memberId,
            'amount_minor' => 100000, 'provider_reference' => 'CONSISTENCY-1000',
            'internal_reference' => 'CONSISTENCY-1000', 'source' => 'manual',
            'occurred_at' => '2026-09-01 10:00:00',
            'allocations' => [
                ['year' => 2026, 'month' => 9, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 10, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 11, 'amount_minor' => 30000],
            ],
        ]);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $stats = app(MemberStatsService::class)->getStats($memberId);
        $token = JwtService::encode(JwtService::buildPayload($userId, $memberId . '@welfare.local', ['member', 'super_admin']));
        $endpoint = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members/financial?member_id=' . $memberId);
        $treasurerActivity = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/treasurer/recent-activity');
        $adminTransactions = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/api/transactions');
        $adminReports = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/api/reports');
        $superAdminStats = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/super-admin/stats');

        $this->assertSame(100000, (int) DB::table('payments')->where('id', $payment['payment_id'])->value('amount_minor'));
        $this->assertSame(100000, $overview['cash_received_minor']);
        $this->assertSame(90000, $overview['allocated_minor']);
        $this->assertSame(10000, $overview['unapplied_minor']);
        $this->assertSame(100000, $stats['cash_received_minor']);
        $this->assertSame(90000, $stats['allocated_minor']);
        $this->assertSame(10000, $stats['unapplied_minor']);
        $endpoint->assertStatus(200)->assertJsonPath('data.overview.cash_received_minor', 100000)
            ->assertJsonPath('data.overview.allocated_minor', 90000)
            ->assertJsonPath('data.overview.unapplied_minor', 10000);
        $this->assertEquals(1000.0, (float) $treasurerActivity->assertStatus(200)->json('data.0.amount'));
        $this->assertEquals(1000.0, (float) $adminTransactions->assertStatus(200)->json('transactions.0.amount'));
        $this->assertEquals(1000.0, (float) $adminReports->assertStatus(200)->json('reports.total_contributions'));
        $this->assertEquals(1000.0, (float) $superAdminStats->assertStatus(200)->json('data.contributions.total'));
    }

    public function test_projection_preserves_mixed_history_without_double_counting(): void
    {
        $memberId = Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => 'Mixed History Member',
            'phone' => '+254700000045',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contributions')->insert([
            'id' => Uuid::v4(),
            'member_id' => $memberId,
            'amount' => 300,
            'month' => 1,
            'year' => 2026,
            'due_date' => '2026-01-31',
            'status' => 'paid',
            'paid_date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PaymentEngine::class)->processProviderEvent([
            'provider' => 'manual',
            'provider_event_id' => 'MIXED-HISTORY-1000',
            'event_type' => 'manual_confirmed',
            'member_id' => $memberId,
            'amount_minor' => 100000,
            'provider_reference' => 'MIXED-HISTORY-1000',
            'internal_reference' => 'MIXED-HISTORY-1000',
            'source' => 'manual',
            'occurred_at' => '2026-02-01 10:00:00',
            'allocations' => [
                ['year' => 2026, 'month' => 2, 'amount_minor' => 30000],
                ['year' => 2026, 'month' => 3, 'amount_minor' => 30000],
            ],
        ]);

        $projection = app(MemberFinancialProjectionService::class);
        $projection->syncMember($memberId);
        $projection->syncMember($memberId);

        $this->assertSame(1000.0, (float) DB::table('members')->where('id', $memberId)->value('total_contributions'));
        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $this->assertSame(100000, $overview['cash_received_minor']);
        $this->assertSame(60000, $overview['allocated_minor']);
        $this->assertSame(40000, $overview['unapplied_minor']);
        $this->assertSame(1, DB::table('carry_forward_balances')->where('member_id', $memberId)->count());
    }
}
