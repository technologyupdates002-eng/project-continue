<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Models\MeetingMinute;
use App\Services\MemberRoleResolver;
use App\Services\WithdrawalSignatureService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative backend for the Minutes workflow.
 *
 * State machine:
 *   Vice Secretary: draft -> submitted_to_secretary -> pending_chairperson_approval
 *   Secretary:       draft --------------------------> pending_chairperson_approval
 *   Secretary review may return minutes to the preparer; Chairperson review
 *   ends in approved or rejected.
 *
 * Attendance is stored in meeting_minute_attendance with a UNIQUE
 * (minute_id, member_id) so a member can only be in one bucket.
 *
 * Auto-resolution:
 *   - officer names/signatures are resolved from active roles and canonical
 *     signatory records, never from minute-form input.
 *
 * Visibility:
 *   - General minutes: visible to all members after approval.
 *   - Executive minutes: visible only to users with executive roles.
 */
class MeetingMinutesController extends Controller
{
    public const STATUSES = [
        'draft',
        'submitted',
        'submitted_to_secretary',
        'pending_chairperson_approval',
        'rejected_by_secretary',
        'approved',
        'rejected',
    ];

    public const TRANSITIONS = [
        'draft' => ['submitted_to_secretary', 'pending_chairperson_approval'],
        'submitted' => ['submitted_to_secretary', 'pending_chairperson_approval'],
        'submitted_to_secretary' => ['pending_chairperson_approval', 'rejected_by_secretary'],
        'pending_chairperson_approval' => ['approved', 'rejected'],
        'rejected_by_secretary' => ['draft'],
        'rejected' => ['draft'],
        'approved' => [],
    ];

    public const ATTENDANCE = ['PRESENT', 'ABSENT', 'ABSENT_WITH_APOLOGY'];

    public function __construct(
        private MemberRoleResolver $roles,
        private WithdrawalSignatureService $signatureService,
    ) {}

