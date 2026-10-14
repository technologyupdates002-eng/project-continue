<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsitePublicProfilesTest extends TestCase
{
    use RefreshDatabase;

    private function makeIdentity(array $roles, string $name = 'Existing KHWWC Member'): array
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        $phone = '+254700' . random_int(1000000, 9999999);

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
        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => $userId,
            'name' => $name,
            'phone' => $phone,
            'status' => 'active',
            'is_active' => 1,
            'profile_picture_url' => "/api/storage/v1/object/profile-images/{$userId}/profile.jpg",
        ]);

        return [
            'user_id' => $userId,
            'member_id' => $memberId,
            'email' => "{$userId}@welfare.local",
            'phone' => $phone,
            'roles' => $roles,
            'token' => JwtService::encode(JwtService::buildPayload($userId, "{$userId}@welfare.local", $roles)),
        ];
    }

    public function test_public_leadership_uses_published_existing_members_and_their_profile_images(): void
    {
        $leader = $this->makeIdentity(['admin', 'treasurer'], 'Jane Wanjiku');
        $hidden = $this->makeIdentity(['member'], 'Unpublished Member');

        DB::table('website_public_profiles')->insert([
            [
                'member_id' => $leader['member_id'],
                'slug' => 'jane-wanjiku',
                'title_prefix' => 'Dr.',
                'public_role' => 'National Treasurer',
                'short_bio' => 'Serving the welfare community.',
                'published' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'member_id' => $hidden['member_id'],
                'slug' => 'unpublished-member',
                'title_prefix' => null,
                'public_role' => 'Secretary',
                'short_bio' => null,
                'published' => false,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/public/website/leadership')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dr. Jane Wanjiku')
            ->assertJsonPath('data.0.role', 'National Treasurer')
            ->assertJsonPath('data.0.image_url', url("/api/storage/v1/object/profile-images/{$leader['user_id']}/profile.jpg"))
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.member_id')
            ->assertJsonMissingPath('data.0.phone');
    }

    public function test_admin_leadership_member_roster_includes_existing_officeholders_without_publishing_them(): void
    {
        $admin = $this->makeIdentity(['admin'], 'Website Administrator');
        $chairperson = $this->makeIdentity(['chairperson'], 'Existing Chairperson');
        $treasurer = $this->makeIdentity(['admin', 'treasurer'], 'Existing Treasurer');
        $ordinaryMember = $this->makeIdentity(['member'], 'Ordinary Member');

        DB::table('website_public_profiles')->insert([
            'member_id' => $treasurer['member_id'],
            'slug' => 'existing-treasurer',
            'public_role' => 'National Treasurer',
            'published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->getJson('/api/admin/website/people/leadership-members')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $response->assertJsonFragment([
            'member_id' => $admin['member_id'],
            'name' => 'Website Administrator',
            'roles' => ['admin'],
            'suggested_public_role' => 'Administrator',
            'profile' => null,
        ]);
        $response->assertJsonFragment([
            'member_id' => $chairperson['member_id'],
            'name' => 'Existing Chairperson',
            'roles' => ['chairperson'],
            'suggested_public_role' => 'Chairperson',
            'profile' => null,
        ]);
        $response->assertJsonFragment([
            'member_id' => $treasurer['member_id'],
            'name' => 'Existing Treasurer',
            'roles' => ['admin', 'treasurer'],
            'suggested_public_role' => 'Treasurer',
        ]);
        $this->assertDatabaseMissing('website_public_profiles', [
            'member_id' => $chairperson['member_id'],
        ]);
        $this->assertDatabaseMissing('website_public_profiles', [
            'member_id' => $ordinaryMember['member_id'],
        ]);
    }

    public function test_existing_khwwc_account_login_returns_the_same_identity_and_roles(): void
    {
        $admin = $this->makeIdentity(['admin', 'treasurer']);
        $userCount = DB::table('auth_users')->count();

        $this->postJson('/api/auth/login', [
            'phone' => $admin['phone'],
            'password' => 'Password123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $admin['user_id'])
            ->assertJsonPath('user.roles.0', 'admin')
            ->assertJsonFragment(['treasurer']);

        $this->assertSame($userCount, DB::table('auth_users')->count());
    }

    public function test_admin_can_configure_a_public_profile_for_an_existing_member(): void
    {
        $admin = $this->makeIdentity(['admin'], 'Website Administrator');
        $member = $this->makeIdentity(['member'], 'Jane Wanjiku');

        $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->postJson('/api/admin/website/people', [
                'member_id' => $member['member_id'],
                'title_prefix' => 'Dr.',
                'public_role' => 'National Treasurer',
                'short_bio' => 'Serving the welfare community.',
                'published' => true,
                'sort_order' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dr. Jane Wanjiku')
            ->assertJsonPath('data.title_prefix', 'Dr.')
            ->assertJsonPath('data.slug', 'jane-wanjiku')
            ->assertJsonPath('data.role', 'National Treasurer')
            ->assertJsonPath('data.published', true);

        $this->assertSame(2, DB::table('auth_users')->count());
        $this->assertDatabaseHas('website_public_profiles', [
            'member_id' => $member['member_id'],
            'created_by' => $admin['user_id'],
            'published' => 1,
        ]);

        $profileId = DB::table('website_public_profiles')->where('member_id', $member['member_id'])->value('id');
        $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->putJson("/api/admin/website/people/{$profileId}", [
                'member_id' => $member['member_id'],
                'title_prefix' => 'Dr.',
                'display_name' => 'Jane Wanjiku',
                'public_role' => 'Treasurer',
                'published' => true,
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.title_prefix', 'Dr.')
            ->assertJsonPath('data.name', 'Dr. Jane Wanjiku')
            ->assertJsonPath('data.slug', 'jane-wanjiku');
    }

    public function test_non_admin_cannot_access_website_management_api(): void
    {
        $member = $this->makeIdentity(['member']);

        $this->withHeader('Authorization', 'Bearer ' . $member['token'])
            ->getJson('/api/admin/website/people')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer ' . $member['token'])
            ->getJson('/api/admin/website/people/leadership-members')
            ->assertForbidden();
    }
}
