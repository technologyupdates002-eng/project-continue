<?php

namespace Tests\Feature;

use App\Models\AuthUser;
use App\Models\Member;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberStatsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $roles, ?string $name = null): array
    {
        static $counter = 0;
        $counter++;
        $userId = Uuid::v4();
        $phone = '+254700000' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
        $memberName = $name ?? 'Test Member ' . $counter;

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => "{$userId}@welfare.local",
            'phone' => $phone,
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        foreach ($roles as $role) {
            DB::table('user_roles')->insert([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'role' => $role,
                'is_active' => 1,
            ]);
        }
        $memberId = Uuid::v4();
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => $memberName,
            'phone' => $phone,
            'status' => 'active',
            'is_active' => 1,
        ]);
        return ['user_id' => $userId, 'member_id' => $memberId, 'roles' => $roles];
    }

    private function tokenFor(array $user): string
    {
        return JwtService::encode(JwtService::buildPayload(
            $user['user_id'],
            "{$user['user_id']}@welfare.local",
            $user['roles']
        ));
    }

    public function test_member_can_access_own_statement(): void
    {
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($member);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members/stats/statement?member_id=' . $member['member_id']);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_member_cannot_access_another_member_statement(): void
    {
        $memberA = $this->makeUser(['member'], 'Member A');
        $memberB = $this->makeUser(['member'], 'Member B');
        $tokenA = $this->tokenFor($memberA);

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/api/members/stats/statement?member_id=' . $memberB['member_id']);

        $response->assertStatus(403);
    }

    public function test_member_cannot_access_another_member_stats(): void
    {
        $memberA = $this->makeUser(['member'], 'Member A');
        $memberB = $this->makeUser(['member'], 'Member B');
        $tokenA = $this->tokenFor($memberA);

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenA)
            ->getJson('/api/members/stats?member_id=' . $memberB['member_id']);

        $response->assertStatus(403);
    }

    public function test_admin_can_access_any_member_statement(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member'], 'Target Member');
        $adminToken = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/members/stats/statement?member_id=' . $member['member_id']);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_admin_can_access_any_member_stats(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member'], 'Target Member');
        $adminToken = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', 'Bearer ' . $adminToken)
            ->getJson('/api/members/stats?member_id=' . $member['member_id']);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_staff_can_access_any_member_statement(): void
    {
        $staff = $this->makeUser(['treasurer']);
        $member = $this->makeUser(['member'], 'Target Member');
        $staffToken = $this->tokenFor($staff);

        $response = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->getJson('/api/members/stats/statement?member_id=' . $member['member_id']);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_unauthorized_member_gets_own_stats_when_no_member_id(): void
    {
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($member);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members/stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_unauthorized_member_gets_own_statement_when_no_member_id(): void
    {
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($member);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members/stats/statement');

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $member['member_id']);
    }

    public function test_member_without_member_record_gets_null(): void
    {
        $userId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => "{$userId}@welfare.local",
            'phone' => '+2547000000999',
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'member',
            'is_active' => 1,
        ]);
        $token = JwtService::encode(JwtService::buildPayload($userId, "{$userId}@welfare.local", ['member']));

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/members/stats/statement');

        $response->assertStatus(400)
            ->assertJsonPath('error', 'member_id is required');
    }

    public function test_staff_gets_all_members_stats_without_member_id(): void
    {
        $staff = $this->makeUser(['treasurer']);
        $this->makeUser(['member'], 'Member 1');
        $this->makeUser(['member'], 'Member 2');
        $staffToken = $this->tokenFor($staff);

        $response = $this->withHeader('Authorization', 'Bearer ' . $staffToken)
            ->getJson('/api/members/stats');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(3, $data);
    }
}
