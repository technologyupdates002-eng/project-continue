<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\MemberRoleResolver;
use App\Services\SmsService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative Bulk SMS controller.
 *
 * Endpoints:
 *   GET  /api/bulk-sms/recipients  — server-side search + filters
 *   POST /api/bulk-sms/dry-run     — validate + count without sending
 *   POST /api/bulk-sms/send        — actually send (or simulate if no
 *                                    provider is configured). Idempotent
 *                                    by (member_id, message, day).
 *   GET  /api/bulk-sms/history     — recorded batch summaries and recipients
 */
class BulkSmsController extends Controller
{
    public function __construct(
        private SmsService $sms,
        private MemberRoleResolver $roles,
    ) {}

    /**
     * GET /api/bulk-sms/recipients
     * Query: q, filter (all|active|inactive|executive|specific),
     *        member_ids[], page, per_page
     */
    public function recipients(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $memberIds = $this->memberIds($request->query('member_ids', []));

        $builder = DB::table('members')->orderBy('name');

        if (in_array($filter, ['active', 'inactive'], true)) {
            $builder->where('is_active', $filter === 'active' ? 1 : 0);
        }
        if ($filter === 'executive') {
            $execUserIds = DB::table('user_roles')
                ->where('is_active', 1)
                ->whereIn('role', MemberRoleResolver::EXECUTIVE_ROLES)
                ->pluck('user_id');
            $builder->whereIn('user_id', $execUserIds);
        }
        $this->applySearch($builder, $q);
        if ($filter === 'specific') {
            $builder->whereIn('id', $memberIds);
        }

        $total = (clone $builder)->count();
        $rows = $builder->forPage($page, $perPage)->get([
            'id', 'user_id', 'name', 'phone', 'is_active', 'status', 'profile_picture_url',
        ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * POST /api/bulk-sms/dry-run
     * Body: { message, member_ids: [], filter: "all" }
     * Returns counts and a sample of invalid recipients.
     */
    public function dryRun(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1600'],
            'member_ids' => ['nullable', 'array'],
            'filter' => ['nullable', 'in:all,active,inactive,executive,specific'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $recipients = $this->resolveRecipients(
            $data['member_ids'] ?? [],
            $data['filter'] ?? 'specific',
            (string) ($data['q'] ?? ''),
        );
        $normalized = $this->normalizeAndValidate($recipients);
        return response()->json([
            'data' => [
                'mode' => 'dry_run',
                'recipients' => count($recipients),
                'valid' => count($normalized['valid']),
                'invalid' => count($normalized['invalid']),
                'duplicates_removed' => $normalized['duplicates'],
                'sample_invalid' => array_slice($normalized['invalid'], 0, 10),
            ],
        ]);
    }

    /**
     * POST /api/bulk-sms/send
     * Body: { message, member_ids: [], filter: "all", live: bool }
     * Default is live=false (dry-run via SmsService if no provider).
     */
    public function send(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1600'],
            'member_ids' => ['nullable', 'array'],
            'filter' => ['nullable', 'in:all,active,inactive,executive,specific'],
            'live' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $recipients = $this->resolveRecipients(
            $data['member_ids'] ?? [],
            $data['filter'] ?? 'specific',
            (string) ($data['q'] ?? ''),
        );
        $normalized = $this->normalizeAndValidate($recipients);
        $jobId = Uuid::v4();
        $live = (bool) ($data['live'] ?? false);
        $sent = 0;
        $failed = 0;
        $provider = $this->sms->isConfigured() ? 'abancool' : 'simulated';

        foreach ($normalized['valid'] as $r) {
            $result = $this->sms->send(
                $r['phone'],
                $data['message'],
                'bulk_sms',
                $jobId,
            );
            if ($result['success'] && empty($result['skipped'])) {
                $sent++;
            } else {
                $failed++;
            }
        }
        $this->audit($request, $live ? 'BULK_SMS_SENT' : 'BULK_SMS_DRY_RUN', null, [
            'job_id' => $jobId,
            'recipients' => count($recipients),
            'valid' => count($normalized['valid']),
            'invalid' => count($normalized['invalid']),
            'duplicates' => $normalized['duplicates'],
            'sent' => $sent,
            'failed' => $failed,
            'live' => $live,
            'provider' => $provider,
        ]);
        return response()->json([
            'data' => [
                'mode' => $live ? 'live' : 'dry_run',
                'job_id' => $jobId,
                'recipients' => count($recipients),
                'valid' => count($normalized['valid']),
                'invalid' => count($normalized['invalid']),
                'duplicates_removed' => $normalized['duplicates'],
                'sent' => $sent,
                'failed' => $failed,
                'provider' => $provider,
            ],
        ]);
    }

    /**
     * GET /api/bulk-sms/history
     * Returns one human-readable summary for each recorded bulk SMS batch.
     */
    public function history(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $campaigns = DB::table('sms_logs')
            ->where('reference_type', 'bulk_sms')
            ->selectRaw(
                "COALESCE(reference_id, id) AS job_id,
                message,
                MIN(created_at) AS created_at,
                COUNT(*) AS recipient_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN status NOT IN ('sent', 'failed') THEN 1 ELSE 0 END) AS pending_count"
            )
            ->groupByRaw('COALESCE(reference_id, id), message');

        $total = DB::query()->fromSub(clone $campaigns, 'campaigns')->count();
        $rows = DB::query()
            ->fromSub($campaigns, 'campaigns')
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($row) => [
                'job_id' => (string) $row->job_id,
                'message' => (string) $row->message,
                'created_at' => $row->created_at,
                'recipient_count' => (int) $row->recipient_count,
                'sent_count' => (int) $row->sent_count,
                'failed_count' => (int) $row->failed_count,
                'pending_count' => (int) $row->pending_count,
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * GET /api/bulk-sms/history/{jobId}/recipients
     * Returns each logged recipient for a recorded bulk SMS batch.
     */
    public function historyRecipients(Request $request, string $jobId): JsonResponse
    {
        $this->requireAdmin($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $query = DB::table('sms_logs')
            ->where('reference_type', 'bulk_sms')
            ->where(function ($query) use ($jobId) {
                $query->where('reference_id', $jobId)->orWhere('id', $jobId);
            });
        $total = (clone $query)->count();
        if ($total === 0) {
            return response()->json(['message' => 'Bulk SMS history was not found.'], 404);
        }

        $logs = $query
            ->orderBy('created_at')
            ->forPage($page, $perPage)
            ->get(['id', 'recipient_phone', 'message', 'status', 'provider_ref', 'created_at']);

        $memberMatches = $this->uniqueMembersByPhone();
        $rows = $logs->map(function ($log) use ($memberMatches) {
            $member = $memberMatches[$this->normalizePhone((string) $log->recipient_phone)] ?? null;
            return [
                'id' => $log->id,
                'member_id' => $member['id'] ?? null,
                'member_name' => $member['name'] ?? null,
                'recipient_phone' => $log->recipient_phone,
                'message' => $log->message,
                'status' => $log->status,
                'provider_ref' => $log->provider_ref,
                'created_at' => $log->created_at,
            ];
        });
        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * GET /api/bulk-sms/members/{memberId}/history
     * Returns bulk SMS log entries delivered to the member's current phone.
     */
    public function memberHistory(Request $request, string $memberId): JsonResponse
    {
        $this->requireAdmin($request);
        $member = DB::table('members')
            ->where('id', $memberId)
            ->first(['id', 'name', 'phone']);

        if (! $member) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $normalizedPhone = $this->normalizePhone((string) $member->phone);
        $memberMatch = $this->uniqueMembersByPhone()[$normalizedPhone] ?? null;
        $phoneVariants = $this->phoneVariants((string) $member->phone);
        $query = DB::table('sms_logs')
            ->where('reference_type', 'bulk_sms')
            ->whereIn('recipient_phone', $phoneVariants)
            ->orderByDesc('created_at');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $logs = $query->paginate($perPage, [
            'id', 'recipient_phone', 'message', 'status', 'provider_ref', 'created_at', 'reference_id',
        ]);

        return response()->json([
            'data' => $logs->getCollection()->map(fn ($log) => [
                'id' => $log->id,
                'job_id' => $log->reference_id ?: $log->id,
                'member_id' => $memberMatch['id'] ?? null,
                'member_name' => $memberMatch['name'] ?? null,
                'recipient_phone' => $log->recipient_phone,
                'message' => $log->message,
                'status' => $log->status,
                'provider_ref' => $log->provider_ref,
                'created_at' => $log->created_at,
            ]),
            'meta' => [
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    private function requireAdmin(Request $request): void
    {
        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];
        if (! in_array('admin', $roles, true) && ! in_array('super_admin', $roles, true)) {
            throw new HttpException(403, 'forbidden', 'Admin only.');
        }
    }

    private function resolveRecipients(array $memberIds, string $filter, string $search = ''): array
    {
        $builder = DB::table('members')->orderBy('name');
        if ($filter === 'specific') {
            if (empty($memberIds)) {
                return [];
            }
            $builder->whereIn('id', $memberIds);
        } elseif ($filter === 'active' || $filter === 'inactive') {
            $builder->where('is_active', $filter === 'active' ? 1 : 0);
        } elseif ($filter === 'executive') {
            $execUserIds = DB::table('user_roles')
                ->where('is_active', 1)
                ->whereIn('role', MemberRoleResolver::EXECUTIVE_ROLES)
                ->pluck('user_id');
            $builder->whereIn('user_id', $execUserIds);
        }
        $this->applySearch($builder, $search);
        return $builder->get(['id', 'user_id', 'name', 'phone'])->map(fn ($r) => (array) $r)->all();
    }

    private function applySearch(Builder $builder, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
        $builder->where(function ($query) use ($like) {
            $query->where('name', 'like', $like)->orWhere('phone', 'like', $like);
        });
    }

    private function memberIds(mixed $memberIds): array
    {
        if (is_string($memberIds)) {
            $memberIds = explode(',', $memberIds);
        }
        if (! is_array($memberIds)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $memberIds), fn ($id) => $id !== ''));
    }

    /**
     * @return array<string, array{id: string, name: string}>
     */
    private function uniqueMembersByPhone(): array
    {
        $matches = [];
        $ambiguous = [];
        foreach (DB::table('members')->get(['id', 'name', 'phone']) as $member) {
            $phone = $this->normalizePhone((string) $member->phone);
            if ($phone === '') {
                continue;
            }
            if (isset($matches[$phone])) {
                unset($matches[$phone]);
                $ambiguous[$phone] = true;
                continue;
            }
            if (! isset($ambiguous[$phone])) {
                $matches[$phone] = ['id' => (string) $member->id, 'name' => (string) $member->name];
            }
        }

        return $matches;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (! $digits) {
            return '';
        }
        if (str_starts_with($digits, '254')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+254' . substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '+254' . $digits;
        }

        return '+' . $digits;
    }

    private function phoneVariants(string $phone): array
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return [];
        }

        $digits = substr($normalized, 1);
        $variants = [$normalized, $digits];
        if (str_starts_with($digits, '254')) {
            $variants[] = '0' . substr($digits, 3);
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return array{valid: array, invalid: array, duplicates: int}
     */
    private function normalizeAndValidate(array $recipients): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];
        $dupes = 0;
        foreach ($recipients as $r) {
            $phone = (string) ($r['phone'] ?? '');
            $norm = $this->normalize($phone);
            if ($norm === null) {
                $invalid[] = ['member_id' => $r['id'] ?? null, 'name' => $r['name'] ?? null, 'phone' => $phone, 'reason' => 'invalid_phone'];
                continue;
            }
            if (isset($seen[$norm])) {
                $dupes++;
                continue;
            }
            $seen[$norm] = true;
            $valid[] = [
                'member_id' => $r['id'] ?? null,
                'name' => $r['name'] ?? null,
                'phone' => $norm,
            ];
        }
        return ['valid' => $valid, 'invalid' => $invalid, 'duplicates' => $dupes];
    }

    private function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || strlen($digits) < 9) return null;
        if (strpos($digits, '254') === 0) return '+' . $digits;
        if (strpos($digits, '0') === 0) return '+254' . substr($digits, 1);
        if (strlen($digits) === 9) return '+254' . $digits; // local 9-digit
        return '+254' . $digits;
    }

    private function audit(Request $request, string $action, ?string $target, array $details): void
    {
        try {
            $identity = $request->attributes->get('identity');
            DB::table('audit_logs')->insert([
                'id' => Uuid::v4(),
                'super_admin_id' => (string) ($identity['user_id'] ?? ''),
                'action' => $action,
                'target_user_id' => $target,
                'target_member_id' => null,
                'details' => json_encode($details),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}
