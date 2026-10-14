<?php
/**
 * KHWWC STK Push Fix Script - CoopBankTransactionService.php rewrite
 * Run from khwwc directory: php fix-stk2.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = '/home/kirinyag/public_html/app1.kirinyagahealthcareworkerswelfare.co.ke/khwwc';

echo "=== Rewriting CoopBankTransactionService.php ===\n";

$path = $base . '/app/Services/CoopBank/CoopBankTransactionService.php';
$timestamp = date('Ymd_His');

// Backup current file
copy($path, $path . '.backup.' . $timestamp);
echo "Backed up corrupted file\n";

// Write complete corrected file
$content = <<<'PHPEOF'
<?php

namespace App\Services\CoopBank;

use App\Models\CoopTransferRequest;
use App\Models\CoopTransferResponse;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;

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

        $bankNarration = substr($narration, 0, 25);
        $internalRef = $paymentData['internal_reference'] ?? Uuid::v4();

        $payload = [
            'MessageReference' => $messageReference,
            'CallBackUrl' => config('coop_bank.callback_base_url') . '/stk',
            'OperatorCode' => config('coop_bank.operator_code'),
            'TransactionCurrency' => 'KES',
            'MobileNumber' => $phone,
            'Narration' => $bankNarration,
            'Amount' => (string) $amount,
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

            return [
                'success' => true,
                'status' => 'processing',
                'message_reference' => $messageReference,
                'checkout_request_id' => $checkoutRequestId,
                'transfer_id' => $transferRecord->id,
                'bank_response' => $body,
            ];
        }

        CoopTransferRequest::where('id', $transferRecord->id)->update([
            'status' => 'failed',
            'error_message' => $body['MessageDescription'] ?? 'STK push failed',
            'updated_at' => now(),
        ]);

        throw new \App\Exceptions\CoopBankException(
            $body['MessageDescription'] ?? 'STK push failed',
            'stk_error',
            $body['MessageCode'] ?? null,
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
        $digits = preg_replace('/\D/', '', $phone);

        if (strpos($digits, '254') === 0) {
            return $digits;
        }
        if (strpos($digits, '0') === 0) {
            return '254' . substr($digits, 1);
        }
        return $digits;
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
        return strlen($digits) === 12 && strpos($digits, '254') === 0;
    }
}
PHPEOF;

file_put_contents($path, $content);
echo "Rewrote CoopBankTransactionService.php\n";

// Verify
$syntax_check = shell_exec("php -l $path 2>&1");
echo "Syntax check: " . $syntax_check . "\n";

// Run Laravel optimization
echo "\n=== Laravel optimization ===\n";
chdir($base);
system('php artisan config:clear --no-interaction 2>&1');
system('php artisan route:clear --no-interaction 2>&1');
system('php artisan config:cache --no-interaction 2>&1');
system('php artisan route:cache --no-interaction 2>&1');

echo "\n=== Done ===\n";
echo "Verify: https://app1.kirinyagahealthcareworkerswelfare.co.ke/api/health\n";
