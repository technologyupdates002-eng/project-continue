<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use App\Http\Controllers\Api\MeetingMinutesController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/{secretary,vice-secretary,chairperson,office}/* dashboards.
 * Minute workflow actions delegate to MeetingMinutesController so the
 * approval rules live in one place.
 */
class OfficerPortalController extends PortalController
{
    private function minutes(): MeetingMinutesController
    {
        return app(MeetingMinutesController::class);
    }

    private function withFilter(Request $request, string $filter): JsonResponse
    {
        $request->query->set('filter', $filter);
        $res = $this->minutes()->index($request);
        $data = $res->getData(true);

        // Frontend expects a plain array.
        return response()->json($data['data'] ?? $data);
    }

    // ---------------- Secretary ----------------

    public function secretaryDashboard(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'admin']);
        $today = now()->toDateString();

        return response()->json([
            'pending_minutes' => DB::table('meeting_minutes')->where('status', 'submitted_to_secretary')->count(),
            'approved_minutes' => DB::table('meeting_minutes')->where('status', 'approved')->count(),
            'upcoming_meetings' => DB::table('events')->where('event_type', 'meeting')->where('status', '!=', 'cancelled')->where('scheduled_date', '>=', $today)->count(),
            'total_minutes' => DB::table('meeting_minutes')->count(),
            'recent_minutes' => DB::table('meeting_minutes')->orderByDesc('meeting_date')->limit(5)->get(),
            'upcoming' => $this->meetings(true),
        ]);
    }

    public function minutesReview(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'admin']);

        return $this->withFilter($request, 'secretary_pending');
    }

    public function submitReview(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'admin']);

        return $this->minutes()->secretaryReview($request, $id);
    }

    public function listMinutes(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'vice_secretary', 'admin', 'chairperson']);

        return $this->withFilter($request, 'mine');
    }

    public function createMinutes(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'vice_secretary', 'admin']);

        return $this->minutes()->store($request);
    }

    public function updateMinutes(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'vice_secretary', 'admin']);

        return $this->minutes()->update($request, $id);
    }

    public function submitMinutes(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'vice_secretary', 'admin']);

        return $this->minutes()->submit($request, $id);
    }

    private function meetings(bool $upcomingOnly): array
    {
        $q = DB::table('events')->where('event_type', 'meeting')->orderBy('scheduled_date');
        if ($upcomingOnly) {
            $q->where('status', '!=', 'cancelled')->where('scheduled_date', '>=', now()->toDateString());
        }

        return $q->limit(100)->get()->map(function ($e) {
            $meta = json_decode((string) $e->description, true);
            $meta = is_array($meta) ? $meta : ['notes' => $e->description];
            $date = (string) ($e->rescheduled_date ?? $e->scheduled_date);

            return [
                'id' => $e->id, 'title' => $e->title, 'date' => substr($date, 0, 10),
                'time' => $meta['time'] ?? substr($date, 11, 5), 'location' => $meta['location'] ?? '',
                'type' => $meta['type'] ?? 'general', 'agenda' => $meta['agenda'] ?? [],
                'attendees_count' => (int) DB::table('meeting_attendance')->where('meeting_id', $e->id)->count(),
                'status' => $e->status === 'cancelled' ? 'cancelled' : (strtotime($date) < time() ? 'completed' : 'scheduled'),
            ];
        })->all();
    }

    public function schedule(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::STAFF_ROLES);

        return response()->json($this->meetings(false));
    }

    public function createMeeting(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['secretary', 'admin', 'chairperson']);
        $d = $request->validate([
            'title' => 'required|string|max:200', 'date' => 'required|date', 'time' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:200', 'type' => 'nullable|string|max:40', 'agenda' => 'nullable|array',
        ]);
        $id = $this->uuid();
        DB::table('events')->insert([
            'id' => $id, 'title' => $d['title'], 'event_type' => 'meeting', 'status' => 'active',
            'description' => json_encode(['time' => $d['time'] ?? '', 'location' => $d['location'] ?? '', 'type' => $d['type'] ?? 'general', 'agenda' => $d['agenda'] ?? []]),
            'scheduled_date' => $d['date'].(isset($d['time']) ? ' '.$d['time'].':00' : ''),
            'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(collect($this->meetings(false))->firstWhere('id', $id), 201);
    }

    public function cancelMeeting(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['secretary', 'admin', 'chairperson']);
        $n = DB::table('events')->where('id', $id)->where('event_type', 'meeting')->update(['status' => 'cancelled', 'updated_at' => now()]);
        if (! $n) {
            throw new HttpException(404, 'not_found', 'Meeting not found.');
        }

        return response()->json($this->ok('Meeting cancelled.'));
    }

    // ---------------- Vice secretary ----------------

    public function viceSecretaryDashboard(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['vice_secretary', 'secretary', 'admin']);

        return response()->json([
            'total_events' => DB::table('events')->count(),
            'active_events' => DB::table('events')->where('status', 'active')->count(),
            'total_documents' => DB::table('documents')->count(),
            'pending_documents' => DB::table('documents')->where('status', 'pending')->count(),
            'active_members' => DB::table('members')->where('is_active', 1)->count(),
            'recent_messages' => DB::table('messages')->where('created_at', '>=', now()->subDays(7))->count(),
            'unread_notifications' => DB::table('notifications')->where('user_id', $identity['user_id'])->where('is_read', 0)->count(),
            'recent_documents' => DB::table('documents')->orderByDesc('created_at')->limit(5)->get(),
            'event_summary' => DB::table('events')->selectRaw('event_type, COUNT(*) as total')->groupBy('event_type')->get(),
            'recent_activity' => DB::table('audit_logs')->orderByDesc('created_at')->limit(8)->get(['action', 'created_at']),
        ]);
    }

    // ---------------- Chairperson ----------------

    public function chairDashboard(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['chairperson', 'admin']);

        return response()->json([
            'pending_approvals' => DB::table('meeting_minutes')->where('status', 'pending_chairperson_approval')->count(),
            'approved_count' => DB::table('meeting_minutes')->where('status', 'approved')->count(),
            'rejected_count' => DB::table('meeting_minutes')->where('status', 'rejected')->count(),
            'pending_items' => DB::table('meeting_minutes')->where('status', 'pending_chairperson_approval')->orderByDesc('meeting_date')->limit(10)->get(),
            'recent_approvals' => DB::table('meeting_minutes')->whereIn('status', ['approved', 'rejected'])->orderByDesc('reviewed_at')->limit(8)->get()
                ->map(fn ($m) => ['title' => $m->title, 'date' => (string) $m->reviewed_at, 'action' => $m->status]),
        ]);
    }

    public function pendingApprovals(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['chairperson', 'admin']);

        return $this->withFilter($request, 'pending');
    }

    public function decideMinutes(Request $request, string $id, string $action): JsonResponse
    {
        $this->requireAnyRole($request, ['chairperson', 'admin']);
        if ($action === 'reject') {
            $request->merge(['rejection_notes' => $request->input('note', 'Rejected')]);

            return $this->minutes()->reject($request, $id);
        }

        return $this->minutes()->approve($request, $id);
    }

    // ---------------- Office ----------------

    public function officeDashboard(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['office', 'admin', 'secretary']);

        return response()->json([
            'total_members' => DB::table('members')->count(),
            'pending_applications' => DB::table('member_registrations')->where('status', 'pending')->count(),
            'pending_documents' => DB::table('documents')->where('status', 'pending')->count(),
            'recent_activities' => DB::table('audit_logs')->orderByDesc('created_at')->limit(10)->get()
                ->map(fn ($a) => ['description' => str_replace('_', ' ', (string) $a->action), 'date' => (string) $a->created_at]),
        ]);
    }
}
