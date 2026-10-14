<?php

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteContactMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_form_persists_a_valid_enquiry(): void
    {
        $this->postJson('/api/public/website/contact', [
            'name' => 'Jane Wanjiku',
            'email' => 'Jane@example.com',
            'topic' => 'membership',
            'message' => 'I would like to learn more about joining the welfare.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Your message has been sent.');

        $this->assertDatabaseHas('website_contact_messages', [
            'name' => 'Jane Wanjiku',
            'email' => 'jane@example.com',
            'topic' => 'membership',
            'status' => 'new',
        ]);
    }

    public function test_contact_form_rejects_invalid_topics_and_honeypot_submissions(): void
    {
        $payload = [
            'name' => 'Jane Wanjiku',
            'email' => 'jane@example.com',
            'topic' => 'private-financial-data',
            'message' => 'I would like to learn more about joining the welfare.',
        ];

        $this->postJson('/api/public/website/contact', $payload)->assertUnprocessable();
        $payload['topic'] = 'membership';
        $payload['website'] = 'spam.example';
        $this->postJson('/api/public/website/contact', $payload)->assertUnprocessable();

        $this->assertDatabaseCount('website_contact_messages', 0);
    }

    public function test_only_admins_can_read_and_update_contact_messages(): void
    {
        $adminId = Uuid::v4();
        DB::table('auth_users')->insert([
            'id' => $adminId,
            'email' => "{$adminId}@welfare.local",
            'phone' => '+254700' . random_int(1000000, 9999999),
            'encrypted_password' => bcrypt('Password123!'),
            'must_change_password' => 0,
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $adminId,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        $token = JwtService::encode(JwtService::buildPayload($adminId, "{$adminId}@welfare.local", ['admin']));
        $messageId = DB::table('website_contact_messages')->insertGetId([
            'name' => 'Jane Wanjiku',
            'email' => 'jane@example.com',
            'topic' => 'membership',
            'message' => 'Please send information about membership.',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/website/contact-messages')
            ->assertOk()
            ->assertJsonPath('data.0.id', $messageId);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/admin/website/contact-messages/{$messageId}/read")
            ->assertOk();

        $this->assertDatabaseHas('website_contact_messages', [
            'id' => $messageId,
            'status' => 'read',
            'read_by' => $adminId,
        ]);
    }
}
