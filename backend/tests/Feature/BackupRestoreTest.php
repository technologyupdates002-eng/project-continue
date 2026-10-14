<?php

namespace Tests\Feature;

use App\Services\AuthService;
use App\Services\JwtService;
use App\Support\Uuid;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use DatabaseMigrations;

    protected string $backupRoot;

    protected string $superAdminId;

    protected string $superAdminEmail = '254700000001@welfare.local';

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = sys_get_temp_dir().'/khwwc_bk_'.uniqid();
        mkdir($this->backupRoot, 0750, true);

        // Point the private backups disk at a throwaway directory for the test.
        config(['filesystems.disks.backups.root' => $this->backupRoot]);
        config(['backup.disk' => 'backups']);
        config(['backup.database.ssl' => false]);

        $this->seed();
        $this->superAdminId = DB::table('user_roles')->where('role', 'super_admin')->value('user_id');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupRoot)) {
            $this->rm($this->backupRoot);
        }
        parent::tearDown();
    }

    protected function superSession(array $roles = null): array
    {
        $roles ??= AuthService::rolesFor($this->superAdminId);
        $token = JwtService::encode(
            JwtService::buildPayload($this->superAdminId, $this->superAdminEmail, $roles)
        );

        return ['admin_jwt' => $token];
    }

    /* ---------------- Authorization ---------------- */

    public function test_unauthenticated_json_is_rejected(): void
    {
        $this->getJson('/admin/api/backups')->assertStatus(401);
    }

    public function test_unauthenticated_page_redirects_to_login(): void
    {
        $this->get('/admin/backups')->assertRedirect('/admin/login');
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $memberId = DB::table('user_roles')->where('role', 'member')->value('user_id');
        $memberEmail = DB::table('auth_users')->where('id', $memberId)->value('email');
        $session = ['admin_jwt' => JwtService::encode(
            JwtService::buildPayload($memberId, $memberEmail, AuthService::rolesFor($memberId))
        )];

        $this->withSession($session)->getJson('/admin/api/backups')->assertStatus(403);
    }

    /* ---------------- Create / list / verify ---------------- */

    public function test_create_full_backup_then_verify(): void
    {
        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups', ['type' => 'full'])
            ->assertStatus(201)
            ->assertJsonPath('backup.status', 'completed')
            ->assertJsonPath('backup.verification_status', 'passed');

        $this->assertDatabaseHas('backups', ['status' => 'completed', 'verification_status' => 'passed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_CREATED']);
    }

    public function test_database_and_storage_only_backups(): void
    {
        foreach (['database', 'storage'] as $type) {
            $this->withSession($this->superSession())
                ->postJson('/admin/api/backups', ['type' => $type])
                ->assertStatus(201)
                ->assertJsonPath('backup.type', $type)
                ->assertJsonPath('backup.verification_status', 'passed');
        }
    }

    public function test_backup_list_and_stats(): void
    {
        $this->withSession($this->superSession())->postJson('/admin/api/backups', ['type' => 'full']);
        $this->withSession($this->superSession())
            ->getJson('/admin/api/backups')
            ->assertOk()
            ->assertJsonStructure(['backups', 'stats', 'settings'])
            ->assertJsonPath('stats.total', 1);
    }

    /* ---------------- Download security ---------------- */

    public function test_download_is_secure_and_streamed(): void
    {
        $id = $this->createBackup('full');

        $response = $this->withSession($this->superSession())->get('/admin/api/backups/'.$id.'/download');
        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        // The file must NOT live under the public web root.
        $this->assertStringNotContainsString(public_path(), (string) $response->getContent());
    }

    /* ---------------- Delete protection ---------------- */

    public function test_delete_backup_removes_file_and_records(): void
    {
        $id = $this->createBackup('full');
        $path = DB::table('backups')->where('id', $id)->value('path');

        $this->assertTrue(Storage::disk('backups')->exists($path));

        $this->withSession($this->superSession())
            ->deleteJson('/admin/api/backups/'.$id)
            ->assertOk();

        $this->assertDatabaseHas('backups', ['id' => $id, 'status' => 'deleted']);
        $this->assertFalse(Storage::disk('backups')->exists($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'BACKUP_DELETED']);
    }

    /* ---------------- Restore cycle ---------------- */

    public function test_full_restore_cycle_reverts_data(): void
    {
        $marker = 'ZZ_RESTORE_'.uniqid();
        $phone = '+25479'.rand(1000000, 9999999);

        DB::table('members')->insert([
            'id' => Uuid::v4(), 'user_id' => Uuid::v4(), 'name' => $marker, 'phone' => $phone,
            'member_id' => $marker, 'is_active' => 1, 'status' => 'active',
            'total_contributions' => 0, 'total_penalties' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = DB::table('members')->count();
        $this->assertTrue(DB::table('members')->where('name', $marker)->exists());

        $id = $this->createBackup('full');

        DB::table('members')->where('name', $marker)->delete();
        $this->assertFalse(DB::table('members')->where('name', $marker)->exists());

        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups/'.$id.'/restore', ['confirm_word' => 'RESTORE'])
            ->assertOk()
            ->assertJsonPath('backup.status', 'restored');

        // Data reverted to backed-up state.
        $this->assertTrue(DB::table('members')->where('name', $marker)->exists(), 'Restore did not re-apply the dump.');
        $this->assertEquals($before, DB::table('members')->count());

        // Safety backup of the pre-restore state was created.
        $this->assertDatabaseHas('backups', ['is_safety' => 1, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RESTORE_COMPLETED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SAFETY_BACKUP_CREATED']);
    }

    public function test_restore_requires_confirmation_word(): void
    {
        $id = $this->createBackup('full');
        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups/'.$id.'/restore', ['confirm_word' => 'WRONG'])
            ->assertStatus(422);
    }

    public function test_restore_rejects_unverified_backup(): void
    {
        $id = $this->createBackup('full');
        DB::table('backups')->where('id', $id)->update(['status' => 'running', 'verification_status' => 'pending']);
        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups/'.$id.'/restore', ['confirm_word' => 'RESTORE'])
            ->assertStatus(422);
    }

    /* ---------------- Verification / corruption ---------------- */

    public function test_corrupt_backup_fails_verification(): void
    {
        $id = $this->createBackup('full');
        $path = DB::table('backups')->where('id', $id)->value('path');
        file_put_contents(Storage::disk('backups')->path($path), 'this is not a valid backup archive');

        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups/'.$id.'/verify')
            ->assertOk()
            ->assertJsonPath('backup.verification_status', 'failed');

        $this->assertDatabaseHas('backups', ['id' => $id, 'verification_status' => 'failed']);
    }

    /* ---------------- Pruning ---------------- */

    public function test_prune_respects_retention(): void
    {
        config(['backup.retention.keep_last' => 2]);
        for ($i = 0; $i < 4; $i++) {
            $this->createBackup('database');
        }
        $this->assertEquals(4, DB::table('backups')->where('status', 'completed')->where('is_safety', false)->count());

        $this->withSession($this->superSession())
            ->postJson('/admin/api/backups/prune')
            ->assertOk();

        $remaining = DB::table('backups')->where('status', 'completed')->where('is_safety', false)->count();
        $this->assertEquals(2, $remaining, 'Retention should keep only the last 2 backups.');
    }

    /* ---------------- Helpers ---------------- */

    protected function createBackup(string $type): string
    {
        $resp = $this->withSession($this->superSession())
            ->postJson('/admin/api/backups', ['type' => $type]);

        return $resp->json('backup.id');
    }

    protected function rm(string $dir): void
    {
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rm($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
