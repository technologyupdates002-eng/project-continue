<?php

namespace Tests\Unit;

use App\Services\ViewAsMemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewAsMemberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_start_inserts_audit_row(): void
    {
        ViewAsMemberService::logStart('admin-123', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');

        $this->assertDatabaseHas('audit_logs', [
            'super_admin_id' => 'admin-123',
            'action' => 'ADMIN_STARTED_MEMBER_VIEW',
            'target_member_id' => '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b',
        ]);
    }

    public function test_log_end_inserts_audit_row(): void
    {
        ViewAsMemberService::logEnd('admin-123', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');

        $this->assertDatabaseHas('audit_logs', [
            'super_admin_id' => 'admin-123',
            'action' => 'ADMIN_ENDED_MEMBER_VIEW',
            'target_member_id' => '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b',
        ]);
    }

    public function test_log_start_includes_request_ip_in_details(): void
    {
        $request = Request::create('/api/me', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.42');

        ViewAsMemberService::logStart('admin-123', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', $request);

        $row = DB::table('audit_logs')
            ->where('action', 'ADMIN_STARTED_MEMBER_VIEW')
            ->first();

        $details = json_decode($row->details, true);

        $this->assertEquals('203.0.113.42', $details['ip_address'] ?? null);
        $this->assertEquals('admin-123', $details['acting_admin_id']);
        $this->assertEquals('9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', $details['viewed_member_id']);
    }

    public function test_log_end_includes_request_ip_in_details(): void
    {
        $request = Request::create('/api/me', 'GET');
        $request->server->set('REMOTE_ADDR', '203.0.113.42');

        ViewAsMemberService::logEnd('admin-123', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', $request);

        $row = DB::table('audit_logs')
            ->where('action', 'ADMIN_ENDED_MEMBER_VIEW')
            ->first();

        $details = json_decode($row->details, true);

        $this->assertEquals('203.0.113.42', $details['ip_address'] ?? null);
    }

    public function test_log_start_without_request_still_inserts(): void
    {
        ViewAsMemberService::logStart('admin-123', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ADMIN_STARTED_MEMBER_VIEW')->count());
    }
}
