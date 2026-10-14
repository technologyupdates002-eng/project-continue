<?php

namespace Tests\Feature;

use App\Models\AuthUser;
use App\Models\Member;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewAsMemberTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $roles): array
    {
        static $counter = 0;
        $counter++;
        $userId = Uuid::v4();
        $phone = '+254700000' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
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
            'name' => 'Test Member ' . $counter,
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

    public function test_unauthorized_role_receives_403(): void
    {
        $member = $this->makeUser(['member']);
        $treasurer = $this->makeUser(['treasurer']);
        $token = $this->tokenFor($treasurer);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->getJson('/api/rest/v1/members?id=eq.' . $member['member_id']);

        $response->assertStatus(403);
    }

    public function test_member_cannot_activate_view_mode(): void
    {
        $member = $this->makeUser(['member']);
        $other = $this->makeUser(['member']);
        $token = $this->tokenFor($member);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $other['member_id'])
            ->getJson('/api/rest/v1/members?id=eq.' . $other['member_id']);

        $response->assertStatus(403);
    }

    public function test_invalid_member_id_rejected(): void
    {
        $admin = $this->makeUser(['admin']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', 'not-a-uuid')
            ->getJson('/api/rest/v1/members');

        $response->assertStatus(400);
    }

    public function test_nonexistent_member_rejected(): void
    {
        $admin = $this->makeUser(['admin']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', Uuid::v4())
            ->getJson('/api/rest/v1/members');

        $response->assertStatus(404);
    }

    public function test_expired_or_invalid_jwt_rejected(): void
    {
        $admin = $this->makeUser(['admin']);

        $response = $this->withHeader('Authorization', 'Bearer not-a-token')
            ->withHeader('X-View-As-Member', $admin['member_id'])
            ->getJson('/api/rest/v1/members');

        $response->assertStatus(401);
    }

    public function test_admin_can_view_member(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->getJson('/api/rest/v1/members?id=eq.' . $member['member_id']);

        $response->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('0.id', $member['member_id'])
                ->etc());
    }

    public function test_client_member_id_swap_cannot_bypass_view_context(): void
    {
        $admin = $this->makeUser(['admin']);
        $memberA = $this->makeUser(['member']);
        $memberB = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        // Admin is viewing Member A. The client embeds a contributions query
        // that targets member_id=eq.B; the viewed-member context must be
        // authoritative and re-scope to Member A, never returning Member B's data.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $memberA['member_id'])
            ->getJson('/api/rest/v1/contributions?member_id=eq.' . $memberB['member_id']);

        $response->assertStatus(200);
        $rows = $response->json();
        $this->assertEmpty($rows, 'Client member_id swap must not return the other member.');
    }

    public function test_normal_member_request_rejected(): void
    {
        $member = $this->makeUser(['member']);
        $other = $this->makeUser(['member']);
        $token = $this->tokenFor($member);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $other['member_id'])
            ->getJson('/api/rest/v1/members');

        $response->assertStatus(403);
    }

    public function test_write_blocked_in_view_mode(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->postJson('/api/rest/v1/members', [
                'id' => Uuid::v4(),
                'user_id' => $member['user_id'],
                'name' => 'Attempted Write',
                'phone' => '+254700000000',
                'status' => 'active',
                'is_active' => 1,
            ]);

        $response->assertStatus(403);
    }

    public function test_payment_blocked_in_view_mode(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->postJson('/api/payments/stk-push', [
                'member_id' => $member['member_id'],
                'amount' => 100,
                'phone' => '+254700000000',
                'paymentType' => 'monthly_contribution',
            ]);

        $response->assertStatus(403);
    }

    public function test_wallet_write_blocked_in_view_mode(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->postJson('/api/wallets/penalty_wallet/credit', [
                'member_id' => $member['member_id'],
                'amount' => 100,
                'source' => 'test',
            ]);

        $response->assertStatus(403);
    }

    public function test_audit_log_records_start_event(): void
    {
        $admin = $this->makeUser(['admin']);
        $member = $this->makeUser(['member']);
        $token = $this->tokenFor($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-View-As-Member', $member['member_id'])
            ->getJson('/api/rest/v1/members?id=eq.' . $member['member_id'])
            ->assertStatus(200);

        $count = DB::table('audit_logs')
            ->where('action', 'ADMIN_STARTED_MEMBER_VIEW')
            ->where('target_member_id', $member['member_id'])
            ->where('super_admin_id', $admin['user_id'])
            ->count();

        $this->assertGreaterThanOrEqual(1, $count, 'ADMIN_STARTED_MEMBER_VIEW must be audited.');
    }
}
