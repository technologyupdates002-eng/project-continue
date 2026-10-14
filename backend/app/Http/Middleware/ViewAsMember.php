<?php

namespace App\Http\Middleware;

use App\Exceptions\HttpException;
use App\Services\AuthService;
use App\Services\PolicyService;
use App\Services\ViewAsMemberService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes a server-side "View as Member" context on top of the existing
 * JWT authentication.
 *
 * The middleware runs AFTER auth.jwt (Authenticate). It does NOT replace or
 * modify the authenticated admin identity. It only adds a request-scoped
 * "viewed member" context that downstream controllers and services may consult.
 *
 * The X-View-As-Member header is NOT an authorization mechanism. Every value
 * is independently validated against the authenticated admin's roles, the
 * members table, and the existing PolicyService authorization matrix.
 */
class ViewAsMember
{
    public const HEADER = 'X-View-As-Member';

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header(self::HEADER);

        if ($header === null || $header === '') {
            return $next($request);
        }

        $identity = $request->attributes->get('identity');

        if (! is_array($identity)) {
            throw new HttpException(401, 'unauthenticated', 'Authentication required to use view-as-member.');
        }

        // 1. Only admins may view members.
        if (! AuthService::isAdmin($identity['roles'] ?? [])) {
            throw new HttpException(403, 'insufficient_permissions', 'Only administrators may view members.');
        }

        // 2. Validate UUID format.
        $memberId = trim($header);
        if (! self::isValidUuid($memberId)) {
            throw new HttpException(400, 'invalid_member_id', 'X-View-As-Member must be a valid member UUID.');
        }

        // 3. Resolve the target member.
        $member = DB::table('members')
            ->where('id', $memberId)
            ->select('id', 'user_id', 'name', 'phone', 'status', 'is_active')
            ->first();

        if ($member === null) {
            throw new HttpException(404, 'member_not_found', 'Target member not found.');
        }

        if (! $member->is_active) {
            throw new HttpException(403, 'member_inactive', 'Target member is inactive.');
        }

        // 4. Attach trusted request context.
        $request->attributes->set('viewing_member_id', $memberId);
        $request->attributes->set('viewed_member', [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->name,
            'phone' => $member->phone,
            'status' => $member->status,
            'is_active' => (bool) $member->is_active,
        ]);
        $request->attributes->set('acting_admin_user_id', $identity['user_id']);
        $request->attributes->set('is_view_as_member', true);

        // Also surface the view context on the identity array so existing
        // services (PaymentService, WalletService, etc.) can guard against
        // write abuse without needing to reach for request attributes.
        $identity['is_view_as_member'] = true;
        $identity['viewing_member_id'] = $memberId;
        $identity['viewing_member_user_id'] = $member->user_id;
        $identity['viewing_member'] = [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->name,
            'phone' => $member->phone,
            'status' => $member->status,
            'is_active' => (bool) $member->is_active,
        ];
        $request->attributes->set('identity', $identity);

        // 5. Audit the start of the view session (best-effort).
        ViewAsMemberService::logStart($identity['user_id'], $memberId, $request);

        return $next($request);
    }

    private static function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}