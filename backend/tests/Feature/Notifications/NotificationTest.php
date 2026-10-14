<?php

namespace Tests\Feature\Notifications;

use App\Services\AdminNotificationService;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    private function authHeader(?string &$userId = null): array
    {
        $userId = Uuid::v4();

        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => 'test-notif-'.Uuid::v4().'@welfare.local',
            'encrypted_password' => password_hash('password', PASSWORD_BCRYPT),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'admin',
            'is_active' => 1,
        ]);

        $token = \App\Services\AuthService::issueSession([
            'id' => $userId,
            'email' => DB::table('auth_users')->where('id', $userId)->value('email'),
        ])['access_token'];

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_patch_query_filters_are_not_written_to_notifications(): void
    {
        $userId = null;
        $headers = $this->authHeader($userId);
        $notificationId = Uuid::v4();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'user_id' => $userId,
            'title' => 'Test notification',
            'message' => 'Notification body',
            'type' => 'info',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $this->patchJson(
            '/api/rest/v1/notifications?user_id=eq.'.$userId.'&is_read=eq.false',
            ['is_read' => true],
            $headers
        )->assertOk();

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'user_id' => $userId,
            'is_read' => 1,
        ]);
    }

    public function test_bulk_sms_requires_auth(): void
    {
        $response = $this->postJson('/api/notifications/bulk-sms', [
            'phones' => ['254712345678'],
            'message' => 'Test',
        ]);

        $response->assertStatus(401);
    }

    public function test_bulk_sms_returns_success_for_valid_input(): void
    {
        $headers = $this->authHeader();

        $response = $this->postJson('/api/notifications/bulk-sms', [
            'phones' => ['254712345678', '254723456789'],
            'message' => 'Test SMS message',
        ], $headers);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'sent', 'failed', 'message'])
            ->assertJson(['success' => true]);
    }

    public function test_push_notification_requires_auth(): void
    {
        $response = $this->postJson('/api/notifications/push', [
            'title' => 'Test',
            'body' => 'Test body',
            'tokens' => ['token123'],
        ]);

        $response->assertStatus(401);
    }

    public function test_push_notification_returns_success_for_valid_input(): void
    {
        $headers = $this->authHeader();

        $response = $this->postJson('/api/notifications/push', [
            'title' => 'Test Push',
            'body' => 'Test push body',
            'tokens' => ['firebase-token-1234567890'],
        ], $headers);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'sent', 'message'])
            ->assertJson(['success' => true]);
    }

    public function test_admin_event_notifications_are_idempotent_per_recipient(): void
    {
        $headers = $this->authHeader();
        $eventKey = 'test-event:' . Uuid::v4();
        $storedEventKey = hash('sha256', $eventKey);
        $service = app(AdminNotificationService::class);

        $service->notifyAdmins('Test event', 'A test event occurred.', 'test_event', '/admin', $eventKey);
        $firstCount = DB::table('notifications')->where('event_key', $storedEventKey)->count();

        $this->assertGreaterThan(0, $firstCount);

        $service->notifyAdmins('Test event', 'A test event occurred.', 'test_event', '/admin', $eventKey);
        $this->assertSame($firstCount, DB::table('notifications')->where('event_key', $storedEventKey)->count());

        $inbox = $this->getJson('/api/admin/notifications/inbox', $headers);
        $inbox->assertOk()
            ->assertJsonPath('data.0.title', 'Test event')
            ->assertJsonPath('data.0.target_url', '/admin')
            ->assertJsonPath('meta.unread_count', 1);

        $notificationId = $inbox->json('data.0.id');
        $this->postJson('/api/admin/notifications/' . $notificationId . '/read', [], $headers)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->getJson('/api/admin/notifications/inbox', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.is_read', 1)
            ->assertJsonPath('meta.unread_count', 0);
    }
}
