<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HttpException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InitiatePaymentRequest;
use App\Http\Requests\MemberRegistrationRequest;
use App\Models\MemberRegistration;
use App\Models\RegistrationConfig;
use App\Models\RegistrationFee;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Services\SmsService;
use App\Support\Uuid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberRegistrationController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private SmsService $sms
    ) {}

    public function getConfig(): JsonResponse
    {
        $config = RegistrationConfig::first();

        if (!$config) {
            return response()->json([
                'data' => [
                    'registration_fee' => 0,
                    'active' => false,
                    'show_on_login' => false,
                    'auto_approve' => false,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'registration_fee' => (float) $config->registration_fee,
                'active' => (bool) $config->active,
                'show_on_login' => (bool) $config->show_on_login,
                'auto_approve' => (bool) $config->auto_approve,
                'retiring_date' => $config->retiring_date,
            ],
        ]);
    }

    public function register(MemberRegistrationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $config = RegistrationConfig::first();
        if (!$config || !$config->active) {
            throw new HttpException(422, 'registration_closed', 'Registration is currently closed');
        }

        $normalizedPhone = AuthService::normalizePhone($data['phone_number']);

        $existing = MemberRegistration::where('phone_number', $normalizedPhone)->first();
        if ($existing && $existing->status === 'approved') {
            throw new HttpException(422, 'already_registered', 'This phone number is already registered');
        }

        $existingMember = DB::table('members')
            ->where('phone', $normalizedPhone)
            ->first();
        if ($existingMember) {
            throw new HttpException(422, 'already_member', 'This phone number already belongs to an existing member');
        }

        $registration = MemberRegistration::create([
            'id' => Uuid::v4(),
            'full_name' => $data['full_name'],
            'phone_number' => $normalizedPhone,
            'department' => $data['department'],
            'working_location' => $data['working_location'],
            'date_of_birth' => $data['date_of_birth'],
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($config->auto_approve) {
            $registration->update([
                'status' => 'approved',
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => $registration,
            'message' => 'Registration submitted successfully',
        ], 201);
    }

    public function initiatePayment(InitiatePaymentRequest $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        $data = $request->validated();

        $registration = MemberRegistration::find($data['registration_id']);

        if (!$registration) {
            throw new HttpException(404, 'not_found', 'Registration not found');
        }

        if ($registration->payment_status === 'paid') {
            throw new HttpException(422, 'already_paid', 'Registration fee already paid');
        }

        $config = RegistrationConfig::first();
        $amount = $config ? (float) $config->registration_fee : 0;

        if ($amount <= 0) {
            throw new HttpException(422, 'no_fee', 'No registration fee configured');
        }

        $normalizedPhone = AuthService::normalizePhone($data['phone_number']);

        $existingFee = RegistrationFee::where('registration_id', $registration->id)
            ->where('status', 'pending')
            ->first();

        if ($existingFee) {
            return response()->json([
                'data' => [
                    'checkout_request_id' => $existingFee->mpesa_checkout_request_id,
                    'registration_id' => $registration->id,
                ],
                'message' => 'Payment already initiated',
            ]);
        }

        $checkoutRequestId = 'ws_REG_'.bin2hex(random_bytes(12));

        RegistrationFee::create([
            'id' => Uuid::v4(),
            'registration_id' => $registration->id,
            'amount' => $amount,
            'phone_number' => $normalizedPhone,
            'mpesa_checkout_request_id' => $checkoutRequestId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $mockProvider = app(\App\Services\MockPaymentProvider::class);
            $mockProvider->simulateStkPush($normalizedPhone, $amount, $checkoutRequestId);
        } catch (\Throwable $e) {
            Log::warning('Failed to initiate STK push for registration', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'data' => [
                'checkout_request_id' => $checkoutRequestId,
                'registration_id' => $registration->id,
            ],
            'message' => 'Payment initiated. Please complete on your phone.',
        ]);
    }

    public function getStatus(Request $request, string $id): JsonResponse
    {
        $registration = MemberRegistration::find($id);

        if (!$registration) {
            throw new HttpException(404, 'not_found', 'Registration not found');
        }

        return response()->json([
            'data' => [
                'id' => $registration->id,
                'full_name' => $registration->full_name,
                'status' => $registration->status,
                'payment_status' => $registration->payment_status,
                'created_at' => $registration->created_at,
                'approved_at' => $registration->approved_at,
            ],
        ]);
    }
}
