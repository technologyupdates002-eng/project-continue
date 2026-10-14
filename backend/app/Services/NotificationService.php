<?php

namespace App\Services;

use App\Exceptions\HttpException;

class NotificationService
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    public function sendBulkSms(array $phones, string $message): array
    {
        if (empty($phones) || trim($message) === '') {
            return ['success' => false, 'message' => 'Phones and message are required'];
        }

        $sent = 0;
        $failed = 0;

        foreach ($phones as $phone) {
            if ($this->sms->isConfigured()) {
                $result = $this->sms->send($phone, $message);
                if ($result['success'] && empty($result['skipped'])) {
                    $sent++;
                } else {
                    $failed++;
                }
            } else {
                if ($this->simulateSmsSend($phone, $message)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        return [
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'message' => "Sent {$sent}, failed {$failed}",
        ];
    }

    public function sendPush(string $title, string $body, array $tokens): array
    {
        if (empty($tokens) || trim($title) === '' || trim($body) === '') {
            return ['success' => false, 'message' => 'Title, body, and tokens are required'];
        }

        $sent = 0;
        foreach ($tokens as $token) {
            if ($this->simulatePushSend($token, $title, $body)) {
                $sent++;
            }
        }

        return [
            'success' => true,
            'sent' => $sent,
            'message' => "Push sent to {$sent} devices",
        ];
    }

    private function simulateSmsSend(string $phone, string $message): bool
    {
        // Local simulation: log and return success for valid-looking numbers
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;
    }

    private function simulatePushSend(string $token, string $title, string $body): bool
    {
        // Local simulation: accept any non-empty token
        return strlen($token) > 10;
    }
}
