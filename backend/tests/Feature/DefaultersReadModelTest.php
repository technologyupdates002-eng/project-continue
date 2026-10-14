<?php

namespace Tests\Feature;

use App\Services\FinancialReadModel;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefaultersReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaulters_endpoint_and_admin_summary_use_the_same_canonical_member_set(): void
    {
        $adminId = Uuid::v4();
        $adminEmail = "{$adminId}@welfare.local";
        DB::table('auth_users')->insert([
            'id' => $adminId,
            'email' => $adminEmail,
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $adminId,
            'role' => 'admin',
            'is_active' => 1,
        ]);

        $overdueMemberId = $this->createMember('Overdue obligation member');
        $penaltyMemberId = $this->createMember('Penalty-only member');
        $coveredMemberId = $this->createMember('Carry-forward covered member');
        DB::table('monthly_obligations')->insert([
            [
                'id' => Uuid::v4(),
                'member_id' => $overdueMemberId,
                'period_year' => now()->subMonths(4)->year,
                'period_month' => now()->subMonths(4)->month,
                'required_amount_minor' => 30000,
                'paid_amount_minor' => 10000,
                'status' => 'partial',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Uuid::v4(),
                'member_id' => $coveredMemberId,
                'period_year' => now()->subMonths(4)->year,
                'period_month' => now()->subMonths(4)->month,
                'required_amount_minor' => 30000,
                'paid_amount_minor' => 0,
                'status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('carry_forward_balances')->insert([
            'id' => Uuid::v4(),
            'member_id' => $coveredMemberId,
            'source_payment_id' => Uuid::v4(),
            'amount_minor' => 30000,
            'remaining_amount_minor' => 30000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('penalties')->insert([
            'id' => Uuid::v4(),
            'member_id' => $penaltyMemberId,
            'amount' => 80,
            'reason' => 'Late payment',
            'is_paid' => 0,
            'created_at' => now(),
        ]);

        $token = JwtService::encode(JwtService::buildPayload($adminId, $adminEmail, ['admin']));
        $defaulters = $this->withToken($token)->getJson('/api/members/stats/defaulters');
        $summary = $this->withToken($token)->getJson('/api/members/stats/summary');

        $defaulters->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [$overdueMemberId, $penaltyMemberId],
            array_column($defaulters->json('data'), 'id'),
        );
        $this->assertNotContains(
            $coveredMemberId,
            array_column($defaulters->json('data'), 'id'),
        );
        $summary->assertOk()->assertJsonPath('data.total_defaulters', 2);
        $this->assertSame(
            $summary->json('data.total_defaulters'),
            count(app(FinancialReadModel::class)->defaulters()),
        );
    }

    public function test_defaulters_read_uses_batched_queries_as_member_count_grows(): void
    {
        for ($index = 0; $index < 8; $index++) {
            $memberId = $this->createMember("Overdue member {$index}");
            DB::table('monthly_obligations')->insert([
                'id' => Uuid::v4(),
                'member_id' => $memberId,
                'period_year' => now()->subMonths(4)->year,
                'period_month' => now()->subMonths(4)->month,
                'required_amount_minor' => 30000,
                'paid_amount_minor' => 0,
                'status' => 'unpaid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $defaulters = app(FinancialReadModel::class)->defaulters();
            $queryCount = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertCount(8, $defaulters);
        $this->assertLessThanOrEqual(25, $queryCount);
    }

    private function createMember(string $name): string
    {
        $memberId = Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'name' => $name,
            'phone' => '+254700000' . random_int(1000, 9999),
            'member_id' => $memberId,
            'status' => 'active',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $memberId;
    }
}
