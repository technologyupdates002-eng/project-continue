<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\B2cTransferRequest;
use App\Services\AuthService;
use App\Services\WithdrawalApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class B2cTransferController extends Controller
{
    public function transfer(B2cTransferRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $data = $request->validated();
        $withdrawalId = $data['withdrawal_id'];
        $walletType = $data['wallet_type'];
        $withdrawalTable = WithdrawalApprovalService::WALLET_TABLES[$walletType];
        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();
        if (!$withdrawal) {
            throw new HttpException(404, 'withdrawal_not_found', 'Linked withdrawal not found.');
        }

        $requestedPhone = preg_replace('/\D+/', '', $data['phone_number']);
        $canonicalPhone = preg_replace('/\D+/', '', (string) $withdrawal->phone_number);
        if ((float) $data['amount'] !== (float) $withdrawal->amount || $requestedPhone !== $canonicalPhone) {
            throw new HttpException(409, 'withdrawal_details_mismatch', 'B2C amount and beneficiary must match the approved withdrawal.');
        }

        $approvalService = app(WithdrawalApprovalService::class);
        $approvalService->setIdentity($identity);
        $approvalService->triggerB2CWithdrawal($walletType, $withdrawalId, $identity);

        $current = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();
        return response()->json([
            'data' => [
                'withdrawal_id' => $withdrawalId,
                'transfer_id' => $current->bank_transfer_id ?? null,
                'message_reference' => $current->b2c_reference ?? null,
                'status' => $current->status,
            ],
            'message' => 'Canonical withdrawal status returned.',
        ]);
    }

    private function assertStaffAccess(array $identity): void
    {
        if (!AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required');
        }
    }
}