    /**
     * POST /api/minutes
     */
    public function store(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $userId = (string) ($identity['user_id'] ?? '');
        if ($userId === '') {
            throw new HttpException(401, 'unauthenticated', 'Authentication required.');
        }
        if (! $this->canPrepareMinutes($userId, $identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Secretary, Vice Secretary, or an admin may create minutes.');
        }

        $data = $request->validate([
            'meeting_date' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'meeting_type' => ['required', 'in:general,executive'],
            'title' => ['required', 'string', 'max:255'],
            'agenda' => ['nullable', 'string'],
            'discussions' => ['nullable', 'string'],
            'decisions' => ['nullable', 'string'],
            'action_items' => ['nullable', 'string'],
            'next_meeting_date' => ['nullable', 'date'],
            'visible_to_members' => ['nullable'],
        ]);

        $isExecutive = $data['meeting_type'] === 'executive';
        $secretaryId = $this->roles->userIdForRole('secretary')
            ?? ($this->roles->hasRole($userId, 'secretary') ? $userId : null);
        $chairpersonId = $this->roles->userIdForRole('chairperson');

        $minute = [
            'id' => Uuid::v4(),
            'created_by' => $userId,
            'meeting_date' => $data['meeting_date'],
            'venue' => $data['venue'] ?? null,
            'meeting_type' => $data['meeting_type'],
            'is_executive' => $isExecutive ? 1 : 0,
            'title' => $data['title'],
            'agenda' => $data['agenda'] ?? null,
            'discussions' => $data['discussions'] ?? null,
            'decisions' => $data['decisions'] ?? null,
            'action_items' => $data['action_items'] ?? null,
            'next_meeting_date' => $data['next_meeting_date'] ?? null,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
            'secretary_name' => $secretaryId
                ? $this->roles->memberNameForUser($secretaryId)
                : ($identity['email'] ?? 'Secretary'),
            'secretary_signature_url' => $secretaryId
                ? $this->roles->signatureUrlFor('secretary', $secretaryId)
                : null,
            'chairperson_name' => $chairpersonId
                ? $this->roles->memberNameForUser($chairpersonId)
                : null,
            'chairperson_signature_url' => $chairpersonId
                ? $this->roles->signatureUrlFor('chairperson', $chairpersonId)
                : null,
        ];

        // Visibility: General minutes default to all members; executive default to executive roles only.
        if (! $isExecutive) {
            $minute['visible_to_members'] = $this->resolveVisibleMembers($data['visible_to_members'] ?? null);
        } else {
            $minute['visible_to_roles'] = json_encode(MemberRoleResolver::EXECUTIVE_ROLES);
        }

        // Attendance: write all rows in a single transaction with validation.
        $attendance = $this->normalizeAttendance($request->input('attendance'));
        $this->assertAttendeesValid($isExecutive, $attendance);

        DB::transaction(function () use ($minute, $attendance) {
            DB::table('meeting_minutes')->insert($minute);
            if (! empty($attendance)) {
                $rows = [];
                foreach ($attendance as $a) {
                    $rows[] = [
                        'id' => Uuid::v4(),
                        'minute_id' => $minute['id'],
                        'member_id' => $a['member_id'],
                        'status' => $a['status'],
                        'created_at' => now(),
                    ];
                }
                DB::table('meeting_minute_attendance')->insert($rows);
            }
        });

        return response()->json(['data' => $this->hydrate($minute['id'])], 201);
    }

    /**
     * PUT /api/minutes/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $userId = (string) ($identity['user_id'] ?? '');
        $existing = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $existing) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        $isSecretaryReview = $existing->status === 'submitted_to_secretary'
            && ($this->roles->hasRole($userId, 'secretary') || $this->isAdminOrSuper($identity));
        if (! $isSecretaryReview && ! in_array($existing->status, ['draft', 'rejected', 'rejected_by_secretary'], true)) {
            throw new HttpException(409, 'invalid_state', 'Only draft or returned minutes can be edited.');
        }
        if (! $isSecretaryReview && $existing->created_by !== $userId && ! $this->isAdminOrSuper($identity)) {
            throw new HttpException(403, 'forbidden', 'You cannot edit this minute.');
        }
        if (! $this->canPrepareMinutes($userId, $identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Secretary, Vice Secretary, or an admin may edit minutes.');
        }

        $data = $request->validate([
            'meeting_date' => ['sometimes', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'meeting_type' => ['sometimes', 'in:general,executive'],
            'title' => ['sometimes', 'string', 'max:255'],
            'agenda' => ['nullable', 'string'],
            'discussions' => ['nullable', 'string'],
            'decisions' => ['nullable', 'string'],
            'action_items' => ['nullable', 'string'],
            'next_meeting_date' => ['nullable', 'date'],
            'visible_to_members' => ['nullable'],
            'attendance' => ['sometimes', 'array'],
        ]);

        $data['updated_at'] = now();
        if (in_array($existing->status, ['rejected', 'rejected_by_secretary'], true)) {
            $data['status'] = 'draft';
            $data['rejection_notes'] = null;
            $data['secretary_notes'] = null;
        }
        if (isset($data['meeting_type'])) {
            $data['is_executive'] = $data['meeting_type'] === 'executive' ? 1 : 0;
        }
        if (array_key_exists('visible_to_members', $data)) {
            $data['visible_to_members'] = $this->resolveVisibleMembers($data['visible_to_members']);
        }
        unset($data['attendance']);

        $secretaryId = $this->roles->userIdForRole('secretary');
        if ($secretaryId) {
            $data['secretary_name'] = $this->roles->memberNameForUser($secretaryId);
            $data['secretary_signature_url'] = $this->roles->signatureUrlFor('secretary', $secretaryId);
        }
        $chairpersonId = $this->roles->userIdForRole('chairperson');
        if ($chairpersonId) {
            $data['chairperson_name'] = $this->roles->memberNameForUser($chairpersonId);
            $data['chairperson_signature_url'] = $this->roles->signatureUrlFor('chairperson', $chairpersonId);
        }

        $attendance = null;
        if ($request->has('attendance')) {
            $attendance = $this->normalizeAttendance($request->input('attendance'));
            $isExec = isset($data['meeting_type'])
                ? $data['meeting_type'] === 'executive'
                : (bool) ($existing->is_executive);
            $this->assertAttendeesValid($isExec, $attendance);
        }

        DB::transaction(function () use ($id, $data, $attendance) {
            DB::table('meeting_minutes')->where('id', $id)->update($data);
            if ($attendance !== null) {
                DB::table('meeting_minute_attendance')->where('minute_id', $id)->delete();
                if (! empty($attendance)) {
                    $rows = [];
                    foreach ($attendance as $a) {
                        $rows[] = [
                            'id' => Uuid::v4(),
                            'minute_id' => $id,
                            'member_id' => $a['member_id'],
                            'status' => $a['status'],
                            'created_at' => now(),
                        ];
                    }
                    DB::table('meeting_minute_attendance')->insert($rows);
                }
            }
        });

        return response()->json(['data' => $this->hydrate($id)]);
    }

    /**
     * DELETE /api/minutes/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity', []);
        $userId = $this->userId($request);
        $minute = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $minute) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        if (! in_array($minute->status, ['draft', 'rejected', 'rejected_by_secretary'], true)) {
            throw new HttpException(409, 'invalid_state', 'Only draft or returned minutes can be deleted.');
        }
        if (($minute->created_by !== $userId && ! $this->isAdminOrSuper($identity)) || ! $this->canPrepareMinutes($userId, $identity)) {
            throw new HttpException(403, 'forbidden', 'You cannot delete these minutes.');
        }

        DB::transaction(function () use ($id): void {
            DB::table('meeting_minute_attendance')->where('minute_id', $id)->delete();
            DB::table('meeting_minutes')->where('id', $id)->delete();
        });

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/minutes/{id}/submit — Vice Secretary submits to Secretary;
     * Secretary submits directly to Chairperson review.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        $identity = $request->attributes->get('identity', []);
        if (! $this->canPrepareMinutes($userId, $identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Secretary, Vice Secretary, or an admin may submit minutes.');
        }

        $minute = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $minute) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        if ($minute->created_by !== $userId && ! $this->isAdminOrSuper($identity)) {
            throw new HttpException(403, 'forbidden', 'You cannot submit these minutes.');
        }

        $targetStatus = $this->roles->hasRole($userId, 'secretary') || $this->isAdminOrSuper($identity)
            ? 'pending_chairperson_approval'
            : 'submitted_to_secretary';

        return $this->transition($request, $id, $targetStatus, [
            'submitted_by' => $this->userId($request),
            'submitted_at' => now(),
            'rejection_notes' => null,
            'secretary_notes' => null,
        ], 'Only draft or returned minutes can be submitted.');
    }

    /**
     * POST /api/minutes/{id}/secretary-review
     */
    public function secretaryReview(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        $identity = $request->attributes->get('identity', []);
        if (! $this->roles->hasRole($userId, 'secretary') && ! $this->isAdminOrSuper($identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Secretary may review submitted minutes.');
        }

        $minute = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $minute) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        if ($minute->status !== 'submitted_to_secretary') {
            throw new HttpException(409, 'invalid_state', 'Only minutes pending Secretary review can be reviewed.');
        }

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_if:decision,reject'],
        ]);
        $secretaryName = $this->roles->memberNameForUser($userId) ?? ($identity['email'] ?? 'Secretary');
        $secretarySignature = $this->roles->signatureUrlFor('secretary', $userId);

