<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HttpException;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/member/* — everything the member dashboard calls.
 */
class MemberPortalController extends PortalController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function dashboard(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        $userId = $this->identity($request)['user_id'];

        $data = $this->cached("member:dash:{$memberId}", function () use ($memberId, $userId) {
            $member = DB::table('members')->where('id', $memberId)->first();
            $contrib = DB::table('contributions')->where('member_id', $memberId)->where('status', 'paid')
                ->selectRaw('COALESCE(SUM(amount),0) as total, COUNT(*) as cnt')->first();
            $penalties = (float) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->sum('amount');
            $donations = (float) DB::table('donation_payment_records')->where('member_id', $memberId)
                ->whereIn('status', ['verified', 'completed', 'paid', 'confirmed'])->sum('amount');

            $settings = DB::table('welfare_settings')->first();
            $rate = (float) ($settings->monthly_contribution_amount ?? 300);

            return [
                'monthly_rate' => $rate,
                'grace_days' => (int) ($settings->penalty_grace_days ?? 30),
                'penalty_rate' => (float) ($settings->penalty_amount ?? 50),
                'months_covered' => $rate > 0 ? (int) floor(((float) $contrib->total) / $rate) : 0,
                'contributions_total' => (float) $contrib->total,
                'contributions_count' => (int) $contrib->cnt,
                'penalties_total' => $penalties,
                'donations_total' => $donations,
                'wallet_balance' => (float) $contrib->total,
                'upcoming_events' => DB::table('events')->where('status', '!=', 'cancelled')
                    ->where(fn ($q) => $q->whereNull('scheduled_date')->orWhere('scheduled_date', '>=', now()->toDateString()))
                    ->orderBy('scheduled_date')->limit(5)->get()->map(fn ($e) => $this->mapEvent($e))->all(),
                'recent_contributions' => DB::table('contributions')->where('member_id', $memberId)
                    ->orderByDesc('year')->orderByDesc('month')->limit(6)->get()
                    ->map(fn ($c) => $this->mapContribution($c, $member?->name))->all(),
                'unread_notifications' => DB::table('notifications')->where('user_id', $userId)->where('is_read', 0)->count(),
                'status' => (string) ($member->status ?? ($member && $member->is_active ? 'active' : 'inactive')),
            ];
        }, 20);

        return response()->json($data);
    }

    public function contributions(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        $name = DB::table('members')->where('id', $memberId)->value('name');
        $page = $this->paginate(
            DB::table('contributions')->where('member_id', $memberId)->orderByDesc('year')->orderByDesc('month'),
            $request
        );
        $page['data'] = array_map(fn ($c) => $this->mapContribution($c, $name), $page['data']);

        return response()->json($page);
    }

    public function beneficiaries(Request $request): JsonResponse
    {
        return response()->json(DB::table('beneficiaries')->where('member_id', $this->memberId($request))->orderBy('name')->get());
    }

    public function addBeneficiary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'relationship' => 'required|string|max:60',
            'phone' => 'nullable|string|max:20',
            'id_number' => 'nullable|string|max:30',
        ]);
        $memberId = $this->memberId($request);
        // Changes go through the admin review queue (beneficiary_requests).
        $id = $this->uuid();
        DB::table('beneficiary_requests')->insert([
            'id' => $id, 'member_id' => $memberId, 'request_type' => 'add', 'status' => 'pending',
            'beneficiary_name' => $data['name'], 'beneficiary_relationship' => $data['relationship'],
            'beneficiary_phone' => $data['phone'] ?? null, 'beneficiary_id_number' => $data['id_number'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => 'pending', 'message' => 'Beneficiary submitted for approval.'] + $data, 201);
    }

    public function updateBeneficiary(Request $request, string $id): JsonResponse
    {
        $memberId = $this->memberId($request);
        $b = DB::table('beneficiaries')->where('id', $id)->where('member_id', $memberId)->first();
        if (! $b) {
            throw new HttpException(404, 'not_found', 'Beneficiary not found.');
        }
        $data = $request->validate([
            'name' => 'sometimes|string|max:150', 'relationship' => 'sometimes|string|max:60',
            'phone' => 'nullable|string|max:20', 'id_number' => 'nullable|string|max:30',
        ]);
        DB::table('beneficiary_requests')->insert([
            'id' => $this->uuid(), 'member_id' => $memberId, 'request_type' => 'update', 'status' => 'pending',
            'beneficiary_id' => $id,
            'beneficiary_name' => $data['name'] ?? $b->name, 'beneficiary_relationship' => $data['relationship'] ?? $b->relationship,
            'beneficiary_phone' => $data['phone'] ?? $b->phone, 'beneficiary_id_number' => $data['id_number'] ?? $b->id_number,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Change submitted for approval.'] + (array) $b);
    }

    public function deleteBeneficiary(Request $request, string $id): JsonResponse
    {
        $memberId = $this->memberId($request);
        $b = DB::table('beneficiaries')->where('id', $id)->where('member_id', $memberId)->first();
        if (! $b) {
            throw new HttpException(404, 'not_found', 'Beneficiary not found.');
        }
        DB::table('beneficiary_requests')->insert([
            'id' => $this->uuid(), 'member_id' => $memberId, 'request_type' => 'remove', 'status' => 'pending',
            'beneficiary_id' => $id, 'beneficiary_name' => $b->name, 'beneficiary_relationship' => $b->relationship,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json($this->ok('Removal submitted for approval.'));
    }

    public function notifications(Request $request): JsonResponse
    {
        $rows = DB::table('notifications')->where('user_id', $this->identity($request)['user_id'])
            ->orderByDesc('created_at')->limit(100)->get()
            ->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'message' => $n->message,
                'type' => in_array($n->type, ['info', 'success', 'warning', 'error'], true) ? $n->type : 'info',
                'read' => (bool) $n->is_read, 'date' => (string) $n->created_at, 'target_url' => $n->target_url,
            ]);

        return response()->json($rows);
    }

    public function markNotificationRead(Request $request, string $id): JsonResponse
    {
        DB::table('notifications')->where('id', $id)->where('user_id', $this->identity($request)['user_id'])->update(['is_read' => 1]);

        return response()->json($this->ok('Marked as read.'));
    }

    public function documents(Request $request): JsonResponse
    {
        return response()->json(DB::table('documents')->where('member_id', $this->memberId($request))->orderByDesc('created_at')->get());
    }

    public function events(): JsonResponse
    {
        return response()->json($this->cached('member:events', fn () => DB::table('events')->orderByDesc('scheduled_date')->limit(100)->get()
            ->map(fn ($e) => $this->mapEvent($e))->all(), 60));
    }

    public function news(Request $request): JsonResponse
    {
        $page = $this->paginate(DB::table('news')->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'published'))->orderByDesc('created_at'), $request, 12);
        $page['data'] = array_map(fn ($n) => self::mapNews($n), $page['data']);

        return response()->json($page);
    }

    public function profile(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        $m = DB::table('members')->where('id', $memberId)->first();
        $u = DB::table('auth_users')->where('id', $m->user_id)->first();

        return response()->json($this->mapMember($m, $u));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        $data = $request->validate([
            'phone' => 'sometimes|string|max:20',
            'status_message' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:500',
        ]);
        $update = array_filter([
            'phone' => $data['phone'] ?? null,
            'status_message' => $data['status_message'] ?? null,
            'profile_picture_url' => $data['avatar'] ?? null,
        ], fn ($v) => $v !== null);
        if ($update) {
            DB::table('members')->where('id', $memberId)->update($update + ['updated_at' => now()]);
        }

        return $this->profile($request);
    }

    public function penalties(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);
        $name = DB::table('members')->where('id', $memberId)->value('name');

        return response()->json(DB::table('penalties')->where('member_id', $memberId)->orderByDesc('created_at')->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'member_id' => $p->member_id, 'member_name' => $name, 'amount' => (float) $p->amount,
                'reason' => $p->reason, 'status' => $p->is_paid ? 'paid' : 'unpaid', 'date' => (string) $p->created_at,
            ]));
    }

    public function payPenalty(Request $request, string $id): JsonResponse
    {
        $memberId = $this->memberId($request);
        $p = DB::table('penalties')->where('id', $id)->where('member_id', $memberId)->where('is_paid', 0)->first();
        if (! $p) {
            throw new HttpException(404, 'not_found', 'Unpaid penalty not found.');
        }
        $data = $request->validate(['phone' => 'required|string|max:20', 'method' => 'nullable|string']);

        return $this->stk($request, [
            'member_id' => $memberId, 'amount' => (float) $p->amount, 'phone' => $data['phone'],
            'paymentType' => 'penalty', 'penaltyIds' => [$id], 'accountReference' => 'PENALTY',
        ]);
    }

    public function donationCampaigns(): JsonResponse
    {
        return response()->json($this->cached('member:campaigns', fn () => DB::table('donation_campaigns')->where('active', 1)->orderByDesc('created_at')->get()->all(), 30));
    }

    public function donate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_id' => 'required|string', 'amount' => 'required|numeric|min:1',
            'phone' => 'required|string|max:20', 'method' => 'nullable|string',
        ]);
        if (! DB::table('donation_campaigns')->where('id', $data['campaign_id'])->where('active', 1)->exists()) {
            throw new HttpException(404, 'not_found', 'Campaign is not active.');
        }

        return $this->stk($request, [
            'member_id' => $this->memberId($request), 'amount' => (float) $data['amount'], 'phone' => $data['phone'],
            'paymentType' => 'donation', 'campaignId' => $data['campaign_id'], 'accountReference' => 'DONATION',
        ]);
    }

    public function withdrawalReceipts(Request $request): JsonResponse
    {
        $memberId = $this->memberId($request);

        return response()->json(DB::table('payouts')->where('member_id', $memberId)->orderByDesc('created_at')->get());
    }

    public function statement(Request $request)
    {
        $memberId = $this->memberId($request);
        $q = DB::table('contributions')->where('member_id', $memberId)->orderBy('year')->orderBy('month');
        if ($from = $request->query('from')) {
            $q->where('paid_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('paid_date', '<=', $to);
        }

        return $this->csv('statement.csv', ['Year', 'Month', 'Amount', 'Status', 'Paid date', 'Reference'],
            $q->get(['year', 'month', 'amount', 'status', 'paid_date', 'payment_ref']));
    }

    public function receipt(Request $request, string $id)
    {
        $c = DB::table('contributions')->where('id', $id)->where('member_id', $this->memberId($request))->first();
        if (! $c) {
            throw new HttpException(404, 'not_found', 'Receipt not found.');
        }

        return $this->csv("receipt-{$id}.csv", ['Receipt', 'Period', 'Amount', 'Status', 'Paid date', 'Reference'],
            [[$c->id, $c->month.'/'.$c->year, $c->amount, $c->status, $c->paid_date, $c->payment_ref]]);
    }

    public function executive(Request $request, string $role): JsonResponse
    {
        $this->requireAnyRole($request, [$role]);
        $w = $this->walletBalances();

        return response()->json([
            'role' => $role,
            'title' => ucwords(str_replace('_', ' ', $role)).' Dashboard',
            'stats' => [
                ['label' => 'Active members', 'value' => (string) DB::table('members')->where('is_active', 1)->count()],
                ['label' => 'Contributions', 'value' => 'KES '.number_format($w['contributions'])],
                ['label' => 'Pending minutes', 'value' => (string) DB::table('meeting_minutes')->whereIn('status', ['submitted', 'pending_review'])->count()],
            ],
            'actions' => [],
        ]);
    }

    private function stk(Request $request, array $payload): JsonResponse
    {
        $result = $this->payments->initiateStkPush($this->identity($request), $payload);

        return response()->json([
            'message' => $result['message'] ?? 'Check your phone to complete payment.',
            'reference' => $result['checkoutRequestId'] ?? ($result['CheckoutRequestID'] ?? null),
        ] + $result, ($result['success'] ?? false) ? 200 : 422);
    }

    private function mapContribution(object $c, ?string $name): array
    {
        return [
            'id' => $c->id, 'member_id' => $c->member_id, 'member_name' => $name, 'amount' => (float) $c->amount,
            'type' => 'monthly', 'status' => $c->status === 'paid' ? 'confirmed' : ($c->status === 'failed' ? 'failed' : 'pending'),
            'method' => $c->payment_ref ? 'mpesa' : 'manual', 'reference' => $c->payment_ref,
            'period' => sprintf('%04d-%02d', $c->year, $c->month), 'date' => (string) ($c->paid_date ?? $c->due_date ?? $c->created_at),
        ];
    }

    protected function mapEvent(object $e): array
    {
        $date = $e->rescheduled_date ?? $e->scheduled_date ?? $e->created_at;
        $status = $e->status === 'cancelled' ? 'cancelled' : ($date && strtotime((string) $date) < strtotime('today') ? 'past' : 'upcoming');

        return [
            'id' => $e->id, 'title' => $e->title, 'description' => $e->description, 'date' => (string) $date,
            'location' => '', 'status' => $status, 'attendees_count' => 0, 'event_type' => $e->event_type,
            'contribution_amount' => (float) ($e->contribution_amount ?? 0),
        ];
    }

    public static function mapNews(object $n): array
    {
        $text = trim(strip_tags((string) $n->content));

        return [
            'id' => $n->id, 'title' => $n->title, 'slug' => \Illuminate\Support\Str::slug((string) $n->title),
            'excerpt' => mb_substr($text, 0, 180), 'content' => $n->content, 'category' => 'news',
            'status' => $n->status ?: 'published', 'author' => 'KHWWC', 'date' => (string) ($n->scheduled_date ?? $n->created_at),
            'featured' => false,
        ];
    }

    protected function mapMember(object $m, ?object $u = null): array
    {
        return [
            'id' => $m->id, 'name' => $m->name, 'email' => $u->email ?? null, 'phone' => $m->phone,
            'member_number' => $m->member_id, 'status' => $m->status ?: ($m->is_active ? 'active' : 'inactive'),
            'joined_date' => (string) $m->created_at, 'contributions_total' => (float) $m->total_contributions,
            'penalties_total' => (float) $m->total_penalties, 'donations_total' => 0,
            'avatar' => $m->profile_picture_url, 'status_message' => $m->status_message, 'user_id' => $m->user_id,
        ];
    }
}
