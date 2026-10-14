<?php

namespace App\Services\CoopBank;

use App\Models\CoopTransferRequest;
use App\Models\CoopTransferResponse;
use App\Support\Phone;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoopBankTransactionService
{
    public function __construct(
        protected CoopBankClient $client
    ) {}

    public function initiateStkPush(array $paymentData): array
    {
        $messageReference = $this->generateMessageReference('STK');
        $phone = $this->normalizePhone($paymentData['phone'] ?? '');
        $amount = (float) $paymentData['amount'] ?? 0;
        $narration = $paymentData['narration'] ?? '';

        if ($amount <= 0) {
            throw new \App\Exceptions\CoopBankException('Amount must be greater than 0', 'invalid_amount');
        }

        if (!$this->isValidPhone($phone)) {
            throw new \App\Exceptions\CoopBankException('Invalid phone number format', 'invalid_phone');
        }

        $bankNarration = substr($narration, 0, 100);
        $internalRef = $paymentData['internal_reference'] ?? Uuid::v4();

        $originalPhone = $paymentData['phone'] ?? '';
        $normalizedPhone = $this->normalizePhone($originalPhone);
        $phoneLast4 = substr($normalizedPhone, -4);

        Log::info('STK push initiated', [
            'payment_type' => $paymentData['payment_type'] ?? 'unknown',
            'member_id' => $paymentData['member_id'] ?? null,
            'amount' => $amount,
            'original_phone' => $originalPhone,
            'normalized_phone' => $normalizedPhone,
            'phone_last4' => $phoneLast4,
            'message_reference' => $messageReference,
            'internal_reference' => $internalRef,
            'bank_reference' => $messageReference,
            'request_timestamp' => now()->toIso8601String(),
        ]);

        $payload = [
            'MessageReference' => $messageReference,
            'CallBackUrl' => config('coop_bank.callback_base_url'),
            'OperatorCode' => config('coop_bank.operator_code'),
            'TransactionCurrency' => 'KES',
            'MobileNumber' => $phone,
            'Narration' => $bankNarration,
            'Amount' => $amount,
            'MessageDateTime' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'OtherDetails' => [
                ['Name' => 'Source', 'Value' => 'KHWWC_WELFARE'],
            ],
        ];

        $transferRecord = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => $messageReference,
            'transfer_type' => 'STK',
            'status' => 'submitted',
            'destination_account' => $phone,
            'amount' => $amount,
            'currency' => 'KES',
            'narration_internal' => $narration,
            'narration_bank' => $bankNarration,
            'internal_reference' => $internalRef,
            'internal_table' => 'payment_records',
            'request_payload' => json_encode($payload),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (!$this->isConfigured()) {
            return [
                'success' => true,
                'status' => 'processing',
                'message_reference' => $messageReference,
                'checkout_request_id' => 'MOCK-' . $messageReference,
                'transfer_id' => $transferRecord->id,
                'message' => 'STK Push initiated (mock mode)',
            ];
        }

        $response = $this->client->request('POST', '/FT/stk/1.0.0', $payload);
        $body = $response['body'];

        if ($response['success']) {
            $checkoutRequestId = $body['CheckoutRequestID'] ?? $body['response']['CheckoutRequestID'] ?? null;

            CoopTransferRequest::where('id', $transferRecord->id)->update([
                'status' => 'processing',
                'updated_at' => now(),
            ]);

            CoopTransferResponse::create([
                'id' => Uuid::v4(),
                'transfer_request_id' => $transferRecord->id,
                'message_reference' => $messageReference,
                'transaction_id' => $checkoutRequestId,
                'status' => 'PENDING',
                'response_payload' => json_encode($body),
                'received_at' => now(),
            ]);

            Log::info('STK push accepted by bank', [
                'message_reference' => $messageReference,
                'checkout_request_id' => $checkoutRequestId,
                'transfer_id' => $transferRecord->id,
                'bank_status' => $body['MessageCode'] ?? null,
                'bank_description' => $body['MessageDescription'] ?? null,
                'response_time_ms' => $response['duration_ms'] ?? null,
            ]);

            return [
                'success' => true,
                'status' => 'processing',
                'message_reference' => $messageReference,
                'checkout_request_id' => $checkoutRequestId,
                'transfer_id' => $transferRecord->id,
                'bank_response' => $body,
            ];
        }

        $bankMessageDescription = $body['MessageDescription'] ?? 'STK push failed';
        $bankMessageCode = $body['MessageCode'] ?? null;

        if ($bankMessageCode === '1037' || $bankMessageCode === 1037) {
            CoopTransferRequest::where('id', $transferRecord->id)->update([
                'status' => 'processing',
                'error_message' => 'Customer action required',
                'updated_at' => now(),
            ]);

            Log::warning('STK push pending customer action', [
                'message_reference' => $messageReference,
                'transfer_id' => $transferRecord->id,
                'message_code' => $bankMessageCode,
                'message_description' => $bankMessageDescription,
            ]);

            return [
                'success' => true,
                'status' => 'processing',
                'message_reference' => $messageReference,
                'checkout_request_id' => $body['CheckoutRequestID'] ?? $body['response']['CheckoutRequestID'] ?? null,
                'transfer_id' => $transferRecord->id,
                'bank_response' => $body,
                'customer_action_required' => true,
            ];
        }

        CoopTransferRequest::where('id', $transferRecord->id)->update([
            'status' => 'failed',
            'error_message' => $bankMessageDescription,
            'updated_at' => now(),
        ]);

        throw new \App\Exceptions\CoopBankException(
            $bankMessageDescription,
            'stk_error',
            $bankMessageCode,
            true
        );
    }

    protected function isConfigured(): bool
    {
        $key = config('coop_bank.consumer_key');
        $secret = config('coop_bank.consumer_secret');
        return !empty($key) && !empty($secret) && config('coop_bank.enable_real_calls', false);
    }

    protected function generateMessageReference(string $prefix): string
    {
        $timestamp = gmdate('ymdHis');
        $random = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        return substr($prefix . $timestamp . $random, 0, 28);
    }

    protected function normalizePhone(string $phone): string
    {
        return Phone::normalize($phone);
    }

    protected function normalizeForStk(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strpos($digits, '254') === 0) {
            return substr($digits, 3);
        }
        if (strpos($digits, '0') === 0) {
            return substr($digits, 1);
        }
        return $digits;
    }

    protected function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '254')) {
            return strlen($digits) === 12;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return true;
        }

        return strlen($digits) === 12 && str_starts_with($digits, '254');
    }

    public function queryTransactionStatus(string $messageReference): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'unknown',
                'message' => 'Mock mode - no real bank call',
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
        ];

        $response = $this->client->request('POST', '/Enquiry/TransactionStatus_V3/3.0.0/', $payload);

        $body = $response['body'];

        return [
            'ResultCode' => $body['ResultCode'] ?? $body['result_code'] ?? -1,
            'ResultDesc' => $body['ResultDesc'] ?? $body['result_description'] ?? 'Unknown',
            'MpesaReceiptNumber' => $body['MpesaReceiptNumber'] ?? null,
            'response' => $body,
        ];
    }

    public function queryStkStatus(string $messageReference): array
    {
        if (! $this->isConfigured()) {
            return [
                'status' => 'pending',
                'provider_status' => 'pending',
                'message' => 'Mock mode - no real bank call',
            ];
        }

        $response = $this->client->request('POST', '/Enquiry/STK/1.0.0/', [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
        ]);
        $body = $response['body'];
        $providerStatus = $body['Status']
            ?? $body['TransactionStatus']
            ?? $body['TransactionState']
            ?? $body['MessageCode']
            ?? $body['ResultCode']
            ?? null;
        $description = (string) ($body['MessageDescription'] ?? $body['ResultDesc'] ?? $body['ResultDescription'] ?? '');

        return [
            'status' => $this->normalizeStkStatus($providerStatus, $description),
            'provider_status' => $providerStatus,
            'provider_code' => $body['MessageCode'] ?? $body['ResultCode'] ?? null,
            'message' => $description,
            'receipt' => $body['MpesaReceiptNumber'] ?? $body['ReceiptNumber'] ?? null,
            'response' => $body,
        ];
    }

    private function normalizeStkStatus(mixed $providerStatus, string $description): string
    {
        $value = strtoupper(trim((string) $providerStatus . ' ' . $description));
        if (str_contains($value, 'SUCCESS') || str_contains($value, 'COMPLETED') || str_contains($value, 'PROCESSED')) {
            return 'success';
        }
        if (str_contains($value, 'CANCEL')) {
            return 'cancelled';
        }
        if (str_contains($value, 'FAIL') || str_contains($value, 'REJECT') || str_contains($value, 'DECLIN')) {
            return 'failed';
        }
        if (str_contains($value, 'PENDING') || str_contains($value, 'PROCESS')) {
            return 'pending';
        }

        return 'unknown';
    }
}
