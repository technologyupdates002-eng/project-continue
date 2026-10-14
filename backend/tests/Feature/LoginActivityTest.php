<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginActivityTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ID = '11111111-1111-4111-8111-111111111111';
    private const MEMBER_USER_ID = '22222222-2222-4222-8222-222222222222';
    private const MEMBER_ID = '33333333-3333-4333-8333-333333333333';

    private function token(array $roles = ['super_admin', 'admin']): string
    {
        return JwtService::encode(JwtService::buildPayload(
            self::ADMIN_ID,
            'admin@welfare.local',
            $roles
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('members')->insert([
            'id' => self::MEMBER_ID,
            'user_id' => self::MEMBER_USER_ID,
            'name' => 'Jane Example',
            'phone' => '254711222333',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('login_activity')->insert([
            'id' => '44444444-4444-4444-8444-444444444444',
            'user_id' => self::MEMBER_USER_ID,
            'email' => 'jane@example.test',
            'phone' => '254711222333',
            'role' => 'member',
            'ip_address' => '192.0.2.1',
            'user_agent' => 'Mozilla/5.0',
            'event_type' => 'login',
            'success' => 1,
            'created_at' => now()->subDay(),
        ]);
        DB::table('login_activity')->insert([
            'id' => '55555555-5555-4555-8555-555555555555',
            'user_id' => null,
            'email' => 'unknown@example.test',
            'phone' => null,
            'role' => null,
            'ip_address' => '192.0.2.2',
            'user_agent' => 'Mozilla/5.0',
            'event_type' => 'login_failed',
            'success' => 0,
            'failure_reason' => 'Invalid login credentials',
            'created_at' => now(),
        ]);
    }

    public function test_activity_lists_member_name_and_supports_search_and_pagination(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/admin/presence/login-activity?search=Jane&limit=1&offset=0');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('successful', 1)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('offset', 0)
            ->assertJsonPath('data.0.member_name', 'Jane Example')
            ->assertJsonPath('data.0.email', 'jane@example.test');
    }

    public function test_activity_filters_failed_attempts_and_validates_pagination(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/admin/presence/login-activity?success=false&event_type=login_failed');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.event_type', 'login_failed')
            ->assertJsonPath('data.0.success', 0);

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/admin/presence/login-activity?limit=1000')
            ->assertUnprocessable();
    }

    public function test_activity_is_not_available_to_member_accounts(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token(['member']))
            ->getJson('/api/admin/presence/login-activity')
            ->assertForbidden();
    }

    public function test_admin_can_access_super_admin_login_activity(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token(['admin']))
            ->getJson('/api/admin/presence/login-activity')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_activity_includes_legacy_last_sign_in_when_no_audit_event_exists(): void
    {
        DB::table('auth_users')->insert([
            'id' => '66666666-6666-4666-8666-666666666666',
            'email' => 'legacy@example.test',
            'phone' => '254722333444',
            'encrypted_password' => password_hash('test', PASSWORD_BCRYPT),
            'last_sign_in_at' => now()->subDays(7)->toDateTimeString(),
            'created_at' => now()->subYear(),
            'updated_at' => now()->subDays(7),
        ]);

        DB::table('members')->insert([
            'id' => '77777777-7777-4777-8777-777777777777',
            'user_id' => '66666666-6666-4666-8666-666666666666',
            'name' => 'Legacy Member',
            'phone' => '254722333444',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subDays(7),
        ]);

        $from = now()->subDays(8)->toDateString();
        $to = now()->subDays(6)->toDateString();
        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson("/api/admin/presence/login-activity?event_type=historical_login&from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.event_type', 'historical_login')
            ->assertJsonPath('data.0.member_name', 'Legacy Member')
            ->assertJsonPath('data.0.ip_address', null);
    }

    public function test_online_users_are_derived_from_recent_last_seen_and_return_primary_role(): void
    {
        $userId = '88888888-8888-4888-8888-888888888888';
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'online@example.test',
            'phone' => '254733444555',
            'encrypted_password' => password_hash('test', PASSWORD_BCRYPT),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('members')->insert([
            'id' => '99999999-9999-4999-8999-999999999999',
            'user_id' => $userId,
            'name' => 'Online Executive',
            'phone' => '254733444555',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'user_id' => $userId, 'role' => 'admin', 'is_active' => 1],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'user_id' => $userId, 'role' => 'super_admin', 'is_active' => 1],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/admin/presence/online-users?status=online')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $userId)
            ->assertJsonPath('data.0.name', 'Online Executive')
            ->assertJsonPath('data.0.role', 'super_admin')
            ->assertJsonPath('data.0.is_online', true)
            ->assertJsonPath('online_count', 1)
            ->assertJsonPath('offline_count', 0)
            ->assertJsonPath('total_count', 1);
    }

    public function test_member_status_uses_the_configured_presence_expiry(): void
    {
        Config::set('presence.online_threshold_seconds', 30);
        DB::table('user_presence')->insert([
            'id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'user_id' => self::MEMBER_USER_ID,
            'is_online' => 1,
            'last_seen' => now()->subSeconds(60),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/admin/presence/members')
            ->assertOk()
            ->assertJsonPath('data.0.member_id', self::MEMBER_ID)
            ->assertJsonPath('data.0.is_online', false);
    }
}
