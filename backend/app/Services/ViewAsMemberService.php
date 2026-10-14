<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Audit logging for the "View as Member" feature.
 *
 * Uses the existing audit_logs table (CHAR36 UUID, no auto-increment, no
 * Eloquent timestamps). Records ADMIN_STARTED_MEMBER_VIEW and
 * ADMIN_ENDED_MEMBER_VIEW events with acting admin + viewed member.
 */
class ViewAsMemberService
{
    public const ACTION_STARTED = 'ADMIN_STARTED_MEMBER_VIEW';
    public const ACTION_ENDED = 'ADMIN_ENDED_MEMBER_VIEW';

    /**
     * Record the start of a view-as-member session.
     */
    public static function logStart(string $adminUserId, string $memberId, ?Request $request = null): void
    {
        $row = self::buildRow($adminUserId, $memberId, self::ACTION_STARTED, $request);

        self::insert($row);
    }

    /**
     * Record the end of a view-as-member session.
     */
    public static function logEnd(string $adminUserId, string $memberId, ?Request $request = null, ?string $startedAt = null): void
    {
        $row = self::buildRow($adminUserId, $memberId, self::ACTION_ENDED, $request);

        self::insert($row);
    }

    /**
     * Build the audit row payload.
     */
    private static function buildRow(string $adminUserId, string $memberId, string $action, ?Request $request): array
    {
        $ip = $request?->ip();
        $ua = $request?->headers->get('User-Agent');

        return [
            'id' => \App\Support\Uuid::v4(),
            'super_admin_id' => $adminUserId,
            'action' => $action,
            'target_user_id' => null,
            'target_member_id' => $memberId,
            'details' => json_encode([
                'view_as_context' => true,
                'acting_admin_id' => $adminUserId,
                'viewed_member_id' => $memberId,
                'ip_address' => $ip,
                'user_agent' => $ua ? substr($ua, 0, 500) : null,
            ], JSON_UNESCAPED_SLASHES),
            'ip_address' => $ip,
            'created_at' => now(),
        ];
    }

    /**
     * Insert the row using the audit_logs table directly.
     */
    private static function insert(array $row): void
    {
        try {
            DB::table('audit_logs')->insert($row);
        } catch (\Throwable $e) {
            // Audit logging must never break the request.
            if (config('app.debug')) {
                throw $e;
            }
            report($e);
        }
    }
}