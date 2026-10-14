<?php

namespace Tests\Unit;

use App\Exceptions\HttpException;
use App\Http\Middleware\ViewAsMember;
use App\Support\ViewAsMemberContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ViewAsMemberMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected ViewAsMember $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ViewAsMember();
    }

    public function test_passes_through_when_no_header(): void
    {
        $request = Request::create('/api/me', 'GET');
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;
            return response()->json(['ok' => true]);
        });

        $this->assertTrue($called);
    }

    public function test_rejects_invalid_uuid_with_400(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('must be a valid member UUID');

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', 'not-a-uuid');
        $request->attributes->set('identity', [
            'user_id' => 'admin-123',
            'roles' => ['admin'],
        ]);

        $this->middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_rejects_query_param_bypass_with_400(): void
    {
        $this->expectException(HttpException::class);

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', 'eq.B');
        $request->attributes->set('identity', [
            'user_id' => 'admin-123',
            'roles' => ['admin'],
        ]);

        $this->middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_rejects_non_admin_with_403(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Only administrators may view members');

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');
        $request->attributes->set('identity', [
            'user_id' => 'member-123',
            'roles' => ['member'],
        ]);

        $this->middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_rejects_treasurer_with_403(): void
    {
        $this->expectException(HttpException::class);

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');
        $request->attributes->set('identity', [
            'user_id' => 'treasurer-123',
            'roles' => ['treasurer'],
        ]);

        $this->middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_rejects_nonexistent_member_with_404(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Target member not found');

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b');
        $request->attributes->set('identity', [
            'user_id' => 'admin-123',
            'roles' => ['admin'],
        ]);

        $this->middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
    }

    public function test_valid_admin_sets_request_attributes(): void
    {
        $memberId = '9b5e99e0-87b3-4e3f-a70f-596dc2e4e92b';
        $adminId = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        DB::table('members')->insert([
            'id' => $memberId,
            'user_id' => 'm-user-123',
            'name' => 'Test Member',
            'phone' => '+254712345678',
            'status' => 'active',
            'is_active' => 1,
        ]);

        $request = Request::create('/api/me', 'GET');
        $request->headers->set('X-View-As-Member', $memberId);
        $request->attributes->set('identity', [
            'user_id' => $adminId,
            'roles' => ['admin', 'super_admin'],
        ]);

        $called = false;
        $this->middleware->handle($request, function ($req) use (&$called, $memberId, $adminId) {
            $called = true;
            $this->assertTrue($req->attributes->get('is_view_as_member'));
            $this->assertSame($memberId, $req->attributes->get('viewing_member_id'));
            $this->assertSame($adminId, $req->attributes->get('acting_admin_user_id'));
            $viewedMember = $req->attributes->get('viewed_member');
            $this->assertSame($memberId, $viewedMember['id']);
            $this->assertSame('Test Member', $viewedMember['name']);
            return response()->json(['ok' => true]);
        });

        $this->assertTrue($called);
    }
}
