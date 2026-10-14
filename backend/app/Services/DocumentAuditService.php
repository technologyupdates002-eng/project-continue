<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centralized audit logger for document actions.
 *
 * Writes to `document_audit_log` if the table exists. Designed to be
 * fire-and-forget: never throws.
 */
class DocumentAuditService
{
    public static function log(array $args): void
    {
        $action = (string) ($args['action'] ?? 'unknown');
        $bucket = (string) ($args['bucket'] ?? '');
        $path = (string) ($args['path'] ?? '');
        $request = $args['request'] ?? null;
        $extra = (array) ($args['extra'] ?? []);

        $identity = $request instanceof Request ? $request->attributes->get('identity') : [];
        $userId = is_array($identity) ? ($identity['user_id'] ?? null) : null;
        $memberId = is_array($identity) ? ($identity['member_id'] ?? null) : null;
        $ip = $request instanceof Request ? $request->ip() : null;
        $ua = $request instanceof Request ? (string) $request->userAgent() : null;

        try {
            DB::table('document_audit_log')->insert([
                'id' => (string) Str::uuid(),
                'action' => substr($action, 0, 64),
                'bucket' => substr($bucket, 0, 64),
                'object_path' => substr($path, 0, 512),
                'user_id' => $userId,
                'member_id' => $memberId,
                'ip' => $ip,
                'user_agent' => $ua ? substr($ua, 0, 512) : null,
                'extra' => ! empty($extra) ? json_encode($extra) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit logging should never break the main flow
        }
    }

    public static function recent(int $limit = 50, ?string $bucket = null): array
    {
        $q = DB::table('document_audit_log')->orderByDesc('created_at')->limit($limit);
        if ($bucket !== null) {
            $q->where('bucket', $bucket);
        }
        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    public static function stats(): array
    {
        $rows = DB::table('document_audit_log')
            ->select('action', DB::raw('count(*) as c'))
            ->groupBy('action')
            ->get();
        $byAction = [];
        $total = 0;
        foreach ($rows as $r) {
            $byAction[$r->action] = (int) $r->c;
            $total += (int) $r->c;
        }
        return ['by_action' => $byAction, 'total' => $total];
    }
}
