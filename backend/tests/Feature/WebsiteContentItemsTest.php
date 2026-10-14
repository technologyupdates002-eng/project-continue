<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteContentItemsTest extends TestCase
{
    use RefreshDatabase;

    private function identity(array $roles): array
    {
        $userId = Uuid::v4();
        $memberId = Uuid::v4();
        $email = "{$userId}@welfare.local";

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'phone' => '+2547' . random_int(10000000, 99999999),
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
            'name' => 'Website Content Administrator',
            'phone' => '+2547' . random_int(10000000, 99999999),
            'status' => 'active',
            'is_active' => 1,
        ]);

        return [
            'user_id' => $userId,
            'token' => JwtService::encode(JwtService::buildPayload($userId, $email, $roles)),
        ];
    }

    public function test_public_content_only_returns_published_items_of_the_requested_type(): void
    {
        DB::table('website_content_items')->insert([
            $this->contentItem('project', 'published-project', 'published', now()->subMinute()),
            $this->contentItem('project', 'future-project', 'published', now()->addDay()),
            $this->contentItem('project', 'draft-project', 'draft', null),
            $this->contentItem('project', 'archived-project', 'archived', null),
            $this->contentItem('article', 'published-article', 'published', now()->subMinute()),
            $this->contentItem('announcement', 'published-announcement', 'published', now()->subMinute()),
            $this->contentItem('announcement', 'future-announcement', 'published', now()->subMinute()),
        ]);
        DB::table('website_content_items')->where('slug', 'future-announcement')->update(['starts_at' => now()->addDay()]);

        $this->getJson('/api/public/website/content/project')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'published-project')
            ->assertJsonPath('data.0.content_type', 'project')
            ->assertJsonMissingPath('data.0.created_by')
            ->assertJsonMissingPath('data.0.status');

        $this->getJson('/api/public/website/content/project/future-project')
            ->assertNotFound();

        $this->getJson('/api/public/website/content/announcement')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'published-announcement');
    }

    public function test_admin_can_create_publish_and_archive_website_content(): void
    {
        $admin = $this->identity(['admin']);
        $payload = [
            'content_type' => 'article',
            'title' => 'Member welfare update',
            'status' => 'published',
            'category' => 'Community',
            'excerpt' => 'An approved public update.',
            'content' => 'Published public content.',
            'is_featured' => true,
            'sort_order' => 2,
            'image_url' => 'https://example.org/image.jpg',
            'image_alt' => 'A community gathering',
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->postJson('/api/admin/website/content', $payload)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'member-welfare-update')
            ->assertJsonPath('data.status', 'published');

        $itemId = $response->json('data.id');
        $this->assertDatabaseHas('website_content_items', [
            'id' => $itemId,
            'content_type' => 'article',
            'created_by' => $admin['user_id'],
        ]);
        $this->getJson('/api/public/website/content/article/member-welfare-update')->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->postJson("/api/admin/website/content/{$itemId}/archive")
            ->assertOk();
        $this->getJson('/api/public/website/content/article/member-welfare-update')->assertNotFound();
    }

    public function test_non_admin_cannot_manage_content_and_unsafe_urls_are_rejected(): void
    {
        $member = $this->identity(['member']);
        $headers = ['Authorization' => 'Bearer ' . $member['token']];
        $payload = [
            'content_type' => 'resource',
            'title' => 'Public resource',
            'status' => 'published',
            'resource_url' => 'javascript:alert(1)',
        ];

        $this->withHeaders($headers)->postJson('/api/admin/website/content', $payload)->assertForbidden();

        $admin = $this->identity(['admin']);
        $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->postJson('/api/admin/website/content', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('resource_url');
    }

    public function test_website_dashboard_returns_live_admin_only_content_and_contact_totals(): void
    {
        $admin = $this->identity(['admin']);
        $now = now();

        DB::table('website_content_items')->insert([
            $this->contentItem('page', 'dashboard-published-page', 'published', $now->copy()->subMinute()),
            $this->contentItem('article', 'dashboard-draft-article', 'draft', null),
            $this->contentItem('event', 'dashboard-upcoming-event', 'published', $now->copy()->subMinute()),
        ]);
        DB::table('website_content_items')
            ->where('slug', 'dashboard-upcoming-event')
            ->update(['starts_at' => $now->copy()->addWeek()]);
        DB::table('website_public_profiles')->insert([
            'member_id' => DB::table('members')->where('user_id', $admin['user_id'])->value('id'),
            'slug' => 'dashboard-admin-profile',
            'public_role' => 'Administrator',
            'published' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('website_contact_messages')->insert([
            'name' => 'Website visitor',
            'email' => 'visitor@example.org',
            'topic' => 'general',
            'message' => 'A new enquiry.',
            'status' => 'new',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->getJson('/api/admin/website/dashboard')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $admin['token'])
            ->getJson('/api/admin/website/dashboard')
            ->assertOk()
            ->assertJsonPath('data.published_by_type.page', 1)
            ->assertJsonPath('data.published_by_type.event', 1)
            ->assertJsonPath('data.draft_content', 1)
            ->assertJsonPath('data.published_profiles', 1)
            ->assertJsonPath('data.unpublished_profiles', 0)
            ->assertJsonPath('data.new_contact_messages', 1)
            ->assertJsonPath('data.upcoming_events', 1);

        $member = $this->identity(['member']);
        $this->withHeader('Authorization', 'Bearer ' . $member['token'])
            ->getJson('/api/admin/website/dashboard')
            ->assertForbidden();
    }

    private function contentItem(string $type, string $slug, string $status, mixed $publishedAt): array
    {
        return [
            'content_type' => $type,
            'slug' => $slug,
            'title' => $slug,
            'status' => $status,
            'published_at' => $publishedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
