<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Services\CoopBank\CoopBankTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoopTransferController extends Controller
{
    public function __construct(
        protected CoopBankTransferService $transferService,
        protected \App\Services\WalletService $walletService,
        protected \App\Services\WithdrawalApprovalService $withdrawalService
    ) {}

    public function b2cWithdrawal(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertTreasurerOrAdmin($identity);

        $data = $request->validate([
            'withdrawal_id' => ['required', 'string'],
            'wallet_type' => ['required', 'in:penalty,donation,operational'],
            'amount' => ['required', 'numeric', 'min:1'],
            'phone_number' => ['required', 'string'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $walletType = strtolower($data['wallet_type']);
        $withdrawalId = $data['withdrawal_id'];
        $withdrawalTable = \App\Services\WithdrawalApprovalService::WALLET_TABLES[$walletType];
        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();
        if (!$withdrawal) {
            throw new \App\Exceptions\HttpException(404, 'withdrawal_not_found', 'Linked withdrawal not found.');
        }
        $requestedPhone = preg_replace('/\D+/', '', $data['phone_number']);
        $canonicalPhone = preg_replace('/\D+/', '', (string) $withdrawal->phone_number);
        if ((float) $data['amount'] !== (float) $withdrawal->amount || $requestedPhone !== $canonicalPhone) {
            throw new \App\Exceptions\HttpException(409, 'withdrawal_details_mismatch', 'B2C amount and beneficiary must match the approved withdrawal.');
        }

        $this->withdrawalService->setIdentity($identity);
        $this->withdrawalService->triggerB2CWithdrawal($walletType, $withdrawalId, $identity);
        $current = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();

        return response()->json([
            'success' => in_array($current->status, ['submitted', 'processing', 'completed'], true),
            'status' => $current->status,
            'transfer_id' => $current->bank_transfer_id ?? null,
            'message_reference' => $current->b2c_reference ?? null,
            'message' => 'Canonical withdrawal status returned.',
        ], 200);
    }

    public function pesalinkWithdrawal(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertTreasurerOrAdmin($identity);

        $data = $request->validate([
            'withdrawal_id' => ['required', 'string'],
            'wallet_type' => ['required', 'in:penalty,donation,operational'],
            'amount' => ['required', 'numeric', 'min:1'],
            'account_number' => ['required', 'string'],
            'bank_code' => ['required', 'string'],
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $walletType = strtolower($data['wallet_type']);
        $withdrawalId = $data['withdrawal_id'];
        $amount = (float) $data['amount'];

        $validation = $this->transferService->validatePesalinkRecipient(
            $data['account_number'],
            $data['bank_code']
        );

        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_recipient',
                'message' => 'Invalid bank account or bank code',
                'details' => $validation,
            ], 400);
        }

        try {
            $this->walletService->reserveFunds(
                $walletType,
                $withdrawalId,
                $amount,
                $walletType . '_withdrawals',
                "Pesalink withdrawal reservation for withdrawal #{$withdrawalId}"
            );
        } catch (\App\Exceptions\HttpException $e) {
            if ($e->getCode() === 422) {
                return response()->json([
                    'success' => false,
                    'error' => 'insufficient_available_balance',
                    'message' => 'Insufficient available wallet balance (funds already reserved)',
                ], 400);
            }
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'reservation_failed',
                'message' => $e->getMessage(),
            ], 500);
        }

        $result = $this->transferService->sendPesalink(
            $withdrawalId,
            $walletType . '_withdrawals',
            $amount,
            $data['account_number'],
            $data['bank_code'],
            $data['narration'] ?? sprintf('Withdrawal to %s', $data['account_number'])
        );

        if (!$result['success']) {
            $this->walletService->releaseReservedFunds(
                $walletType,
                $withdrawalId,
                $amount,
                $walletType . '_withdrawals',
                "Pesalink withdrawal failed, releasing reservation for withdrawal #{$withdrawalId}"
            );
        }

        return response()->json($result, 200);
    }

    public function status(Request $request, string $messageReference): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertTreasurerOrAdmin($identity);

        $transfer = $this->transferService->getTransfer($messageReference);

        if (!$transfer) {
            return response()->json(['error' => 'Transfer not found'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $transfer->id,
                'message_reference' => $transfer->message_reference,
                'transfer_type' => $transfer->transfer_type,
                'status' => $transfer->status,
                'amount' => (float) $transfer->amount,
                'currency' => $transfer->currency,
                'internal_reference' => $transfer->internal_reference,
                'created_at' => $transfer->created_at,
                'updated_at' => $transfer->updated_at,
                'latest_response' => $transfer->latestResponse() ? [
                    'transaction_id' => $transfer->latestResponse()->transaction_id,
                    'status' => $transfer->latestResponse()->status,
                    'status_description' => $transfer->latestResponse()->status_description,
                    'received_at' => $transfer->latestResponse()->received_at,
                ] : null,
            ],
        ], 200);
    }

    public function queryBankStatus(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertTreasurerOrAdmin($identity);

        $request->validate([
            'message_reference' => ['required', 'string'],
        ]);

        $result = $this->transferService->queryTransactionStatus($request->input('message_reference'));

        return response()->json($result, 200);
    }

    protected function assertTreasurerOrAdmin(array $identity): void
    {
        $roles = $identity['roles'] ?? [];

        if (!in_array('treasurer', $roles) && !in_array('admin', $roles) && !in_array('super_admin', $roles)) {
            throw new \App\Exceptions\HttpException(403, 'insufficient_permissions', 'Treasurer or admin access required');
        }
    }
}
