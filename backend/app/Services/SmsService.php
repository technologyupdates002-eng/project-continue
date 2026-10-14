<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected string $provider;

    protected ?string $apiKey;

    protected ?string $senderId;

    protected ?string $baseUrl;

    public function __construct()
    {
        $this->provider = strtolower((string) config('services.sms.provider', env('SMS_PROVIDER', 'abancool')));

        if ($this->provider === 'talksasa') {
            $this->apiKey = (string) config('services.talksasa.api_key', env('TALKSASA_API_KEY'));
            $this->senderId = (string) config('services.talksasa.sender_id', env('TALKSASA_SENDER_ID', 'KHCWWELDARE'));
            $this->baseUrl = rtrim((string) config('services.talksasa.base_url', env('TALKSASA_BASE_URL', 'https://bulksms.talksasa.com/api/v3')), '/');
        } else {
            $this->apiKey = (string) config('services.abancool.api_key', env('ABANCOOL_API_KEY'));
            $this->senderId = (string) config('services.abancool.sender_id', env('ABANCOOL_SENDER_ID', 'KHCWWELDARE'));
            $this->baseUrl = rtrim((string) config('services.abancool.base_url', env('ABANCOOL_BASE_URL', 'https://api.abancool.tech/api/v1')), '/');
        }
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->baseUrl);
    }

    public function send(string $phone, string $message, string $referenceType = null, string $referenceId = null): array
    {
        $phone = $this->normalizePhone($phone);

        // Idempotency: skip if already sent successfully
        if ($referenceType && $referenceId && $this->wasSent($phone, $referenceType, $referenceId)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'already_sent'];
        }

        $logId = Uuid::v4();

        if ($this->isConfigured()) {
            return $this->sendViaProvider($logId, $phone, $message, $referenceType, $referenceId);
        }

        return $this->simulateSend($logId, $phone, $message, $referenceType, $referenceId);
    }

    protected function sendViaProvider(string $logId, string $phone, string $message, ?string $referenceType, ?string $referenceId): array
    {
        try {
            if ($this->provider === 'talksasa') {
                $response = Http::timeout(10)->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])->post($this->baseUrl . '/sms/send', [
                        'recipient' => preg_replace('/^\+/', '', $phone),
                        'sender_id' => $this->senderId,
                        'type' => 'plain',
                        'message' => $message,
                    ]);

                $body = $response->json();

                if ($response->successful() && ($body['status'] ?? '') === 'success') {
                    $providerRef = is_array($body['data'] ?? null)
                        ? ($body['data']['queue_uid'] ?? $body['data']['uid'] ?? $body['data'][0]['uid'] ?? null)
                        : null;
                    $this->logSuccess($logId, $phone, $message, $providerRef, $referenceType, $referenceId);

                    return ['success' => true, 'provider_ref' => $providerRef];
                }

                $this->logFailure($logId, $phone, $message, $body['message'] ?? $response->body(), $referenceType, $referenceId);

                return ['success' => false, 'error' => $body['message'] ?? 'Provider error'];
            }

            $response = Http::timeout(10)->post($this->baseUrl . '/sms/send', [
                'api_key' => $this->apiKey,
                'sender_id' => $this->senderId,
                'phone' => $phone,
                'message' => $message,
            ]);

            $body = $response->json();

            if ($response->successful() && ($body['success'] ?? false)) {
                $this->logSuccess($logId, $phone, $message, $body['ref'] ?? null, $referenceType, $referenceId);

                return ['success' => true, 'provider_ref' => $body['ref'] ?? null];
            }

            $this->logFailure($logId, $phone, $message, $body['message'] ?? $response->body(), $referenceType, $referenceId);

            return ['success' => false, 'error' => $body['message'] ?? 'Provider error'];
        } catch (\Throwable $e) {
            $this->logFailure($logId, $phone, $message, $e->getMessage(), $referenceType, $referenceId);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function simulateSend(string $logId, string $phone, string $message, ?string $referenceType, ?string $referenceId): array
    {
        $valid = preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;

        if ($valid) {
            $this->logSuccess($logId, $phone, $message, 'simulated:' . $logId, $referenceType, $referenceId);

            return ['success' => true, 'provider_ref' => 'simulated:' . $logId];
        }

        $this->logFailure($logId, $phone, $message, 'Invalid phone format', $referenceType, $referenceId);

        return ['success' => false, 'error' => 'Invalid phone format'];
    }

    protected function logSuccess(string $logId, string $phone, string $message, ?string $providerRef, ?string $referenceType, ?string $referenceId): void
    {
        DB::table('sms_logs')->insert([
            'id' => $logId,
            'recipient_phone' => $phone,
            'message' => $message,
            'status' => 'sent',
            'provider_ref' => $providerRef ?? '',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => now(),
        ]);
    }

    protected function logFailure(string $logId, string $phone, string $message, string $error, ?string $referenceType, ?string $referenceId): void
    {
        DB::table('sms_logs')->insert([
            'id' => $logId,
            'recipient_phone' => $phone,
            'message' => $message,
            'status' => 'failed',
            'provider_ref' => '',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => now(),
        ]);
    }

    public function wasSent(string $phone, string $referenceType, string $referenceId): bool
    {
        $phone = $this->normalizePhone($phone);

        return DB::table('sms_logs')
            ->where('recipient_phone', $phone)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'sent')
            ->exists();
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strpos($digits, '254') === 0) {
            return '+' . $digits;
        }

        if (strpos($digits, '0') === 0) {
            return '+254' . substr($digits, 1);
        }

        return '+254' . $digits;
    }
}
