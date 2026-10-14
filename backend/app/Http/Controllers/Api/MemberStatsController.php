<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ViewAsMemberContext;
use App\Services\FinancialReadModel;
use App\Services\MemberStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberStatsController extends Controller
{
    public function __construct(
        private MemberStatsService $statsService,
        private FinancialReadModel $financialReadModel
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ctx = ViewAsMemberContext::from($request);

        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];

        $memberId = $request->query('member_id');

        // In view-as mode, the viewed member is authoritative. A client
        // cannot switch the target member by changing the query parameter.
        if ($ctx->isActive()) {
            $memberId = $ctx->viewedMemberId() ?? $memberId;
        } elseif ($memberId) {
            $ownMemberId = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
            if ($memberId !== $ownMemberId && ! \App\Services\AuthService::isStaff($roles)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        if ($memberId) {
            return response()->json([
                'data' => $this->statsService->getStats($memberId),
            ]);
        }

        // Staff/admin users (treasurer, chairperson, etc.) get all members' stats
        if (\App\Services\AuthService::isStaff($roles)) {
            return response()->json([
                'data' => $this->statsService->getStats(null),
            ]);
        }

        $own = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
        if (! $own) {
            return response()->json(['data' => null]);
        }
        return response()->json([
            'data' => $this->statsService->getStats($own),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->statsService->getSummary(),
        ]);
    }

    public function defaulters(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if (! \App\Services\AuthService::isStaff($identity['roles'] ?? [])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'data' => $this->financialReadModel->defaulters(),
        ]);
    }

    public function executiveDashboard(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];

        if (! \App\Services\AuthService::isStaff($roles)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $summary = $this->financialReadModel->summary();

        return response()->json([
            'data' => [
                'active_members' => $summary['active_members'],
                'total_collected' => $summary['total_contributions'],
                'defaulters' => $summary['total_defaulters'],
                'penalty_members' => $summary['total_penalty_members'],
                'defaulters_list' => $this->financialReadModel->unpaidObligationMembers(),
                'penalty_members_list' => $this->financialReadModel->unpaidPenaltyMembers(),
                'recent_payments' => $this->financialReadModel->recentConfirmedPayments(10),
                'active_events' => \Illuminate\Support\Facades\DB::table('events')
                    ->where('status', 'active')
                    ->count(),
            ],
        ]);
    }

    public function statement(Request $request): JsonResponse
    {
        $ctx = ViewAsMemberContext::from($request);

        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];

        $memberId = $request->query('member_id');

        // In view-as mode, the viewed member is authoritative.
        if ($ctx->isActive()) {
            $memberId = $ctx->viewedMemberId() ?? $memberId;
        } elseif ($memberId) {
            $ownMemberId = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
            if ($memberId !== $ownMemberId && ! \App\Services\AuthService::isStaff($roles)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        if (! $memberId) {
            $memberId = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
        }
        if (! $memberId) {
            return response()->json(['error' => 'member_id is required'], 400);
        }

        return response()->json([
            'data' => [
                'member_id' => $memberId,
                'statement' => $this->financialReadModel->statement($memberId),
                'obligations' => $this->financialReadModel->obligations($memberId),
                'overview' => $this->financialReadModel->memberOverview($memberId),
            ],
        ]);
    }

    public function financial(Request $request): JsonResponse
    {
        $ctx = ViewAsMemberContext::from($request);
        $identity = $request->attributes->get('identity');
        $roles = $identity['roles'] ?? [];
        $memberId = $request->query('member_id');

        if ($ctx->isActive()) {
            $memberId = $ctx->viewedMemberId() ?? $memberId;
        } elseif ($memberId) {
            $ownMemberId = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
            if ($memberId !== $ownMemberId && ! \App\Services\AuthService::isStaff($roles)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
        }

        if (! $memberId) {
            $memberId = \App\Models\Member::where('user_id', $identity['user_id'])->value('id');
        }
        if (! $memberId) {
            return response()->json(['error' => 'member_id is required'], 400);
        }

        $overview = $this->financialReadModel->memberOverview($memberId);
        if ($overview === []) {
            return response()->json(['error' => 'Member not found'], 404);
        }

        return response()->json([
            'data' => [
                'overview' => $overview,
                'obligations' => $this->financialReadModel->obligations($memberId),
                'statement' => $this->financialReadModel->statement($memberId),
                'exceptions' => $this->financialReadModel->openExceptions($memberId),
            ],
        ]);
    }
}