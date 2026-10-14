<?php

namespace Tests\Unit;

use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_normalize_phone_handles_various_formats(): void
    {
        $this->assertSame('+254712345678', AuthService::normalizePhone('0712345678'));
        $this->assertSame('+254712345678', AuthService::normalizePhone('712345678'));
        $this->assertSame('+254712345678', AuthService::normalizePhone('254712345678'));
        $this->assertSame('+254712345678', AuthService::normalizePhone('+254712345678'));
        $this->assertSame('+254712345678', AuthService::normalizePhone('0712 345 678'));
    }

    public function test_email_for_phone_excludes_plus_prefix(): void
    {
        $this->assertSame(
            '254712345678@welfare.local',
            AuthService::emailForPhone('0712345678')
        );
    }

    public function test_role_helpers(): void
    {
        $this->assertTrue(AuthService::isStaff(['admin']));
        $this->assertTrue(AuthService::isStaff(['treasurer']));
        $this->assertFalse(AuthService::isStaff(['member']));
        $this->assertTrue(AuthService::isAdmin(['super_admin']));
        $this->assertTrue(AuthService::hasRole(['member', 'admin'], 'admin'));
        $this->assertFalse(AuthService::hasRole(['member'], 'admin'));
    }

    public function test_roles_for_super_admin_includes_admin_implicitly(): void
    {
        $userId = \App\Support\Uuid::v4();

        DB::table('auth_users')->where('email', 'test-super@welfare.local')->delete();
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'test-super@welfare.local',
            'encrypted_password' => password_hash('test', PASSWORD_BCRYPT),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->where('user_id', $userId)->delete();
        DB::table('user_roles')->insert([
            'id' => \App\Support\Uuid::v4(),
            'user_id' => $userId,
            'role' => 'super_admin',
            'is_active' => 1,
        ]);

        $roles = AuthService::rolesFor($userId);

        $this->assertIsArray($roles);
        $this->assertContains('super_admin', $roles);
        $this->assertContains('admin', $roles);
    }
}
