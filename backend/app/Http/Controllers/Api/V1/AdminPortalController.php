<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use App\Jobs\SendBulkSmsJob;
use App\Services\PaymentLifecycleService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/admin/* — admin dashboard, members, payments, content and settings.
 */
class AdminPortalController extends PortalController
{
    private const ROLES = ['admin', 'treasurer', 'secretary', 'chairperson', 'office'];

    public function __construct(private readonly PaymentLifecycleService $lifecycle) {}

    public function dashboard(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->cached('admin:dash', fn () => [
            'total_members' => DB::table('members')->count(),
            'active_members' => DB::table('members')->where('is_active', 1)->count(),
            'total_contributions' => (float) DB::table('contributions')->where('status', 'paid')->sum('amount'),
            'pending_approvals' => DB::table('member_registrations')->where('status', 'pending')->count()
                + DB::table('beneficiary_requests')->where('status', 'pending')->count(),
            'defaulters_count' => self::defaultersQuery()->count(),
            'recent_signups' => DB::table('members')->orderByDesc('created_at')->limit(6)->get(),
            'contributions_trend' => $this->monthlyTrend(),
        ], 30));
    }

    /** Members with unpaid months up to the current month. */
    public static function defaultersQuery(): Builder
    {
        $now = (int) now()->format('Y') * 100 + (int) now()->format('n');

        return DB::table('members as m')
            ->join('contributions as c', 'c.member_id', '=', 'm.id')
            ->where('m.is_active', 1)
            ->where('c.status', '!=', 'paid')
            ->whereRaw('(c.year * 100 + c.month) <= ?', [$now])
            ->groupBy('m.id', 'm.name', 'm.member_id', 'm.phone')
            ->selectRaw('m.id, m.name, m.member_id, m.phone, COUNT(c.id) as unpaid_months, SUM(c.amount) as arrears, MIN(c.due_date) as oldest_due');
    }

    public function members(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $q = DB::table('members as m')->leftJoin('auth_users as u', 'u.id', '=', 'm.user_id')
            ->select('m.*', 'u.email')->orderBy('m.name');
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('m.name', 'like', "%{$s}%")->orWhere('m.member_id', 'like', "%{$s}%")->orWhere('m.phone', 'like', "%{$s}%"));
        }
        if ($st = $request->query('status')) {
            $st === 'active' ? $q->where('m.is_active', 1) : ($st === 'inactive' ? $q->where('m.is_active', 0) : $q->where('m.status', $st));
        }
        $page = $this->paginate($q, $request);
        $page['data'] = array_map(fn ($m) => $this->member($m), $page['data']);

        return response()->json($page);
    }

    public function member(object $m): array
    {
        return [
            'id' => $m->id, 'name' => $m->name, 'email' => $m->email ?? null, 'phone' => $m->phone,
            'member_number' => $m->member_id, 'status' => $m->status ?: ($m->is_active ? 'active' : 'inactive'),
            'joined_date' => (string) $m->created_at, 'contributions_total' => (float) $m->total_contributions,
            'penalties_total' => (float) $m->total_penalties, 'donations_total' => 0, 'avatar' => $m->profile_picture_url,
            'user_id' => $m->user_id,
        ];
    }

    public function memberDetail(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $m = DB::table('members as m')->leftJoin('auth_users as u', 'u.id', '=', 'm.user_id')->select('m.*', 'u.email')->where('m.id', $id)->first();
        if (! $m) {
            throw new HttpException(404, 'not_found', 'Member not found.');
        }

        return response()->json($this->member($m) + [
            'roles' => DB::table('user_roles')->where('user_id', $m->user_id)->where('is_active', 1)->pluck('role'),
            'unpaid_penalties' => (float) DB::table('penalties')->where('member_id', $id)->where('is_paid', 0)->sum('amount'),
        ]);
    }

    public function memberContributions(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('contributions')->where('member_id', $id)->orderByDesc('year')->orderByDesc('month')->get());
    }

    public function memberBeneficiaries(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('beneficiaries')->where('member_id', $id)->get());
    }

    public function contributions(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $q = DB::table('contributions as c')->leftJoin('members as m', 'm.id', '=', 'c.member_id')
            ->select('c.*', 'm.name as member_name', 'm.member_id as member_number')->orderByDesc('c.year')->orderByDesc('c.month');
        if ($s = $request->query('status')) {
            $q->where('c.status', $s === 'confirmed' ? 'paid' : $s);
        }

        return response()->json($this->paginate($q, $request));
    }

    public function beneficiaries(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->paginate(DB::table('beneficiaries as b')->leftJoin('members as m', 'm.id', '=', 'b.member_id')
            ->select('b.*', 'm.name as member_name')->orderBy('m.name'), $request));
    }

    public function defaulters(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(self::defaultersQuery()->orderByDesc('unpaid_months')->limit(1000)->get());
    }

    public function payments(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->paginate(DB::table('payments as p')->leftJoin('members as m', 'm.id', '=', 'p.member_id')
            ->select('p.*', 'm.name as member_name')->orderByDesc('p.received_at'), $request, 50));
    }

    public function unmatched(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('unmatched_payments')->where('resolved', 0)->orderByDesc('created_at')->limit(500)->get());
    }

    public function matchUnmatched(Request $request, string $id): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'treasurer']);
        $data = $request->validate(['member_id' => 'required|string|exists:members,id']);

        $result = DB::transaction(function () use ($id, $data, $identity) {
            $u = DB::table('unmatched_payments')->where('id', $id)->lockForUpdate()->first();
            if (! $u || $u->resolved) {
                throw new HttpException(422, 'already_resolved', 'This payment was already matched.');
            }
            $ref = (string) ($u->payment_id ?: 'UNMATCHED-'.$id);
            $posted = $this->lifecycle->postPayment('monthly_contribution', $data['member_id'], (float) $u->extracted_amount, $ref, null, 'manual_match');
            DB::table('unmatched_payments')->where('id', $id)->update(['resolved' => 1, 'resolved_by' => $identity['user_id'], 'resolved_at' => now()]);
            if ($u->payment_id) {
                DB::table('payments')->where('id', $u->payment_id)->update(['member_id' => $data['member_id'], 'matched' => 1]);
            }

            return $posted;
        });
        \App\Observers\FinancialChangeObserver::bump();
        $this->audit($request, 'unmatched_payment_matched', ['id' => $id], $data['member_id']);

        return response()->json($this->ok('Payment matched and posted to member.', ['result' => $result]));
    }

    public function bankUnmatched(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return app(\App\Http\Controllers\Banking\CoopAccountController::class)->unmatchedStatementTransactions($request);
    }

    public function reconcileBankUnmatched(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['admin', 'treasurer']);
        $response = app(\App\Http\Controllers\Banking\CoopAccountController::class)->reconcileUnmatchedStatementTransaction($request, $id);
        \App\Observers\FinancialChangeObserver::bump();

        return $response;
    }

    public function notifications(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('notifications')->where('user_id', $identity['user_id'])->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'message' => $n->message, 'type' => $n->type ?: 'info', 'read' => (bool) $n->is_read, 'date' => (string) $n->created_at]));
    }

    public function sendSms(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'secretary', 'chairperson']);
        $data = $request->validate([
            'message' => 'required|string|max:1600',
            'recipients' => 'required|array|min:1|max:10000',
            'recipients.*' => 'string|max:64',
        ]);
        // "all" / "active" shortcuts or explicit member ids / phone numbers.
        $r = $data['recipients'];
        if (in_array('all', $r, true) || in_array('active', $r, true)) {
            $q = DB::table('members')->whereNotNull('phone');
            if (in_array('active', $r, true)) {
                $q->where('is_active', 1);
            }
            $phones = $q->pluck('phone')->all();
        } else {
            $byId = DB::table('members')->whereIn('id', $r)->pluck('phone')->all();
            $phones = array_merge($byId, array_filter($r, fn ($x) => preg_match('/^\+?\d{9,13}$/', $x)));
        }
        $phones = array_values(array_unique(array_filter($phones)));
        $jobId = $this->uuid();
        foreach (array_chunk($phones, 200) as $chunk) {
            SendBulkSmsJob::dispatch($chunk, $data['message'], $jobId);
        }
        $this->audit($request, 'BULK_SMS_QUEUED', ['job_id' => $jobId, 'count' => count($phones), 'by' => $identity['user_id']]);

        return response()->json($this->ok('SMS queued for '.count($phones).' recipients.', ['job_id' => $jobId, 'queued' => count($phones)]), 202);
    }

    public function events(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('events')->orderByDesc('created_at')->limit(300)->get());
    }

    public function createEvent(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'secretary', 'chairperson']);
        $data = $request->validate([
            'title' => 'required|string|max:200', 'description' => 'nullable|string',
            'event_type' => 'nullable|string|max:40', 'departed_name' => 'nullable|string|max:150',
            'relationship' => 'nullable|string|max:60', 'related_member_id' => 'nullable|string',
            'contribution_amount' => 'nullable|numeric|min:0', 'scheduled_date' => 'nullable|date', 'date' => 'nullable|date',
        ]);
        $data['scheduled_date'] = $data['scheduled_date'] ?? ($data['date'] ?? null);
        unset($data['date']);
        $id = $this->uuid();
        DB::table('events')->insert($data + ['id' => $id, 'status' => 'active', 'created_by' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(DB::table('events')->where('id', $id)->first(), 201);
    }

    public function updateEvent(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['admin', 'secretary', 'chairperson']);
        $data = $request->validate([
            'title' => 'sometimes|string|max:200', 'description' => 'nullable|string', 'status' => 'nullable|string|max:20',
            'scheduled_date' => 'nullable|date', 'rescheduled_date' => 'nullable|date', 'reschedule_reason' => 'nullable|string|max:500',
            'contribution_amount' => 'nullable|numeric|min:0',
        ]);
        DB::table('events')->where('id', $id)->update($data + ['updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(DB::table('events')->where('id', $id)->first());
    }

    public function deleteEvent(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['admin']);
        DB::table('events')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('Event cancelled.'));
    }

    public function news(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $page = $this->paginate(DB::table('news')->orderByDesc('created_at'), $request, 20);
        $page['data'] = array_map(fn ($n) => MemberPortalController::mapNews($n), $page['data']);

        return response()->json($page);
    }

    public function createNews(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'secretary', 'chairperson']);
        $data = $request->validate(['title' => 'required|string|max:200', 'content' => 'required|string', 'status' => 'nullable|in:published,draft,archived', 'scheduled_date' => 'nullable|date']);
        $id = $this->uuid();
        DB::table('news')->insert($data + ['id' => $id, 'status' => $data['status'] ?? 'published', 'author_id' => $identity['user_id'], 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('news')->where('id', $id)->first();
        if (($row->status ?? '') === 'published' && class_exists(\App\Events\NewsPublished::class)) {
            try {
                event(new \App\Events\NewsPublished($id));
            } catch (\Throwable) {
            }
        }
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(MemberPortalController::mapNews($row), 201);
    }

    public function updateNews(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['admin', 'secretary', 'chairperson']);
        $data = $request->validate(['title' => 'sometimes|string|max:200', 'content' => 'sometimes|string', 'status' => 'nullable|in:published,draft,archived', 'scheduled_date' => 'nullable|date']);
        DB::table('news')->where('id', $id)->update($data + ['updated_at' => now()]);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(MemberPortalController::mapNews(DB::table('news')->where('id', $id)->first()));
    }

    public function deleteNews(Request $request, string $id): JsonResponse
    {
        $this->requireAnyRole($request, ['admin', 'secretary']);
        DB::table('news_read')->where('news_id', $id)->delete();
        DB::table('news')->where('id', $id)->delete();
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('News deleted.'));
    }

    public function penaltyPayments(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('penalty_payments as p')->leftJoin('members as m', 'm.id', '=', 'p.member_id')
            ->select('p.*', 'm.name as member_name')->orderByDesc('p.created_at')->limit(500)->get());
    }

    public function verifyPenaltyPayment(Request $request, string $id): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'treasurer']);
        DB::transaction(function () use ($id, $identity) {
            $p = DB::table('penalty_payments')->where('id', $id)->lockForUpdate()->first();
            if (! $p || $p->status === 'verified') {
                throw new HttpException(422, 'invalid_state', 'Payment not found or already verified.');
            }
            DB::table('penalty_payments')->where('id', $id)->update(['status' => 'verified', 'verified_by' => $identity['user_id'], 'verified_at' => now(), 'updated_at' => now()]);
            // Credits the penalty wallet and clears the oldest unpaid penalties (idempotent on reference).
            $this->lifecycle->postPayment('penalty', $p->member_id, (float) $p->amount, (string) ($p->reference_number ?: 'PEN-'.$id), null, 'manual_verify');
        });
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json($this->ok('Penalty payment verified and wallet updated.'));
    }

    public function donations(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json(DB::table('donation_payment_records as d')->leftJoin('members as m', 'm.id', '=', 'd.member_id')
            ->leftJoin('donation_campaigns as c', 'c.id', '=', 'd.campaign_id')
            ->select('d.*', 'm.name as member_name', 'c.title as campaign_title')->orderByDesc('d.created_at')->limit(1000)->get());
    }

    public function settings(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);
        $s = DB::table('welfare_settings')->first();

        return response()->json(array_map(fn ($v) => (string) $v, (array) ($s ?? [])));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, ['admin']);
        $data = $request->validate([
            'name' => 'sometimes|string|max:200', 'monthly_contribution_amount' => 'sometimes|numeric|min:0',
            'contribution_due_day' => 'sometimes|integer|min:1|max:28', 'penalty_amount' => 'sometimes|numeric|min:0',
            'penalty_grace_days' => 'sometimes|integer|min:0|max:60',
        ]);
        if (DB::table('welfare_settings')->exists()) {
            DB::table('welfare_settings')->limit(1)->update($data + ['updated_at' => now()]);
        } else {
            DB::table('welfare_settings')->insert($data + ['id' => $this->uuid(), 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Observers\FinancialChangeObserver::bump();
        $this->audit($request, 'welfare_settings_updated', $data);

        return response()->json($this->ok('Settings saved.'));
    }

    public function updateOfficeSignatures(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin']);
        $data = $request->validate(['*' => 'nullable|string|max:500']);
        foreach ($data as $role => $url) {
            DB::table('office_bearer_signatures')->updateOrInsert(['role' => $role], ['signature_url' => $url, 'updated_by' => $identity['user_id'], 'updated_at' => now()]);
        }

        return response()->json($this->ok('Signatures saved.'));
    }

    public function updateRegistrationSettings(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin']);
        $data = $request->validate([
            'registration_fee' => 'sometimes|numeric|min:0', 'active' => 'sometimes|boolean', 'show_on_login' => 'sometimes|boolean',
            'auto_approve' => 'sometimes|boolean', 'retiring_date' => 'nullable|date',
        ]);
        $data += ['updated_by' => $identity['user_id'], 'updated_at' => now()];
        if (DB::table('registration_config')->exists()) {
            DB::table('registration_config')->limit(1)->update($data);
        } else {
            DB::table('registration_config')->insert($data + ['id' => $this->uuid(), 'created_at' => now()]);
        }

        return response()->json($this->ok('Registration settings saved.'));
    }

    public function registrationApplicants(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->paginate(DB::table('member_registrations')->orderByDesc('created_at'), $request));
    }

    public function executiveBadges(Request $request): JsonResponse
    {
        $this->requireAnyRole($request, self::ROLES);

        return response()->json($this->paginate(DB::table('member_executive_roles as r')
            ->join('members as m', 'm.id', '=', 'r.member_id')->leftJoin('executive_badges as b', 'b.id', '=', 'r.badge_id')
            ->select('r.id', 'm.id as member_id', 'm.name', 'm.member_id as member_number', 'b.role_name', 'b.badge_url')->orderBy('b.role_name'), $request));
    }

    public function importMembers(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin', 'treasurer']);
        [$rows, $name] = $this->csvRows($request);
        $result = app(\App\Services\ImportService::class)->importMemberStatement($rows, 'excel_import', $name, $identity['user_id']);
        \App\Observers\FinancialChangeObserver::bump();

        return response()->json(['message' => 'Import completed.', 'imported' => (int) ($result['imported'] ?? count($rows)), 'data' => $result]);
    }

    public function importBeneficiaries(Request $request): JsonResponse
    {
        $identity = $this->requireAnyRole($request, ['admin']);
        [$rows, $name] = $this->csvRows($request);
        $result = app(\App\Services\ImportService::class)->importBeneficiaries($rows, 'beneficiary_import', $name, $identity['user_id']);

        return response()->json(['message' => 'Beneficiaries imported.', 'imported' => (int) ($result['imported'] ?? count($rows)), 'data' => $result]);
    }

    /** CSV upload -> associative rows (lowercased headers). */
    private function csvRows(Request $request): array
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);
        $file = $request->file('file');
        $fh = fopen($file->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($fh) ?: []);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if ($row = @array_combine($header, $r)) {
                $rows[] = $row;
            }
        }
        fclose($fh);

        return [$rows, $file->getClientOriginalName()];
    }
}
