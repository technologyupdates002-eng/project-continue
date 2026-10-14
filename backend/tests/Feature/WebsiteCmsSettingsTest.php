<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteCmsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function identity(array $roles): array
    {
        $userId = Uuid::v4();
        $email = "{$userId}@welfare.local";
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'phone' => '+2547'.random_int(10000000, 99999999),
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
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'name' => 'Website CMS test user',
            'phone' => '+2547'.random_int(10000000, 99999999),
            'status' => 'active',
            'is_active' => 1,
        ]);

        return [
            'user_id' => $userId,
            'token' => JwtService::encode(JwtService::buildPayload($userId, $email, $roles)),
        ];
    }

    public function test_public_settings_use_admin_saved_values_without_exposing_email_configuration(): void
    {
        $admin = $this->identity(['admin']);
        $headers = ['Authorization' => 'Bearer '.$admin['token']];

        $emptySettings = $this->getJson('/api/public/website/settings')->assertOk();
        $this->assertStringContainsString('"data":{}', $emptySettings->getContent());

        $this->withHeaders($headers)->putJson('/api/admin/website/settings/navigation', [
            'items' => [
                ['label' => 'About', 'page' => 'about'],
                ['label' => 'Contact', 'page' => 'contact'],
            ],
        ])->assertOk()->assertJsonPath('data.items.0.page', 'about');

        $this->withHeaders($headers)->putJson('/api/admin/website/settings/email', [
            'enabled' => false,
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => 'production-mail-password',
            'encryption' => 'tls',
            'from_email' => '',
            'from_name' => 'KHCWW',
        ])->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.smtp_password');

        $stored = DB::table('website_cms_settings')->where('setting_key', 'email')->value('setting_value');
        $this->assertStringNotContainsString('production-mail-password', $stored);
        $this->assertSame('production-mail-password', Crypt::decryptString(json_decode($stored, true)['smtp_password']));

        $this->getJson('/api/public/website/settings')
            ->assertOk()
            ->assertJsonPath('data.navigation.items.0.page', 'about')
            ->assertJsonMissingPath('data.email');
    }

    public function test_website_settings_and_media_library_are_admin_only(): void
    {
        $this->getJson('/api/admin/website/settings/site')->assertUnauthorized();
        $member = $this->identity(['member']);
        $this->withHeader('Authorization', 'Bearer '.$member['token'])
            ->getJson('/api/admin/website/media')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$member['token'])
            ->postJson('/api/storage/v1/object/website-media/member-upload.png')
            ->assertForbidden();

        $admin = $this->identity(['admin']);
        DB::table('storage_objects')->insert([
            'id' => Uuid::v4(),
            'bucket' => 'website-media',
            'object_path' => 'cms-header.webp',
            'mime_type' => 'image/webp',
            'size_bytes' => 2048,
            'is_public' => 1,
            'owner_id' => $admin['user_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withHeader('Authorization', 'Bearer '.$admin['token'])
            ->getJson('/api/admin/website/media')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.object_path', 'cms-header.webp')
            ->assertJsonPath('data.0.mime_type', 'image/webp');
    }

    public function test_admin_can_create_and_update_email_templates_and_disabled_smtp_is_not_used(): void
    {
        $admin = $this->identity(['admin']);
        $headers = ['Authorization' => 'Bearer '.$admin['token']];
        $payload = [
            'template_key' => 'cms-test',
            'name' => 'CMS email test',
            'subject' => 'KHCWW delivery check',
            'body' => 'Test sent to {{recipient}}',
            'enabled' => true,
        ];

        $this->withHeaders($headers)->postJson('/api/admin/website/email-templates', $payload)
            ->assertOk()
            ->assertJsonPath('data.template_key', 'cms-test');

        $this->withHeaders($headers)->postJson('/api/admin/website/email/test', [
            'to' => 'admin@example.org',
            'template_key' => 'cms-test',
        ])->assertUnprocessable();

        $payload['subject'] = 'Updated subject';
        $this->withHeaders($headers)->putJson('/api/admin/website/email-templates/cms-test', $payload)
            ->assertOk()
            ->assertJsonPath('data.subject', 'Updated subject');
    }

    public function test_navigation_rejects_unknown_page_targets(): void
    {
        $admin = $this->identity(['admin']);
        $this->withHeader('Authorization', 'Bearer '.$admin['token'])
            ->putJson('/api/admin/website/settings/navigation', [
                'items' => [['label' => 'Unsafe', 'page' => 'javascript:alert(1)']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.page');
    }
}
