<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\ContributionAdjustmentService;
use App\Services\FinancialReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContributionAdjustmentController extends Controller
{
    public function __construct(
        private FinancialReadModel $financialReadModel,
        private ContributionAdjustmentService $adjustments,
    ) {}

    public function index(): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');

        $members = DB::table('members')->orderBy('name')->get(['id', 'name', 'phone', 'member_id']);
        return response()->json([
            'members' => $members->map(fn (object $member): array => [
                ...((array) $member),
                'overview' => $this->financialReadModel->memberOverview((string) $member->id),
                'adjustments' => DB::table('contribution_adjustments')
                    ->where('member_id', $member->id)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        AuthService::requireRole('admin', 'super_admin', 'treasurer');
        $data = $request->validate([
            'member_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'direction' => ['required', 'in:add,reduce'],
            'category' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:1', 'max:160'],
        ]);

        $amountMinor = (int) round(abs((float) $data['amount']) * 100);
        if ($data['direction'] === 'reduce') {
            $amountMinor *= -1;
        }
        $identity = $request->attributes->get('identity') ?? [];
        $actorId = (string) ($identity['user_id'] ?? '');
        if ($actorId === '') {
            return response()->json(['message' => 'Authenticated actor is required.'], 401);
        }

        return response()->json($this->adjustments->create(
            $data['member_id'],
            $amountMinor,
            $data['category'] ?? 'other',
            trim($data['reason']),
            $actorId,
        ), 201);
    }
}
