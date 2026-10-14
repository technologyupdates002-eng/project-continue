<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationalTopupRequest;
use App\Services\AuthService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class OperationalTopupController extends Controller
{
    public function __construct(
        private PaymentService $payments
    ) {}

    public function topup(OperationalTopupRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $this->assertStaffAccess($identity);

        $data = $request->validated();
        $normalizedPhone = AuthService::normalizePhone($data['phone_number']);
        $amount = (float) $data['amount'];

        $result = $this->payments->initiateStkPush($identity, [
            'paymentType' => 'operational',
            'member_id' => 'system',
            'amount' => $amount,
            'phone' => $normalizedPhone,
            'accountReference' => 'operational-system',
            'narration' => $data['narration'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'checkout_request_id' => $result['checkoutRequestId'] ?? null,
                'payment_id' => $result['payment_id'] ?? null,
                'provider_reference' => $result['provider_reference'] ?? null,
                'amount' => $result['amount'] ?? $amount,
                'status' => $result['status'] ?? 'pending',
            ],
            'message' => 'Top-up initiated. Please complete on your phone.',
        ]);
    }

    private function assertStaffAccess(array $identity): void
    {
        if (!AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required');
        }
    }
}
