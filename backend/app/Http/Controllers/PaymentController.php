<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpException;
use App\Services\PaymentService;
use App\Support\ViewAsMemberContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
    ) {}

    /**
     * Block mutating payment endpoints while an admin is viewing a member.
     */
    private function rejectIfViewMode(Request $request): void
    {
        if (ViewAsMemberContext::from($request)->isActive()) {
            throw new HttpException(403, 'view_mode_read_only', 'View Member mode is read-only. Payments cannot be initiated while viewing a member.');
        }
    }

    public function stkPush(Request $request): JsonResponse
    {
        $this->rejectIfViewMode($request);

        try {
            // Website may omit member_id / send account_ref: fill from the signed-in member.
            if (! $request->filled('member_id')) {
                $who = (array) $request->attributes->get('identity');
                $mid = $who['member_id'] ?? (! empty($who['user_id'])
                    ? \Illuminate\Support\Facades\DB::table('members')->where('user_id', $who['user_id'])->value('id')
                    : null);
                if ($mid) {
                    $request->merge(['member_id' => (string) $mid]);
                }
            }
            if (! $request->filled('accountReference') && $request->filled('account_ref')) {
                $request->merge(['accountReference' => $request->input('account_ref')]);
            }

            $data = $request->validate([
                'member_id' => ['required', 'string'],
                'amount' => ['required', 'numeric', 'min:1'],
                'phone' => ['required', 'string'],
                'accountReference' => ['nullable', 'string'],
                'transactionDesc' => ['nullable', 'string'],
                'paymentType' => ['required', 'in:penalty,donation,monthly_contribution,operational'],
                'penaltyIds' => ['nullable', 'array'],
                'campaignId' => ['nullable', 'string'],
            ]);

            $identity = $request->attributes->get('identity');

            $result = $this->payments->initiateStkPush($identity, $data);

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\App\Exceptions\CoopBankException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'bank_error_code' => $e->getBankErrorCode(),
            ], 422);
        }
    }

    public function legacyStkCallback(Request $request): JsonResponse
    {
        $payload = $request->all();

        $checkoutRequestId = $payload['Body']['stkCallback']['CheckoutRequestID']
            ?? $payload['stkCallback']['CheckoutRequestID']
            ?? $payload['CheckoutRequestID']
            ?? null;

        Log::info('Legacy STK callback received; status polling remains authoritative', [
            'checkout_request_id' => $checkoutRequestId,
        ]);

        return response()->json([
            'success' => true,
            'status' => 'ignored',
            'checkout_request_id' => $checkoutRequestId,
            'message' => 'Legacy STK callback ignored; use Co-op status polling for payment completion.',
        ], 200);
    }

    public function status(Request $request, string $checkoutRequestId): JsonResponse
    {
        $identity = $request->attributes->get('identity');

        $result = $this->payments->getStatus($identity, $checkoutRequestId);

        return response()->json($result, isset($result['error']) ? 404 : 200);
    }

    public function bankSync(Request $request): JsonResponse
    {
        $this->rejectIfViewMode($request);

        try {
            $data = $request->validate([
                'from_date' => ['required', 'date'],
                'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            ]);

            $identity = $request->attributes->get('identity');

            $result = $this->payments->bankSync($identity, $data['from_date'], $data['to_date']);

            return response()->json($result, $result['success'] ? 200 : 422);
        } catch (\App\Exceptions\CoopBankException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'bank_error_code' => $e->getBankErrorCode(),
            ], 422);
        }
    }

    public function reconcileStk(Request $request): JsonResponse
    {
        $this->rejectIfViewMode($request);

        try {
            $data = $request->validate([
                'from_date' => ['nullable', 'date'],
                'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            ]);

            $identity = $request->attributes->get('identity');
            $this->assertIsStaff($identity);

            $result = $this->payments->reconcileStkTransfers(
                $identity,
                $data['from_date'] ?? null,
                $data['to_date'] ?? null
            );

            return response()->json($result, 200);
        } catch (\App\Exceptions\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function b2cTransfer(Request $request): JsonResponse
    {
        $this->rejectIfViewMode($request);

        try {
            $data = $request->validate([
                'withdrawal_id' => ['required', 'string'],
                'wallet_type' => ['required', 'in:penalty,donation,operational'],
                'amount' => ['required', 'numeric', 'min:1'],
                'phone' => ['required', 'string'],
                'narration' => ['nullable', 'string', 'max:500'],
            ]);

            $identity = $request->attributes->get('identity');
            $this->assertIsStaff($identity);
            $withdrawalTable = \App\Services\WithdrawalApprovalService::WALLET_TABLES[$data['wallet_type']];
            $withdrawal = \Illuminate\Support\Facades\DB::table($withdrawalTable)
                ->where('id', $data['withdrawal_id'])
                ->first();
            if (!$withdrawal) {
                throw new HttpException(404, 'withdrawal_not_found', 'Linked withdrawal not found.');
            }

            $requestedPhone = preg_replace('/\D+/', '', $data['phone']);
            $canonicalPhone = preg_replace('/\D+/', '', (string) $withdrawal->phone_number);
            if ((float) $data['amount'] !== (float) $withdrawal->amount || $requestedPhone !== $canonicalPhone) {
                throw new HttpException(409, 'withdrawal_details_mismatch', 'B2C amount and beneficiary must match the approved withdrawal.');
            }

            $withdrawalService = app(\App\Services\WithdrawalApprovalService::class);
            $withdrawalService->setIdentity($identity);
            $withdrawalService->triggerB2CWithdrawal($data['wallet_type'], $data['withdrawal_id'], $identity);
            $current = \Illuminate\Support\Facades\DB::table($withdrawalTable)
                ->where('id', $data['withdrawal_id'])
                ->first();

            return response()->json([
                'success' => in_array($current->status, ['submitted', 'processing', 'completed'], true),
                'transactionId' => $current->bank_transfer_id ?? null,
                'message_reference' => $current->b2c_reference ?? null,
                'status' => $current->status,
                'message' => 'Canonical withdrawal status returned.',
            ]);
        } catch (\App\Exceptions\CoopBankException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'bank_error_code' => $e->getBankErrorCode(),
            ], 422);
        }
    }

    private function assertIsStaff(array $identity): void
    {
        if (!\App\Services\AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required.');
        }
    }
}
