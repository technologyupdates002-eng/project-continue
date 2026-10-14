<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Services\WithdrawalApprovalService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberPayoutController extends Controller
{
    private const PAYOUT_TABLES = [
        'penalty' => ['penalty_withdrawals', 'withdrawal_signatories'],
        'donation' => ['donation_withdrawals', 'donation_withdrawal_signatories'],
        'operational' => ['operational_withdrawals', 'operational_withdrawal_signatories'],
    ];

    public function eligible(Request $request): JsonResponse
    {
        $this->assertTreasurerOrAdmin($request);
        $data = $request->validate([
            'payout_type' => ['nullable', 'string', 'in:wedding,death,death_member,death_spouse,death_child,death_parent,retirement,emergency'],
        ]);
        $payoutType = $data['payout_type'] ?? 'wedding';
        $eligibleAmount = $this->eligibleAmount($payoutType);

        $members = DB::table('members')
            ->where('is_active', 1)
            ->whereNotExists(function ($query) use ($payoutType) {
                $query->selectRaw('1')
                    ->from('payouts')
                    ->whereColumn('payouts.member_id', 'members.id')
                    ->where('payouts.payout_type', $payoutType)
                    ->where('payouts.status', 'paid');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'member_id as member_code', 'phone'])
            ->map(fn (object $member): array => [
                ...((array) $member),
                'eligible_amount' => $eligibleAmount,
            ]);

        return response()->json(['data' => $members]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertTreasurerOrAdmin($request);

        $payouts = DB::table('payouts')
            ->leftJoin('members', 'members.id', '=', 'payouts.member_id')
            ->orderByDesc('payouts.created_at')
            ->get([
                'payouts.*',
                'members.name as member_name',
                'members.member_id as member_code',
                'members.phone as member_phone',
            ]);

        $data = $payouts->map(function ($payout) {
            $withdrawal = null;
            $walletType = null;
            foreach (self::PAYOUT_TABLES as $type => [$table]) {
                $candidate = DB::table($table)->where('id', $payout->payment_reference)->first();
                if ($candidate) {
                    $withdrawal = $candidate;
                    $walletType = $type;
                    break;
                }
            }

            $signatories = [];
            if ($withdrawal && $walletType) {
                $signatoryTable = self::PAYOUT_TABLES[$walletType][1];
                $signatories = DB::table($signatoryTable)
                    ->where('withdrawal_id', $withdrawal->id)
                    ->get()
                    ->all();
            }

            return [
                ...((array) $payout),
                'wallet_type' => $walletType,
                'phone_number' => $withdrawal->phone_number ?? $payout->member_phone,
                'reason' => $withdrawal->reason ?? $payout->reason,
                'status' => $payout->status === 'paid' ? 'paid' : ($withdrawal->status ?? $payout->status),
                'signatories' => $signatories,
                'provider_reference' => $withdrawal->b2c_reference ?? $withdrawal->mpesa_receipt ?? null,
                'reference' => 'PAY-' . strtoupper(substr($payout->id, 0, 8)),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertTreasurerOrAdmin($request);
        $data = $request->validate([
            'member_id' => ['required', 'string', 'exists:members,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payout_type' => ['required', 'string', 'in:wedding,death,death_member,death_spouse,death_child,death_parent,retirement,emergency'],
            'death_relation' => ['nullable', 'string', 'in:member,spouse,child,parent'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'wallet_type' => ['required', 'string', 'in:penalty,donation,operational'],
        ]);

        $identity = $request->attributes->get('identity', []);
        $userId = (string) ($identity['user_id'] ?? '');
        if ($userId === '') {
            throw new HttpException(401, 'unauthenticated', 'Authenticated user identity is required.');
        }

        $type = $data['payout_type'];
        if ($type === 'death' && !empty($data['death_relation'])) {
            $type = $data['death_relation'] === 'member' ? 'death_member' : 'death_' . $data['death_relation'];
        }
        $walletType = $data['wallet_type'];
        [$withdrawalTable, $signatoryTable] = self::PAYOUT_TABLES[$walletType];
        $amount = round((float) $data['amount'], 2);

        $result = DB::transaction(function () use ($data, $type, $walletType, $withdrawalTable, $signatoryTable, $amount, $userId) {
            $member = DB::table('members')
                ->where('id', $data['member_id'])
                ->lockForUpdate()
                ->first();

            if (!$member || !(bool) $member->is_active) {
                throw new HttpException(422, 'member_not_eligible', 'Select an active member for this payout.');
            }

            $eligibleAmount = $this->eligibleAmount($type);
            if ($eligibleAmount <= 0) {
                throw new HttpException(422, 'payout_amount_not_configured', 'No payout amount is configured for this payout type.');
            }
            if ($amount > $eligibleAmount) {
                throw new HttpException(422, 'amount_exceeds_eligible', 'Payout amount exceeds the configured eligible amount.');
            }

            $existing = DB::table('payouts')
                ->where('member_id', $member->id)
                ->where('payout_type', $type)
                ->whereIn('status', ['pending', 'approved', 'paid'])
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                throw new HttpException(409, 'payout_already_exists', 'A payout request already exists for this member and payout type.');
            }

            $payoutId = Uuid::v4();
            $withdrawalId = Uuid::v4();
            $now = now();
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                $reason = ucfirst(str_replace('_', ' ', $type)) . ' payout';
            }
            $phone = $member->phone;

            DB::table('payouts')->insert([
                'id' => $payoutId,
                'member_id' => $member->id,
                'payout_type' => $type,
                'amount' => $amount,
                'eligible_amount' => $eligibleAmount,
                'reason' => $reason,
                'status' => 'pending',
                'created_by' => $userId,
                'payment_reference' => $withdrawalId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $withdrawal = [
                'id' => $withdrawalId,
                'amount' => $amount,
                'reason' => $reason,
                'phone_number' => $phone,
                'requested_by' => $userId,
                'status' => 'pending',
                'approval_stage' => 'secretary',
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($walletType === 'operational') {
                $withdrawal['category'] = 'payout';
                $withdrawal['expense_type'] = 'payout';
                $withdrawal['recipient_name'] = $member->name;
            }
            DB::table($withdrawalTable)->insert($withdrawal);

            foreach (WithdrawalApprovalService::APPROVAL_CHAIN as $role) {
                DB::table($signatoryTable)->insert([
                    'id' => Uuid::v4(),
                    'withdrawal_id' => $withdrawalId,
                    'signatory_role' => $role,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'id' => $payoutId,
                'withdrawal_id' => $withdrawalId,
                'member_id' => $member->id,
                'member_name' => $member->name,
                'member_code' => $member->member_id,
                'amount' => $amount,
                'eligible_amount' => $eligibleAmount,
                'payout_type' => $type,
                'status' => 'pending',
            ];
        });

        return response()->json(['data' => $result], 201);
    }

    private function eligibleAmount(string $type): float
    {
        $rules = json_decode((string) DB::table('organization_settings')->value('payout_rules'), true) ?: [];
        $deathFallback = str_starts_with($type, 'death_') ? ($rules['death'] ?? 0) : 0;

        return (float) ($rules[$type] ?? $deathFallback);
    }

    private function assertTreasurerOrAdmin(Request $request): void
    {
        $identity = $request->attributes->get('identity', []);
        $roles = $identity['roles'] ?? [];
        if (!array_intersect($roles, ['admin', 'super_admin', 'treasurer'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Treasurer or administrator access required.');
        }
    }
}
