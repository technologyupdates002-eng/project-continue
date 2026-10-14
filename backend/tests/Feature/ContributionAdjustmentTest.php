<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\ContributionAdjustmentService;
use App\Services\FinancialReadModel;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContributionAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(): array
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $memberId . '@welfare.local',
            'phone' => '+254700000111',
            'encrypted_password' => AuthService::hashPassword('Secret123'),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => 'Adjustment Member',
            'phone' => '+254700000111',
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'total_contributions' => 0,
            'total_penalties' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$memberId, $userId];
    }

    public function test_adjustments_change_canonical_totals_and_statement_reason(): void
    {
        [$memberId, $userId] = $this->createMember();
        $service = app(ContributionAdjustmentService::class);

        $service->create($memberId, 25000, 'other', 'Corrected missed contribution', $userId);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $this->assertSame(25000, $overview['cash_received_minor']);
        $this->assertSame(25000, (int) round((float) DB::table('members')->where('id', $memberId)->value('total_contributions') * 100));

        $statement = app(FinancialReadModel::class)->statement($memberId);
        $this->assertSame('Corrected missed contribution', $statement[0]['adjustment_reason'] ?? '');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $userId,
            'type' => 'contribution_adjustment',
        ]);

        $service->create($memberId, -5000, 'rent_misclassification', 'Entry reversed as rent', $userId);

        $overview = app(FinancialReadModel::class)->memberOverview($memberId);
        $this->assertSame(20000, $overview['cash_received_minor']);
        $this->assertSame(2, DB::table('contribution_adjustments')->where('member_id', $memberId)->count());
    }
}
