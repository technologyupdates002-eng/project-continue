<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use App\Services\PolicyService;
use App\Services\WalletService;
use App\Support\ViewAsMemberContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    private function rejectIfViewMode(Request $request): void
    {
        if (ViewAsMemberContext::from($request)->isActive()) {
            throw new HttpException(403, 'view_mode_read_only', 'View Member mode is read-only. Wallet operations are not permitted while viewing a member.');
        }
    }

    public function credit(Request $request, string $walletType): JsonResponse
    {
        $this->rejectIfViewMode($request);

        $identity = $request->attributes->get('identity');

        $this->assertWalletWrite($identity, $walletType);

        $data = $request->validate([
            'member_id' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'mpesa_receipt' => ['nullable', 'string', 'max:64'],
            'source' => ['required', 'string', 'max:100'],
            'reference_id' => ['nullable', 'string'],
            'reference_table' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->wallets->credit(
            $walletType,
            $data['member_id'],
            (float) $data['amount'],
            $data['mpesa_receipt'] ?? null,
            $data['source'],
            $data['reference_id'] ?? null,
            $data['reference_table'] ?? null,
            $data['notes'] ?? null
        );

        return response()->json($result, 200);
    }

    public function debit(Request $request, string $walletType): JsonResponse
    {
        $this->rejectIfViewMode($request);

        $identity = $request->attributes->get('identity');

        $this->assertWalletWrite($identity, $walletType);

        $data = $request->validate([
            'member_id' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
            'reference_id' => ['nullable', 'string'],
            'reference_table' => ['nullable', 'string'],
        ]);

        $result = $this->wallets->debit(
            $walletType,
            $data['member_id'],
            (float) $data['amount'],
            $data['reason'],
            $data['reference_id'] ?? null,
            $data['reference_table'] ?? null
        );

        return response()->json($result, 200);
    }

    public function balance(Request $request, string $walletType): JsonResponse
    {
        $balance = $this->wallets->getBalance($walletType);

        if (! $balance) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        return response()->json($balance, 200);
    }

    public function transactions(Request $request, string $walletType): JsonResponse
    {
        $identity = $request->attributes->get('identity');

        $walletTable = strtolower($walletType).'_wallet';
        $canReadAll = PolicyService::canReadAll('wallet_transactions', $identity['roles']);

        $ctx = ViewAsMemberContext::from($request);
        $memberId = $ctx->isActive()
            ? ($ctx->viewedMemberId() ?? $identity['member_id'])
            : $request->query('member_id');

        if (! $canReadAll && $memberId !== $identity['member_id']) {
            throw new HttpException(403, 'insufficient_permissions', 'You may only view your own wallet transactions.');
        }

        $limit = (int) $request->query('limit', 100);

        $transactions = $this->wallets->getTransactions($walletType, $memberId, $limit);

        return response()->json(['data' => $transactions], 200);
    }

    public function recentDonations(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity', []);
        if (! PolicyService::canReadAll('donation_payment_records', $identity['roles'] ?? [])) {
            throw new HttpException(403, 'insufficient_permissions', 'Only staff members can view recent donation payments.');
        }

        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        $payments = DB::table('donation_payment_records')
            ->leftJoin('members', 'members.id', '=', 'donation_payment_records.member_id')
            ->where('donation_payment_records.status', 'verified')
            ->orderByDesc('donation_payment_records.created_at')
            ->limit($limit)
            ->get([
                'donation_payment_records.id',
                'donation_payment_records.member_id',
                'donation_payment_records.campaign_id',
                'donation_payment_records.amount',
                'donation_payment_records.status',
                'donation_payment_records.payment_ref',
                'donation_payment_records.mpesa_transaction_id',
                'donation_payment_records.created_at',
                'members.name as member_name',
            ])
            ->map(static function (object $payment): array {
                $row = (array) $payment;
                $row['member_name'] = $row['member_name'] ?: 'Unknown';

                return $row;
            })
            ->all();

        return response()->json(['data' => $payments], 200);
    }

    private function assertWalletWrite(array $identity, string $walletType): void
    {
        $walletTable = strtolower($walletType).'_wallet';

        if (! PolicyService::tableExists($walletTable)) {
            throw new HttpException(422, 'invalid_wallet', 'Unknown wallet type.');
        }

        if (! PolicyService::canWriteAll($walletTable, $identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Only staff members can perform wallet operations.');
        }
    }
}
