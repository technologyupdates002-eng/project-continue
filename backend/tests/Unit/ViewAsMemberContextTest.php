<?php

namespace Tests\Unit;

use App\Support\ViewAsMemberContext;
use Illuminate\Http\Request;
use Tests\TestCase;

class ViewAsMemberContextTest extends TestCase
{
    public function test_inactive_context_when_attribute_not_set(): void
    {
        $request = Request::create('/api/me', 'GET');

        $ctx = ViewAsMemberContext::from($request);

        $this->assertFalse($ctx->isActive());
        $this->assertNull($ctx->viewedMemberId());
    }

    public function test_active_context_reads_viewed_member_id(): void
    {
        $request = Request::create('/api/me', 'GET');
        $request->attributes->set('is_view_as_member', true);
        $request->attributes->set('viewing_member_id', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');

        $ctx = ViewAsMemberContext::from($request);

        $this->assertTrue($ctx->isActive());
        $this->assertSame('9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', $ctx->viewedMemberId());
    }

    public function test_viewed_member_array_is_passed_through(): void
    {
        $member = ['id' => '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', 'name' => 'Test User'];

        $request = Request::create('/api/me', 'GET');
        $request->attributes->set('is_view_as_member', true);
        $request->attributes->set('viewing_member_id', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');
        $request->attributes->set('viewed_member', $member);
        $request->attributes->set('acting_admin_user_id', 'admin-uid-123');

        $ctx = ViewAsMemberContext::from($request);

        $this->assertSame($member, $ctx->viewedMember());
        $this->assertSame('admin-uid-123', $ctx->actingAdminUserId());
    }

    public function test_resolve_member_id_returns_viewed_when_active(): void
    {
        $request = Request::create('/api/me', 'GET');
        $request->attributes->set('is_view_as_member', true);
        $request->attributes->set('viewing_member_id', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');

        $ctx = ViewAsMemberContext::from($request);

        $this->assertSame('9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b', $ctx->resolveMemberId('fallback-id'));
    }

    public function test_resolve_member_id_returns_fallback_when_inactive(): void
    {
        $request = Request::create('/api/me', 'GET');

        $ctx = ViewAsMemberContext::from($request);

        $this->assertSame('fallback-id', $ctx->resolveMemberId('fallback-id'));
        $this->assertNull($ctx->resolveMemberId(null));
    }
}