        return $this->transition(
            $request,
            $id,
            $data['decision'] === 'approve' ? 'pending_chairperson_approval' : 'rejected_by_secretary',
            [
                'secretary_reviewed_by' => $userId,
                'secretary_reviewed_at' => now(),
                'secretary_notes' => $data['notes'] ?? null,
                'secretary_name' => $secretaryName,
                'secretary_signature_url' => $secretarySignature,
                'rejection_notes' => $data['decision'] === 'reject' ? $data['notes'] : null,
            ],
            'Only minutes pending Secretary review can be reviewed.',
        );
    }

    /**
     * POST /api/minutes/{id}/approve — Chairperson approves.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! $this->roles->hasRole($this->userId($request), 'chairperson') && ! $this->isAdminOrSuper($identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Chairperson may approve a minute.');
        }
        $minute = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $minute) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        if ($minute->status !== 'pending_chairperson_approval') {
            throw new HttpException(409, 'invalid_state', 'Only minutes pending Chairperson approval can be approved.');
        }
        // Auto-resolve Chairperson signature on approval.
        $chairUser = $this->userId($request);
        $chairName = $this->roles->memberNameForUser($chairUser) ?? 'Chairperson';
        $chairSig = $this->roles->signatureUrlFor('chairperson', $chairUser);

        return $this->transition($request, $id, 'approved', [
            'reviewed_by' => $chairUser,
            'reviewed_at' => now(),
            'chairperson_name' => $chairName,
            'chairperson_signature_url' => $chairSig,
        ], 'Only the Chairperson may approve.');
    }

    /**
     * POST /api/minutes/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        $identity = $request->attributes->get('identity');
        if (! $this->roles->hasRole($userId, 'chairperson') && ! $this->isAdminOrSuper($identity)) {
            throw new HttpException(403, 'forbidden', 'Only the Chairperson may reject submitted minutes.');
        }
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);
        return $this->transition($request, $id, 'rejected', [
            'rejection_notes' => $data['notes'],
        ], 'Not authorized to reject.');
    }

    /**
     * GET /api/minutes — list with visibility filter.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $q = DB::table('meeting_minutes')->orderByDesc('meeting_date');
        $isExec = $this->roles->isExecutive($userId);
        $filter = (string) $request->query('filter', '');
        if ($filter === 'secretary_pending') {
            if (! $this->roles->hasRole($userId, 'secretary') && ! $this->isAdminOrSuper($request->attributes->get('identity', []))) {
                throw new HttpException(403, 'forbidden', 'Only the Secretary may view minutes awaiting Secretary review.');
            }
            $q->where('status', 'submitted_to_secretary');
        } elseif ($filter === 'pending') {
            if (! $this->roles->hasRole($userId, 'chairperson') && ! $this->isAdminOrSuper($request->attributes->get('identity', []))) {
                throw new HttpException(403, 'forbidden', 'Only the Chairperson may view minutes awaiting final approval.');
            }
            $q->where('status', 'pending_chairperson_approval');
        } elseif ($filter === 'mine') {
            if (! $this->canPrepareMinutes($userId, $request->attributes->get('identity', []))) {
                throw new HttpException(403, 'forbidden', 'Only minute preparers may view their minutes.');
            }
            $q->where('created_by', $userId);
        } elseif (! $isExec) {
            // Ordinary members only see approved General minutes.
            $q->where('meeting_type', 'general')
                ->where('status', 'approved')
                ->where(function ($w) use ($userId) {
                    $w->whereNull('visible_to_members')
                        ->orWhere('visible_to_members', 'all')
                        ->orWhereJsonContains('visible_to_members', $userId);
                });
        }
        $rows = $q->limit(200)->get(['id']);
        return response()->json(['data' => $rows->map(fn ($row) => $this->hydrate((string) $row->id))->all()]);
    }

    /**
     * GET /api/minutes/{id} — single minute, visibility-enforced.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $userId = $this->userId($request);
        $r = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $r) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        if (! $this->canView($userId, $r)) {
            throw new HttpException(403, 'forbidden', 'You cannot view this minute.');
        }
        return response()->json(['data' => $this->hydrate($r->id)]);
    }

    /**
     * GET /api/minutes/executive-members — list of users eligible for executive-minute attendance.
     */
    public function executiveMembers(Request $request): JsonResponse
    {
        $userIds = DB::table('user_roles')
            ->where('is_active', 1)
            ->whereIn('role', MemberRoleResolver::EXECUTIVE_ROLES)
            ->pluck('user_id')
            ->unique();
        $members = DB::table('members')
            ->whereIn('user_id', $userIds)
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'user_id', 'name', 'phone']);
        return response()->json(['data' => $members->map(fn ($m) => (array) $m)->all()]);
    }

    // -------------------- helpers --------------------

    private function userId(Request $request): string
    {
        $identity = $request->attributes->get('identity');
        return (string) ($identity['user_id'] ?? '');
    }

    private function isAdminOrSuper(array $identity): bool
    {
        $roles = $identity['roles'] ?? [];
        return in_array('admin', $roles, true) || in_array('super_admin', $roles, true);
    }

    private function canPrepareMinutes(string $userId, array $identity): bool
    {
        return $this->roles->hasRole($userId, 'secretary')
            || $this->roles->hasRole($userId, 'vice_secretary')
            || $this->isAdminOrSuper($identity);
    }

    private function transition(Request $request, string $id, string $newStatus, array $extra, string $denyMsg): JsonResponse
    {
        $existing = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $existing) {
            throw new HttpException(404, 'not_found', 'Minute not found.');
        }
        $allowed = self::TRANSITIONS[$existing->status] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            throw new HttpException(409, 'invalid_state', "Cannot transition from {$existing->status} to {$newStatus}.");
        }
        $update = array_merge(['status' => $newStatus, 'updated_at' => now()], $extra);
        DB::table('meeting_minutes')->where('id', $id)->update($update);
        return response()->json(['data' => $this->hydrate($id)]);
    }

    private function normalizeAttendance(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) continue;
            $memberId = (string) ($row['member_id'] ?? '');
            $status = strtoupper((string) ($row['status'] ?? ''));
            if ($memberId === '' || ! in_array($status, self::ATTENDANCE, true)) continue;
            $out[] = ['member_id' => $memberId, 'status' => $status];
        }
        // Enforce one state per member server-side.
        $seen = [];
        $final = [];
        foreach ($out as $a) {
            if (isset($seen[$a['member_id']])) {
                throw new HttpException(422, 'duplicate_attendance', "Member {$a['member_id']} appears in multiple attendance buckets.");
            }
            $seen[$a['member_id']] = true;
            $final[] = $a;
        }
        return $final;
    }

    private function assertAttendeesValid(bool $isExecutive, array $attendance): void
    {
        if (empty($attendance)) return;
        $memberIds = array_column($attendance, 'member_id');
        $validIds = DB::table('members')->whereIn('id', $memberIds)->pluck('id')->all();
        $missing = array_diff($memberIds, $validIds);
        if (! empty($missing)) {
            throw new HttpException(422, 'invalid_attendee', 'Some attendees do not exist.');
        }
        if ($isExecutive) {
            $eligibleUserIds = DB::table('user_roles')
                ->where('is_active', 1)
                ->whereIn('role', MemberRoleResolver::EXECUTIVE_ROLES)
                ->pluck('user_id');
            $eligibleMemberIds = DB::table('members')->whereIn('user_id', $eligibleUserIds)->pluck('id');
            $bad = array_diff($memberIds, $eligibleMemberIds->all());
            if (! empty($bad)) {
                throw new HttpException(422, 'non_executive_attendee', 'Executive minutes may only list executive members.');
            }
        }
    }

    private function resolveVisibleMembers(mixed $raw): ?string
    {
        if ($raw === null) return 'all';
        if (is_string($raw)) return $raw;
        if (is_array($raw)) return json_encode(array_values($raw));
        return null;
    }

    private function canView(string $userId, object $minute): bool
    {
        $roles = $this->roles->rolesFor($userId);
        if (in_array('admin', $roles, true) || in_array('super_admin', $roles, true)) {
            return true;
        }
        if ($minute->created_by === $userId) {
            return true;
        }
        if ((int) ($minute->is_executive ?? 0) === 1) {
            return $this->roles->isExecutive($userId);
        }
        if ($minute->status !== 'approved') {
            // Only creator + admins see drafts.
            return false;
        }
        $vis = $minute->visible_to_members;
        if ($vis === null || $vis === '' || $vis === 'all') {
            return true;
        }
        $arr = json_decode((string) $vis, true);
        if (! is_array($arr)) return true;
        return in_array($userId, $arr, true);
    }

    private function hydrate(string $id): array
    {
        $row = DB::table('meeting_minutes')->where('id', $id)->first();
        if (! $row) return [];
        $data = (array) $row;
        $data['creator_name'] = DB::table('members')
            ->where('user_id', $row->created_by)
            ->value('name');
        $data['secretary_signature_preview_url'] = $this->signaturePreviewUrl($row->secretary_signature_url);
        $data['chairperson_signature_preview_url'] = $this->signaturePreviewUrl($row->chairperson_signature_url);
        $rows = DB::table('meeting_minute_attendance')
            ->join('members', 'meeting_minute_attendance.member_id', '=', 'members.id')
            ->where('meeting_minute_attendance.minute_id', $id)
            ->get([
                'meeting_minute_attendance.member_id',
                'meeting_minute_attendance.status',
                'members.name',
            ]);
        $byStatus = ['PRESENT' => [], 'ABSENT' => [], 'ABSENT_WITH_APOLOGY' => []];
        $namesByStatus = ['PRESENT' => [], 'ABSENT' => [], 'ABSENT_WITH_APOLOGY' => []];
        foreach ($rows as $a) {
            $byStatus[$a->status][] = (string) $a->member_id;
            $namesByStatus[$a->status][] = (string) $a->name;
        }
        $data['attendance'] = [
            'PRESENT' => $byStatus['PRESENT'],
            'ABSENT' => $byStatus['ABSENT'],
            'ABSENT_WITH_APOLOGY' => $byStatus['ABSENT_WITH_APOLOGY'],
        ];
        $data['attendees'] = $namesByStatus['PRESENT'];
        $data['absent_without_apology'] = $namesByStatus['ABSENT'];
        $data['absent_with_apology'] = $namesByStatus['ABSENT_WITH_APOLOGY'];

        if ($rows->isEmpty() && filled($row->attendees)) {
            $legacyAttendees = json_decode((string) $row->attendees, true);
            if (is_array($legacyAttendees)) {
                $data['attendees'] = array_values(array_filter($legacyAttendees, 'is_string'));
            }
        }

        return $data;
    }

    private function signaturePreviewUrl(?string $signatureUrl): ?string
    {
        if (blank($signatureUrl)) {
            return null;
        }

        try {
            return $this->signatureService->createPreviewUrl($signatureUrl);
        } catch (HttpException) {
            return null;
        }
    }
}
