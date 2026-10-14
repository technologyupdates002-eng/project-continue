<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdminToken(): string
    {
        return JwtService::encode(JwtService::buildPayload(
            '11111111-1111-4111-8111-111111111111',
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));
    }

    public function test_me_requires_token(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401)
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('error.code', 'invalid_token')
                ->etc());
    }

    public function test_me_returns_identity_with_valid_token(): void
    {
        $token = $this->superAdminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('identity.user_id', '11111111-1111-4111-8111-111111111111')
            ->assertJsonPath('identity.roles', ['super_admin', 'admin']);
    }

    public function test_me_rejects_garbage_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer not-a-token')
            ->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_me_rejects_idle_timeout(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        DB::table('auth_users')->updateOrInsert(
            ['id' => $userId],
            ['email' => '254700000000@welfare.local', 'encrypted_password' => password_hash('test', PASSWORD_BCRYPT), 'last_seen_at' => now()->subMinutes(11)]
        );

        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $response->assertStatus(401)
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('error.code', 'session_idle_timeout')
                ->where('error.message', 'Session expired due to inactivity.')
                ->etc());
    }

    public function test_me_allows_recent_activity(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        DB::table('auth_users')->updateOrInsert(
            ['id' => $userId],
            ['email' => '254700000000@welfare.local', 'encrypted_password' => password_hash('test', PASSWORD_BCRYPT), 'last_seen_at' => now()->subMinutes(2)]
        );

        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me');

        $response->assertStatus(200);
    }

    public function test_heartbeat_with_activity_updates_last_seen(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        DB::table('auth_users')->updateOrInsert(
            ['id' => $userId],
            ['email' => '254700000000@welfare.local', 'encrypted_password' => password_hash('test', PASSWORD_BCRYPT), 'last_seen_at' => now()->subMinutes(5)]
        );

        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/v1/heartbeat', ['activity' => true]);

        $response->assertStatus(200);

        $lastSeen = DB::table('auth_users')->where('id', $userId)->value('last_seen_at');
        $this->assertNotNull($lastSeen);
        $this->assertGreaterThan(now()->subMinute(), new \DateTime($lastSeen));

        $presence = DB::table('user_presence')->where('user_id', $userId)->first();
        $this->assertNotNull($presence);
        $this->assertSame(1, (int) $presence->is_online);
        $this->assertGreaterThan(now()->subMinute(), new \DateTime($presence->last_seen));
    }

    public function test_heartbeat_without_activity_does_not_update_last_seen(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        $oldLastSeen = now()->subMinutes(5)->format('Y-m-d H:i:s');
        DB::table('auth_users')->updateOrInsert(
            ['id' => $userId],
            ['email' => '254700000000@welfare.local', 'encrypted_password' => password_hash('test', PASSWORD_BCRYPT), 'last_seen_at' => $oldLastSeen]
        );

        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/v1/heartbeat', ['activity' => false]);

        $response->assertStatus(200);

        $lastSeen = DB::table('auth_users')->where('id', $userId)->value('last_seen_at');
        $this->assertEquals($oldLastSeen, $lastSeen);

        $presence = DB::table('user_presence')->where('user_id', $userId)->first();
        $this->assertNotNull($presence);
        $this->assertSame(1, (int) $presence->is_online);
        $this->assertGreaterThan(now()->subMinute(), new \DateTime($presence->last_seen));
    }

    public function test_periodic_heartbeat_without_payload_refreshes_existing_presence_once(): void
    {
        $userId = '11111111-1111-4111-8111-111111111111';
        $oldLastSeen = now()->subMinutes(5)->format('Y-m-d H:i:s');
        DB::table('auth_users')->updateOrInsert(
            ['id' => $userId],
            ['email' => '254700000000@welfare.local', 'encrypted_password' => password_hash('test', PASSWORD_BCRYPT), 'last_seen_at' => $oldLastSeen]
        );
        DB::table('user_presence')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'is_online' => 1,
            'last_seen' => $oldLastSeen,
        ]);

        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            '254700000000@welfare.local',
            ['super_admin', 'admin']
        ));
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/v1/heartbeat')
            ->assertOk();
        $this->postJson('/api/auth/v1/heartbeat')->assertOk();

        $this->assertSame(
            1,
            DB::table('user_presence')->where('user_id', $userId)->count()
        );
        $presence = DB::table('user_presence')->where('user_id', $userId)->first();
        $this->assertGreaterThan(now()->subMinute(), new \DateTime($presence->last_seen));
        $this->assertSame($oldLastSeen, DB::table('auth_users')->where('id', $userId)->value('last_seen_at'));
    }

    public function test_member_password_change_requires_current_password_and_updates_credentials(): void
    {
        $userId = '22222222-2222-4222-8222-222222222222';
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'member-password@test.local',
            'encrypted_password' => password_hash('Current123', PASSWORD_BCRYPT),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $token = JwtService::encode(JwtService::buildPayload(
            $userId,
            'member-password@test.local',
            ['member']
        ));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/v1/password', ['new_password' => 'Changed123'])
            ->assertUnprocessable();
        $this->postJson('/api/auth/v1/password', [
            'current_password' => 'Current123',
            'new_password' => 'Changed123',
        ])->assertOk()->assertJsonPath('success', true);

        $updated = DB::table('auth_users')->where('id', $userId)->first();
        $this->assertSame(0, (int) $updated->must_change_password);
        $this->assertTrue(password_verify('Changed123', $updated->encrypted_password));
    }

    public function test_super_admin_can_reset_member_or_admin_password_with_audited_forced_change(): void
    {
        $targetId = '33333333-3333-4333-8333-333333333333';
        DB::table('auth_users')->insert([
            'id' => $targetId,
            'email' => 'admin-password@test.local',
            'phone' => '+254700000003',
            'encrypted_password' => password_hash('OldPass123', PASSWORD_BCRYPT),
            'must_change_password' => 0,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $targetId,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        DB::table('auth_refresh_tokens')->insert([
            'id' => Uuid::v4(),
            'user_id' => $targetId,
            'token' => 'refresh-token-for-reset-target',
            'revoked' => 0,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);
        $token = $this->superAdminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/functions/v1/rpc/admin_reset_password', [
                'target_user_id' => $targetId,
                'new_password' => 'NewAdminPass123',
                'reason' => 'Account recovery requested by the account holder.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('must_change_password', true);

        $updated = DB::table('auth_users')->where('id', $targetId)->first();
        $this->assertTrue(password_verify('NewAdminPass123', $updated->encrypted_password));
        $this->assertSame(1, (int) $updated->must_change_password);
        $this->assertSame(1, (int) DB::table('auth_refresh_tokens')->where('user_id', $targetId)->value('revoked'));
        $this->assertDatabaseHas('password_resets', [
            'user_id' => $targetId,
            'reset_by' => '11111111-1111-4111-8111-111111111111',
            'reason' => 'Account recovery requested by the account holder.',
        ]);
        $this->assertDatabaseHas('login_activity', [
            'user_id' => $targetId,
            'event_type' => 'admin_password_reset',
            'success' => 1,
        ]);
    }

    public function test_super_admin_password_user_list_is_authorized_and_never_returns_password_hashes(): void
    {
        $targetId = '44444444-4444-4444-8444-444444444444';
        DB::table('auth_users')->insert([
            'id' => $targetId,
            'email' => 'listed-member@test.local',
            'encrypted_password' => password_hash('Secret123', PASSWORD_BCRYPT),
            'raw_user_meta_data' => json_encode(['name' => 'Listed Member']),
            'must_change_password' => 1,
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $targetId,
            'role' => 'member',
            'is_active' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->superAdminToken())
            ->getJson('/api/admin/password-management/users')
            ->assertOk()
            ->assertJsonFragment([
                'user_id' => $targetId,
                'name' => 'Listed Member',
                'role' => 'member',
                'must_change_password' => true,
            ])
            ->assertJsonMissingPath('0.encrypted_password');

        $memberToken = JwtService::encode(JwtService::buildPayload(
            $targetId,
            'listed-member@test.local',
            ['member']
        ));
        $this->withHeader('Authorization', "Bearer {$memberToken}")
            ->getJson('/api/admin/password-management/users')
            ->assertForbidden();
    }

    public function test_admin_can_list_accounts_and_reset_password_with_audit(): void
    {
        $memberId = '77777777-7777-4777-8777-777777777777';
        DB::table('auth_users')->insert([
            'id' => $memberId,
            'email' => 'ordinary-member@test.local',
            'encrypted_password' => password_hash('MemberSecret123', PASSWORD_BCRYPT),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $memberId,
            'role' => 'member',
            'is_active' => 1,
        ]);
        $memberToken = JwtService::encode(JwtService::buildPayload(
            $memberId,
            'ordinary-member@test.local',
            ['member']
        ));
        $this->withHeader('Authorization', 'Bearer '.$memberToken)
            ->postJson('/api/functions/v1/rpc/admin_reset_password', [
                'target_user_id' => $memberId,
                'new_password' => 'UnauthorizedReset123',
                'reason' => 'A member must not reset passwords.',
            ])->assertForbidden();

        $adminId = '55555555-5555-4555-8555-555555555555';
        $targetId = '66666666-6666-4666-8666-666666666666';
        $superAdminTargetId = '88888888-8888-4888-8888-888888888888';
        DB::table('auth_users')->insert([
            [
                'id' => $adminId,
                'email' => 'admin-password-manager@test.local',
                'encrypted_password' => password_hash('AdminSecret123', PASSWORD_BCRYPT),
                'must_change_password' => 0,
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $targetId,
                'email' => 'reset-target@test.local',
                'encrypted_password' => password_hash('TargetSecret123', PASSWORD_BCRYPT),
                'must_change_password' => 0,
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $superAdminTargetId,
                'email' => 'super-admin-target@test.local',
                'encrypted_password' => password_hash('SuperAdminSecret123', PASSWORD_BCRYPT),
                'must_change_password' => 0,
                'is_banned' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('user_roles')->insert([
            [
                'id' => Uuid::v4(),
                'user_id' => $adminId,
                'role' => 'admin',
                'is_active' => 1,
            ],
            [
                'id' => Uuid::v4(),
                'user_id' => $superAdminTargetId,
                'role' => 'super_admin',
                'is_active' => 1,
            ],
        ]);

        $adminToken = JwtService::encode(JwtService::buildPayload(
            $adminId,
            'admin-password-manager@test.local',
            ['admin']
        ));

        $listedUsers = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/admin/password-management/users')
            ->assertOk()
            ->assertJsonFragment(['user_id' => $targetId])
            ->json();
        $this->assertFalse(collect($listedUsers)->contains(
            fn (array $listedUser): bool => $listedUser['user_id'] === $superAdminTargetId
        ));

        $this->postJson('/api/functions/v1/rpc/admin_reset_password', [
            'target_user_id' => $superAdminTargetId,
            'new_password' => 'AdminMustNotReset123',
            'reason' => 'An admin must not reset a super-admin.',
        ])->assertForbidden();

        $this->postJson('/api/functions/v1/rpc/admin_reset_password', [
            'target_user_id' => $targetId,
            'new_password' => 'AdminResetPassword123',
            'reason' => 'Account recovery approved by an administrator.',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('must_change_password', true);

        $updated = DB::table('auth_users')->where('id', $targetId)->first();
        $this->assertTrue(password_verify('AdminResetPassword123', $updated->encrypted_password));
        $this->assertSame(1, (int) $updated->must_change_password);
        $this->assertDatabaseHas('password_resets', [
            'user_id' => $targetId,
            'reset_by' => $adminId,
            'reason' => 'Account recovery approved by an administrator.',
        ]);
    }
}
