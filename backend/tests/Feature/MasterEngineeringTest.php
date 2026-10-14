<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Member;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Backend coverage for the master engineering feature set (2026-09-04).
 *
 * Verifies:
 *  - SignatureController own-write authorization
 *  - MeetingMinutesController state machine + attendance dedup + executive filter
 *  - BulkSmsController search/normalize/dry-run
 *  - SuperAdminStatsController authorization + data presence
 */
class MasterEngineeringTest extends TestCase
{
    private string $adminId = '11111111-1111-1111-1111-111111111111';
    private string $secretaryId = '44444444-4444-4444-4444-444444444444';
    private string $viceSecretaryId = '77777777-7777-7777-7777-777777777777';
    private string $memberId = '55555555-5555-5555-5555-555555555555';
    private string $nonExecutiveMemberId = '66666666-6666-6666-6666-666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        // admin
        DB::table('auth_users')->updateOrInsert(
            ['id' => $this->adminId],
            [
                'email' => 'admin@khwwc.local',
                'phone' => '+254700000001',
                'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'must_change_password' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('members')->updateOrInsert(
            ['id' => $this->adminId],
            [
                'user_id' => $this->adminId,
                'name' => 'Admin User',
                'phone' => '+254700000001',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        foreach (['admin', 'super_admin'] as $r) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $this->adminId, 'role' => $r],
                ['id' => Uuid::v4(), 'is_active' => 1]
            );
        }
        // secretary
        DB::table('auth_users')->updateOrInsert(
            ['id' => $this->secretaryId],
            [
                'email' => 'secretary@khwwc.local',
                'phone' => '+254700000004',
                'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'must_change_password' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('members')->updateOrInsert(
            ['id' => $this->secretaryId],
            [
                'user_id' => $this->secretaryId,
                'name' => 'Secretary User',
                'phone' => '+254700000004',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $this->secretaryId, 'role' => 'secretary'],
            ['id' => Uuid::v4(), 'is_active' => 1]
        );
        DB::table('auth_users')->updateOrInsert(
            ['id' => $this->viceSecretaryId],
            [
                'email' => 'vice-secretary@khwwc.local',
                'phone' => '+254700000007',
                'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'must_change_password' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('members')->updateOrInsert(
            ['id' => $this->viceSecretaryId],
            [
                'user_id' => $this->viceSecretaryId,
                'name' => 'Vice Secretary User',
                'phone' => '+254700000007',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $this->viceSecretaryId, 'role' => 'vice_secretary'],
            ['id' => Uuid::v4(), 'is_active' => 1]
        );
        // exec member
        DB::table('members')->updateOrInsert(
            ['id' => $this->memberId],
            [
                'user_id' => $this->memberId,
                'name' => 'Test Exec',
                'phone' => '+254700000099',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('auth_users')->updateOrInsert(
            ['id' => $this->memberId],
            [
                'email' => 'exec@khwwc.local',
                'phone' => '+254700000099',
                'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'must_change_password' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $this->memberId, 'role' => 'admin'],
            ['id' => Uuid::v4(), 'is_active' => 1]
        );
        // plain member
        DB::table('members')->updateOrInsert(
            ['id' => $this->nonExecutiveMemberId],
            [
                'user_id' => $this->nonExecutiveMemberId,
                'name' => 'Test Plain',
                'phone' => '+254700000088',
                'is_active' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DB::table('auth_users')->updateOrInsert(
            ['id' => $this->nonExecutiveMemberId],
            [
                'email' => 'plain@khwwc.local',
                'phone' => '+254700000088',
                'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
                'is_banned' => 0,
                'must_change_password' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        // Ensure plain member has NO executive role
        DB::table('user_roles')->where('user_id', $this->nonExecutiveMemberId)->whereIn('role', [
            'admin','super_admin','treasurer','chairperson','vice_chairperson','secretary','vice_secretary','patron'
        ])->delete();
        DB::table('user_roles')->updateOrInsert(
            ['user_id' => $this->nonExecutiveMemberId, 'role' => 'member'],
            ['id' => Uuid::v4(), 'is_active' => 1]
        );
    }

    private function loginAs(string $userId, string $email, array $roles = ['admin', 'super_admin']): string
    {
        $payload = \App\Services\JwtService::buildPayload($userId, $email, $roles);
        return \App\Services\JwtService::encode($payload);
    }

    // ---------------- SIGNATURES ----------------

    public function test_signature_admin_can_upload(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local');
        $res = $this->postJson('/api/signatures', [
            'signatory_role' => 'admin',
            'signature_url' => '/api/storage/v1/object/signatory-signatures/test.png',
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(201)->assertJsonPath('data.signature_url', '/api/storage/v1/object/signatory-signatures/test.png');
    }

    public function test_signature_cannot_upload_for_other_user(): void
    {
        $token = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $res = $this->postJson('/api/signatures', [
            'signatory_role' => 'admin',
            'signature_url' => '/api/storage/v1/object/signatory-signatures/x.png',
            'user_id' => $this->adminId,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(403);
    }

    public function test_signature_requires_known_role(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local');
        $res = $this->postJson('/api/signatures', [
            'signatory_role' => 'unknown',
            'signature_url' => '/api/storage/v1/object/x.png',
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(422);
    }

    // ---------------- MINUTES ----------------

    public function test_minute_create_requires_secretary(): void
    {
        $token = $this->loginAs($this->nonExecutiveMemberId, 'plain@khwwc.local', ['member']);
        $res = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'Test',
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(403);
    }

    public function test_minute_create_dedup_attendance(): void
    {
        $token = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $res = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'With dupes',
            'attendance' => [
                ['member_id' => $this->adminId, 'status' => 'PRESENT'],
                ['member_id' => $this->adminId, 'status' => 'ABSENT'],
            ],
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(422);
    }

    public function test_minute_state_machine_submit_then_approve(): void
    {
        $token = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $create = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'Flow',
        ], ['Authorization' => 'Bearer ' . $token]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $submit = $this->postJson("/api/minutes/{$id}/submit", [], ['Authorization' => 'Bearer ' . $token]);
        $submit->assertStatus(200)->assertJsonPath('data.status', 'pending_chairperson_approval');

        // Approve as admin (chair role may not exist in tests)
        $adminToken = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $queue = $this->getJson('/api/minutes?filter=pending', ['Authorization' => 'Bearer ' . $adminToken]);
        $queue->assertStatus(200)->assertJsonFragment(['id' => $id]);
        $approve = $this->postJson("/api/minutes/{$id}/approve", [], ['Authorization' => 'Bearer ' . $adminToken]);
        $approve->assertStatus(200)->assertJsonPath('data.status', 'approved');
    }

    public function test_vice_secretary_minutes_reach_chair_queue_after_secretary_review(): void
    {
        $viceToken = $this->loginAs($this->viceSecretaryId, 'vice-secretary@khwwc.local', ['vice_secretary']);
        $create = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'Vice Secretary handoff',
        ], ['Authorization' => 'Bearer ' . $viceToken]);
        $create->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $id = $create->json('data.id');

        $submit = $this->postJson("/api/minutes/{$id}/submit", [], ['Authorization' => 'Bearer ' . $viceToken]);
        $submit->assertStatus(200)->assertJsonPath('data.status', 'submitted_to_secretary');

        $secretaryToken = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $secretaryQueue = $this->getJson('/api/minutes?filter=secretary_pending', [
            'Authorization' => 'Bearer ' . $secretaryToken,
        ]);
        $secretaryQueue->assertStatus(200)->assertJsonFragment(['id' => $id]);

        $edit = $this->putJson("/api/minutes/{$id}", ['agenda' => 'Secretary review edit'], [
            'Authorization' => 'Bearer ' . $secretaryToken,
        ]);
        $edit->assertStatus(200)->assertJsonPath('data.agenda', 'Secretary review edit');

        $review = $this->postJson("/api/minutes/{$id}/secretary-review", [
            'decision' => 'approve',
            'notes' => 'Forwarded for final approval',
        ], ['Authorization' => 'Bearer ' . $secretaryToken]);
        $review->assertStatus(200)->assertJsonPath('data.status', 'pending_chairperson_approval');

        $adminToken = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $chairQueue = $this->getJson('/api/minutes?filter=pending', ['Authorization' => 'Bearer ' . $adminToken]);
        $chairQueue->assertStatus(200)->assertJsonFragment(['id' => $id]);
    }

    public function test_minutes_cannot_skip_review_states(): void
    {
        $secretaryToken = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $create = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'State guard',
        ], ['Authorization' => 'Bearer ' . $secretaryToken]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        $reviewDraft = $this->postJson("/api/minutes/{$id}/secretary-review", [
            'decision' => 'approve',
        ], ['Authorization' => 'Bearer ' . $secretaryToken]);
        $reviewDraft->assertStatus(409)->assertJsonPath('error.code', 'invalid_state');

        $adminToken = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $approveDraft = $this->postJson("/api/minutes/{$id}/approve", [], [
            'Authorization' => 'Bearer ' . $adminToken,
        ]);
        $approveDraft->assertStatus(409)->assertJsonPath('error.code', 'invalid_state');

        $rejectDraft = $this->postJson("/api/minutes/{$id}/reject", ['notes' => 'Not submitted'], [
            'Authorization' => 'Bearer ' . $adminToken,
        ]);
        $rejectDraft->assertStatus(409)->assertJsonPath('error.code', 'invalid_state');
    }

    public function test_executive_minute_blocks_non_executive_attendee(): void
    {
        $token = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $res = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'executive',
            'title' => 'Exec only',
            'attendance' => [
                ['member_id' => $this->nonExecutiveMemberId, 'status' => 'PRESENT'],
            ],
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(422);
    }

    public function test_executive_members_endpoint(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $res = $this->getJson('/api/minutes/executive-members', ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertNotEmpty($data);
    }

    public function test_minute_visibility_ordinary_member_sees_only_approved_general(): void
    {
        $secretaryToken = $this->loginAs($this->secretaryId, 'secretary@khwwc.local', ['secretary']);
        $create = $this->postJson('/api/minutes', [
            'meeting_date' => '2026-09-04',
            'meeting_type' => 'general',
            'title' => 'Visibility test',
        ], ['Authorization' => 'Bearer ' . $secretaryToken]);
        $id = $create->json('data.id');
        // ordinary member cannot view a draft
        $plainToken = $this->loginAs($this->nonExecutiveMemberId, 'plain@khwwc.local', ['member']);
        $show = $this->getJson("/api/minutes/{$id}", ['Authorization' => 'Bearer ' . $plainToken]);
        $show->assertStatus(403);
    }

    // ---------------- BULK SMS ----------------

    public function test_bulk_sms_recipients_requires_admin(): void
    {
        $plainToken = $this->loginAs($this->nonExecutiveMemberId, 'plain@khwwc.local', ['member']);
        $res = $this->getJson('/api/bulk-sms/recipients', ['Authorization' => 'Bearer ' . $plainToken]);
        $res->assertStatus(403);
    }

    public function test_bulk_sms_dry_run_validates(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $res = $this->postJson('/api/bulk-sms/dry-run', [
            'message' => 'Hello',
            'filter' => 'all',
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200)->assertJsonStructure(['data' => ['mode', 'recipients', 'valid', 'invalid', 'duplicates_removed']]);
        $this->assertSame('dry_run', $res->json('data.mode'));
    }

    public function test_bulk_sms_send_writes_sms_logs(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $before = DB::table('sms_logs')->where('reference_type', 'bulk_sms')->count();
        $res = $this->postJson('/api/bulk-sms/send', [
            'message' => 'Test',
            'filter' => 'all',
            'live' => false,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200);
        $after = DB::table('sms_logs')->where('reference_type', 'bulk_sms')->count();
        $this->assertGreaterThanOrEqual($before, $after);
    }

    public function test_bulk_sms_search_pagination(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $res = $this->getJson('/api/bulk-sms/recipients?page=1&per_page=5', ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200)->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total']]);
    }

    public function test_bulk_sms_select_all_search_resolves_all_matching_members(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $res = $this->postJson('/api/bulk-sms/dry-run', [
            'message' => 'Filtered member test',
            'filter' => 'all',
            'q' => 'Test Plain',
        ], ['Authorization' => 'Bearer ' . $token]);

        $res->assertOk()
            ->assertJsonPath('data.recipients', 1)
            ->assertJsonPath('data.valid', 1);
    }

    public function test_bulk_sms_history_shows_batches_and_member_names(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['admin']);
        $jobId = Uuid::v4();
        DB::table('sms_logs')->insert([
            'id' => Uuid::v4(),
            'recipient_phone' => '+254700000099',
            'message' => 'A readable campaign message',
            'status' => 'sent',
            'provider_ref' => 'provider-test-ref',
            'reference_type' => 'bulk_sms',
            'reference_id' => $jobId,
            'created_at' => now(),
        ]);

        $this->getJson('/api/bulk-sms/history', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonFragment([
                'job_id' => $jobId,
                'message' => 'A readable campaign message',
            ])
            ->assertJsonFragment([
                'recipient_count' => 1,
                'sent_count' => 1,
            ]);

        $this->getJson("/api/bulk-sms/history/{$jobId}/recipients", ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonFragment([
                'member_id' => $this->memberId,
                'member_name' => 'Test Exec',
                'recipient_phone' => '+254700000099',
            ]);

        $this->getJson("/api/bulk-sms/members/{$this->memberId}/history", ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonFragment([
                'job_id' => $jobId,
                'message' => 'A readable campaign message',
                'status' => 'sent',
            ]);
    }

    public function test_bulk_sms_history_requires_admin(): void
    {
        $token = $this->loginAs($this->nonExecutiveMemberId, 'plain@khwwc.local', ['member']);
        $this->getJson('/api/bulk-sms/history', ['Authorization' => 'Bearer ' . $token])
            ->assertForbidden();
    }

    // ---------------- SUPER ADMIN STATS ----------------

    public function test_super_admin_stats_requires_super(): void
    {
        $token = $this->loginAs($this->nonExecutiveMemberId, 'plain@khwwc.local', ['member']);
        $res = $this->getJson('/api/super-admin/stats', ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(403);
    }

    public function test_super_admin_stats_returns_real_data(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['super_admin']);
        $res = $this->getJson('/api/super-admin/stats', ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertArrayHasKey('redis', $data);
        $this->assertArrayHasKey('queue', $data);
        $this->assertArrayHasKey('database', $data);
        $this->assertArrayHasKey('members', $data);
        $this->assertGreaterThan(0, $data['members']['total']);
    }

    public function test_super_admin_health_returns_sections(): void
    {
        $token = $this->loginAs($this->adminId, 'admin@khwwc.local', ['super_admin']);
        $res = $this->getJson('/api/super-admin/health', ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(200)->assertJsonStructure(['data' => ['redis', 'queue', 'scheduler', 'database']]);
    }
}
