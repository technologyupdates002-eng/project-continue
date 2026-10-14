<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminBeneficiaryRequestTest extends TestCase
{
    use RefreshDatabase;

    private string $adminId;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = Uuid::v4();
        $email = 'beneficiary-admin-' . Uuid::v4() . '@welfare.local';
        DB::table('auth_users')->insert([
            'id' => $this->adminId,
            'email' => $email,
            'encrypted_password' => password_hash('password', PASSWORD_BCRYPT),
            'is_banned' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'id' => Uuid::v4(),
            'user_id' => $this->adminId,
            'role' => 'admin',
            'is_active' => 1,
        ]);
        $token = AuthService::issueSession([
            'id' => $this->adminId,
            'email' => $email,
        ])['access_token'];
        $this->headers = ['Authorization' => 'Bearer ' . $token];
    }

    public function test_request_list_includes_member_name_and_phone(): void
    {
        $memberId = $this->createMember('Amina Wanjiru', '254712345678');
        $requestId = $this->createRequest($memberId);

        $this->getJson('/api/admin/beneficiary-requests?status=pending', $this->headers)
            ->assertOk()
            ->assertJsonPath('0.id', $requestId)
            ->assertJsonPath('0.member.name', 'Amina Wanjiru')
            ->assertJsonPath('0.member.phone', '254712345678');
    }

    public function test_approval_adds_beneficiary_and_reviews_request_atomically_once(): void
    {
        $memberId = $this->createMember('Amina Wanjiru', '254712345678');
        $requestId = $this->createRequest($memberId);

        $this->postJson("/api/admin/beneficiary-requests/{$requestId}/review", [
            'status' => 'approved',
            'admin_notes' => 'Verified',
        ], $this->headers)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, DB::table('beneficiaries')->where('member_id', $memberId)->count());
        $this->assertDatabaseHas('beneficiary_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'reviewed_by' => $this->adminId,
            'admin_notes' => 'Verified',
        ]);

        $this->postJson("/api/admin/beneficiary-requests/{$requestId}/review", [
            'status' => 'approved',
        ], $this->headers)->assertStatus(409);
        $this->assertSame(1, DB::table('beneficiaries')->where('member_id', $memberId)->count());
    }

    public function test_removal_conflict_does_not_change_request_or_another_members_beneficiary(): void
    {
        $requestOwnerId = $this->createMember('Member One', '254711111111');
        $otherMemberId = $this->createMember('Member Two', '254722222222');
        $beneficiaryId = Uuid::v4();
        DB::table('beneficiaries')->insert([
            'id' => $beneficiaryId,
            'member_id' => $otherMemberId,
            'name' => 'Protected Beneficiary',
            'relationship' => 'child',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = $this->createRequest($requestOwnerId, 'remove', $beneficiaryId);

        $this->postJson("/api/admin/beneficiary-requests/{$requestId}/review", [
            'status' => 'approved',
        ], $this->headers)->assertStatus(409);

        $this->assertDatabaseHas('beneficiaries', ['id' => $beneficiaryId, 'member_id' => $otherMemberId]);
        $this->assertDatabaseHas('beneficiary_requests', ['id' => $requestId, 'status' => 'pending']);
    }

    public function test_rejection_does_not_create_a_beneficiary(): void
    {
        $memberId = $this->createMember('Amina Wanjiru', '254712345678');
        $requestId = $this->createRequest($memberId);

        $this->postJson("/api/admin/beneficiary-requests/{$requestId}/review", [
            'status' => 'rejected',
            'admin_notes' => 'Details need correction',
        ], $this->headers)->assertOk();

        $this->assertSame(0, DB::table('beneficiaries')->where('member_id', $memberId)->count());
        $this->assertDatabaseHas('beneficiary_requests', ['id' => $requestId, 'status' => 'rejected']);
    }

    public function test_pending_request_notifications_are_backfilled_once_and_remain_read(): void
    {
        $memberId = $this->createMember('Amina Wanjiru', '254712345678');
        $requestId = $this->createRequest($memberId);
        $eventKey = hash('sha256', 'beneficiary-request:' . $requestId);

        $inbox = $this->getJson('/api/admin/notifications/inbox', $this->headers);
        $inbox->assertOk()->assertJsonPath('meta.unread_count', 1);
        $notificationId = $inbox->json('data.0.id');

        $this->getJson('/api/admin/notifications/inbox', $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->adminId)->where('event_key', $eventKey)->count());

        $this->postJson("/api/admin/notifications/{$notificationId}/read", [], $this->headers)->assertOk();
        $this->getJson('/api/admin/notifications/inbox', $this->headers)
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->adminId)->where('event_key', $eventKey)->count());
    }

    private function createMember(string $name, string $phone): string
    {
        $id = Uuid::v4();
        DB::table('members')->insert([
            'id' => $id,
            'name' => $name,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createRequest(string $memberId, string $type = 'add', ?string $beneficiaryId = null): string
    {
        $id = Uuid::v4();
        DB::table('beneficiary_requests')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'request_type' => $type,
            'status' => 'pending',
            'beneficiary_name' => $type === 'add' ? 'Requested Beneficiary' : null,
            'beneficiary_relationship' => $type === 'add' ? 'spouse' : null,
            'beneficiary_phone' => $type === 'add' ? '254733333333' : null,
            'beneficiary_id' => $beneficiaryId,
            'reason' => 'Member request',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
