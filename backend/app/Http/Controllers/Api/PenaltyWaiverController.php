<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\FinancialReadModel;
use App\Services\MemberStatsService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PenaltyWaiverController extends Controller
{
    public function __construct(
        private readonly FinancialReadModel $financialReadModel,
        private readonly MemberStatsService $memberStats,
    ) {}

    public function store(Request $request, string $memberId): JsonResponse
    {
        $identity = $request->attributes->get('identity', []);
        if (! AuthService::isAdmin($identity['roles'] ?? [])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $amountMinor = (int) round((float) $data['amount'] * 100);
        if ($amountMinor <= 0) {
            return response()->json(['message' => 'Waiver amount must be at least KES 0.01.'], 422);
        }

        $actorId = (string) ($identity['user_id'] ?? '');
        if ($actorId === '') {
            return response()->json(['message' => 'Authenticated actor is required.'], 401);
        }

        $waiver = DB::transaction(function () use ($memberId, $amountMinor, $data, $actorId): array {
            $member = DB::table('members')->where('id', $memberId)->lockForUpdate()->first();
            if (! $member) {
                abort(404, 'Member not found.');
            }

            $overview = $this->financialReadModel->memberOverview($memberId);
            $outstandingMinor = (int) ($overview['penalties_outstanding_minor'] ?? 0);
            if ($amountMinor > $outstandingMinor) {
                abort(422, 'Waiver amount exceeds the current outstanding penalty balance.');
            }

            $waiverId = Uuid::v4();
            DB::table('penalty_waivers')->insert([
                'id' => $waiverId,
                'member_id' => $memberId,
                'amount_minor' => $amountMinor,
                'reason' => trim($data['reason']),
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            return [
                'id' => $waiverId,
                'member_id' => $memberId,
                'amount_minor' => $amountMinor,
                'reason' => trim($data['reason']),
                'created_by' => $actorId,
            ];
        });

        $this->memberStats->forgetCache($memberId);
        $overview = $this->financialReadModel->memberOverview($memberId);

        return response()->json([
            'data' => [
                ...$waiver,
                'penalties_outstanding_minor' => (int) ($overview['penalties_outstanding_minor'] ?? 0),
            ],
        ], 201);
    }
}
