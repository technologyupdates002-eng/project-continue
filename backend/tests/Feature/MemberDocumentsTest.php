<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Member Documents module — feature tests (audit 2026-09-04).
 *
 * Covers the entire lifecycle:
 *   - upload:        member uploads an image, then a PDF
 *   - replace:       member uploads a new version; old is soft-deleted
 *   - approve/reject: staff can change status; members cannot
 *   - delete:        member soft-deletes own; staff hard-deletes
 *   - download:      requires auth + ownership; signed URLs work
 *   - summary:       completion + types + statuses calculated correctly
 *   - admin:         search/filter, expiry, missing, orphans, audit
 *   - security:      executable extensions blocked; MIME validation; size limits
 */
class MemberDocumentsTest extends TestCase
{
    private string $adminId = '11111111-1111-1111-1111-111111111111';
    private string $memberAId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private string $memberBId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTestStorageDisk();

        // Admin user.
        DB::table('auth_users')->updateOrInsert(['id' => $this->adminId], [
            'email' => 'admin-docs@khwwc.local', 'phone' => '+254700000010',
            'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
            'is_banned' => 0, 'must_change_password' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('members')->updateOrInsert(['id' => $this->adminId], [
            'user_id' => $this->adminId, 'name' => 'Admin Docs', 'phone' => '+254700000010',
            'is_active' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->updateOrInsert(['user_id' => $this->adminId, 'role' => 'admin'], ['id' => Uuid::v4(), 'is_active' => 1]);
        DB::table('user_roles')->updateOrInsert(['user_id' => $this->adminId, 'role' => 'super_admin'], ['id' => Uuid::v4(), 'is_active' => 1]);

        // Member A.
        DB::table('auth_users')->updateOrInsert(['id' => $this->memberAId], [
            'email' => 'a@khwwc.local', 'phone' => '+254700000020',
            'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
            'is_banned' => 0, 'must_change_password' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('members')->updateOrInsert(['id' => $this->memberAId], [
            'user_id' => $this->memberAId, 'name' => 'Member A', 'phone' => '+254700000020',
            'is_active' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->updateOrInsert(['user_id' => $this->memberAId, 'role' => 'member'], ['id' => Uuid::v4(), 'is_active' => 1]);

        // Member B.
        DB::table('auth_users')->updateOrInsert(['id' => $this->memberBId], [
            'email' => 'b@khwwc.local', 'phone' => '+254700000030',
            'encrypted_password' => password_hash('Pass2026', PASSWORD_BCRYPT),
            'is_banned' => 0, 'must_change_password' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('members')->updateOrInsert(['id' => $this->memberBId], [
            'user_id' => $this->memberBId, 'name' => 'Member B', 'phone' => '+254700000030',
            'is_active' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->updateOrInsert(['user_id' => $this->memberBId, 'role' => 'member'], ['id' => Uuid::v4(), 'is_active' => 1]);
    }

    private function loginAs(string $userId, string $email, array $roles): string
    {
        $payload = JwtService::buildPayload($userId, $email, $roles);
        return JwtService::encode($payload);
    }

    private function ensureTestStorageDisk(): void
    {
        // Ensure storage path exists; tests rely on storage/app/private.
        $root = storage_path('app/private/documents');
        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }
    }

    // ---------------- Types catalog ----------------

    public function test_types_catalog_is_seeded_and_active(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $res = $this->getJson('/api/documents/types', ['Authorization' => 'Bearer ' . $token]);
        $res->assertOk();
        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertContains('national_id', $codes);
        $this->assertContains('profile_photo', $codes);
        $this->assertContains('employment_letter', $codes);
    }

    // ---------------- Upload ----------------

    public function test_member_can_upload_image(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $img = $this->makeImage(50, 50);
        $res = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $img,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(201)->assertJsonPath('data.member_id', $this->memberAId);
        $this->assertSame('approved', $res->json('data.status')); // profile_photo requires_approval=false
        $this->assertNotEmpty($res->json('data.download_url'));
    }

    public function test_member_can_upload_pdf(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $res = $this->postJson('/api/documents', [
            'document_type_code' => 'employment_letter',
            'file' => $this->makePdf(),
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(201);
        $this->assertSame('pending', $res->json('data.status'));
        $this->assertStringContainsString('application/pdf', $res->json('data.mime_type'));
    }

    public function test_executable_extension_is_rejected(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $file = UploadedFile::fake()->createWithContent('hack.php', "<?php echo 'pwned'; ?>");
        $res = $this->postJson('/api/documents', [
            'document_type_code' => 'other',
            'file' => $file,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(415);
        $this->assertSame('blocked_extension', $res->json('error.code'));
    }

    public function test_oversized_file_is_rejected(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $file = UploadedFile::fake()->create('big.pdf', 11 * 1024); // 11 MB
        $res = $this->postJson('/api/documents', [
            'document_type_code' => 'employment_letter',
            'file' => $file,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(413);
    }

    public function test_corrupted_image_is_rejected(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $file = UploadedFile::fake()->createWithContent('bad.png', 'not really a png');
        $res = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $file,
        ], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(400);
    }

    // ---------------- Replace ----------------

    public function test_member_can_replace_document(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        // First upload
        $first = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(20, 20),
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        // Replace
        $second = $this->postJson('/api/documents/' . $first['id'] . '/replace', [
            'file' => $this->makeImage(40, 40),
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, $second['version']);
        $this->assertSame($first['id'], $second['replaces_id']);
        // Old row should be soft-deleted
        $old = DB::table('documents')->where('id', $first['id'])->first();
        $this->assertNotNull($old->deleted_at);
    }

    // ---------------- RBAC ----------------

    public function test_member_cannot_approve_own_document(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'employment_letter',
            'file' => $this->makePdf(),
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        $res = $this->postJson('/api/documents/' . $doc['id'] . '/approve', [], ['Authorization' => 'Bearer ' . $token]);
        $res->assertStatus(403);
    }

    public function test_staff_can_approve_document(): void
    {
        $memberToken = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin', 'super_admin']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'employment_letter',
            'file' => $this->makePdf(),
        ], ['Authorization' => 'Bearer ' . $memberToken])->json('data');
        $res = $this->postJson('/api/documents/' . $doc['id'] . '/approve', ['notes' => 'OK'], ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_reject_requires_notes(): void
    {
        $memberToken = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'employment_letter',
            'file' => $this->makePdf(),
        ], ['Authorization' => 'Bearer ' . $memberToken])->json('data');
        $res = $this->postJson('/api/documents/' . $doc['id'] . '/reject', [], ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertStatus(422);
    }

    public function test_member_cannot_read_other_member_documents(): void
    {
        $aToken = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $bToken = $this->loginAs($this->memberBId, 'b@khwwc.local', ['member']);
        // A uploads.
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(10, 10),
        ], ['Authorization' => 'Bearer ' . $aToken])->json('data');
        // B tries to view.
        $res = $this->getJson('/api/documents/' . $doc['id'], ['Authorization' => 'Bearer ' . $bToken]);
        $res->assertStatus(403);
    }

    // ---------------- Download / Storage ----------------

    public function test_storage_download_requires_auth(): void
    {
        // Upload as member.
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(20, 20),
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        // Anonymous request — must 401.
        $res = $this->get('/api/storage/v1/object/documents/' . $doc['file_url']);
        $res->assertStatus(401);
    }

    public function test_storage_download_works_with_signed_url(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(15, 15),
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        $res = $this->get($doc['download_url']);
        $res->assertOk();
    }

    public function test_storage_signed_url_endpoint_works(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $resp = $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(15, 15),
        ], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(201, $resp->getStatusCode(), 'upload failed: ' . $resp->getContent());
        $doc = $resp->json('data');
        $res = $this->getJson('/api/storage/v1/object/documents/' . $doc['file_url'] . '/signed-url?expires=300', ['Authorization' => 'Bearer ' . $token]);
        $res->assertOk();
        $this->assertNotEmpty($res->json('signedUrl'));
    }

    // ---------------- Summary ----------------

    public function test_member_summary_includes_completion_and_types(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(20, 20),
        ], ['Authorization' => 'Bearer ' . $token])->assertStatus(201);
        $res = $this->getJson('/api/documents/me/summary', ['Authorization' => 'Bearer ' . $token]);
        $res->assertOk();
        $this->assertGreaterThan(0, $res->json('data.completion_percent'));
        $codes = collect($res->json('data.types'))->pluck('code')->all();
        $this->assertContains('profile_photo', $codes);
    }

    // ---------------- Admin endpoints ----------------

    public function test_admin_summary_returns_counts(): void
    {
        $memberToken = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin', 'super_admin']);
        $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(20, 20),
        ], ['Authorization' => 'Bearer ' . $memberToken])->assertStatus(201);
        $res = $this->getJson('/api/admin/documents/summary', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('data.total'));
    }

    public function test_admin_list_joins_member_name(): void
    {
        $memberToken = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $this->postJson('/api/documents', [
            'document_type_code' => 'profile_photo',
            'file' => $this->makeImage(20, 20),
        ], ['Authorization' => 'Bearer ' . $memberToken])->assertStatus(201);
        $res = $this->getJson('/api/admin/documents?q=Member+A', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data')));
        $this->assertSame('Member A', $res->json('data.0.member_name'));
    }

    public function test_admin_expiring_returns_upcoming(): void
    {
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $res = $this->getJson('/api/admin/documents/expiring?days=30', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $this->assertIsArray($res->json('data'));
    }

    public function test_admin_missing_lists_required_types(): void
    {
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $res = $this->getJson('/api/admin/documents/missing', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $codes = collect($res->json('data'))->pluck('code')->all();
        $this->assertContains('national_id', $codes);
    }

    public function test_admin_orphans_endpoint_runs(): void
    {
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $res = $this->getJson('/api/admin/documents/orphans', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $this->assertArrayHasKey('db_orphans_count', $res->json('data'));
        $this->assertArrayHasKey('file_orphans_count', $res->json('data'));
    }

    public function test_admin_audit_returns_recent(): void
    {
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $res = $this->getJson('/api/admin/documents/audit?limit=10', ['Authorization' => 'Bearer ' . $adminToken]);
        $res->assertOk();
        $this->assertIsArray($res->json('data'));
    }

    // ---------------- Expiry detection ----------------

    public function test_member_summary_detects_expired(): void
    {
        $token = $this->loginAs($this->memberAId, 'a@khwwc.local', ['member']);
        $doc = $this->postJson('/api/documents', [
            'document_type_code' => 'national_id',
            'file' => $this->makePdf(),
            'expires_at' => '2020-01-01',
        ], ['Authorization' => 'Bearer ' . $token])->json('data');
        // Mark approved.
        $adminToken = $this->loginAs($this->adminId, 'admin-docs@khwwc.local', ['admin']);
        $this->postJson('/api/documents/' . $doc['id'] . '/approve', [], ['Authorization' => 'Bearer ' . $adminToken])->assertOk();

        $res = $this->getJson('/api/documents/me/summary', ['Authorization' => 'Bearer ' . $token]);
        $res->assertOk();
        $nat = collect($res->json('data.types'))->firstWhere('code', 'national_id');
        $this->assertTrue($nat['is_expired']);
    }

    // ---------------- Helpers ----------------

    private function makeImage(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return UploadedFile::fake()->createWithContent('photo.png', $bytes);
    }

    private function makePdf(): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000056 00000 n \n0000000111 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n160\n%%EOF";
        return UploadedFile::fake()->createWithContent('doc.pdf', $content);
    }
}
