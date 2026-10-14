<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Uuid;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatMessageStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_status_for_multiple_messages(): void
    {
        $userId = Uuid::v4();
        $email = $userId . '@welfare.local';
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'encrypted_password' => bcrypt('Password123!'),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'member',
            'is_active' => 1,
        ]);

        $messageIds = [Uuid::v4(), Uuid::v4()];
        foreach ($messageIds as $messageId) {
            DB::table('messages')->insert([
                'id' => $messageId,
                'user_id' => Uuid::v4(),
                'content' => 'Chat test',
                'status' => 'sent',
                'created_at' => now(),
            ]);
        }

        $token = JwtService::encode(JwtService::buildPayload($userId, $email, ['member']));
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/rest/v1/messages?id=in.(' . implode(',', $messageIds) . ')', [
                'status' => 'read',
            ])
            ->assertOk();

        $this->assertSame(
            2,
            DB::table('messages')->whereIn('id', $messageIds)->where('status', 'read')->count()
        );
    }

    public function test_creating_conversation_participant_preserves_fields_also_used_in_query_filters(): void
    {
        $userId = Uuid::v4();
        $email = $userId . '@welfare.local';
        DB::table('auth_users')->insert([
            'id' => $userId,
            'email' => $email,
            'encrypted_password' => bcrypt('Password123!'),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'role' => 'member',
            'is_active' => 1,
        ]);

        $conversationId = Uuid::v4();
        DB::table('conversations')->insert([
            'id' => $conversationId,
            'type' => 'private',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = JwtService::encode(JwtService::buildPayload($userId, $email, ['member']));
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/rest/v1/conversation_participants?conversation_id=eq.' . $conversationId, [[
                'conversation_id' => $conversationId,
                'user_id' => $userId,
            ]])
            ->assertCreated()
            ->assertJsonPath('0.conversation_id', $conversationId)
            ->assertJsonPath('0.user_id', $userId);

        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
        ]);
    }
}
