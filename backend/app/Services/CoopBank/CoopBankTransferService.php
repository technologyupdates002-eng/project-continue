<?php

namespace App\Services\CoopBank;

use App\Exceptions\CoopBankException;
use App\Exceptions\CoopTransferException;
use App\Models\CoopTransferRequest;
use App\Models\CoopTransferResponse;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoopBankTransferService
{
    public function __construct(
        protected CoopBankClient $client
    ) {}

    public function sendToMpesa(string $withdrawalId, string $internalTable, array $walletInfo, float $amount, string $recipientPhone, string $narration): array
    {
        if (config('app.env') === 'production') {
            throw new CoopBankException(
                'B2C provider transfers are disabled in production safety mode.',
                'configuration',
                'B2C_DISABLED'
            );
        }

        $this->validateAmount($amount);

        $phone = $this->normalizePhone($recipientPhone);
        $messageReference = $this->generateMessageReference('B2C_MPESA', $withdrawalId);
        $internalNarration = $narration;
        $bankNarration = $this->truncateNarration($narration);

        if (!$this->isConfigured()) {
            return $this->createMockTransfer($messageReference, $withdrawalId, $internalTable, $amount, $phone, $internalNarration, 'B2C_MPESA');
        }

        $payload = [
            'MessageReference' => $messageReference,
            'ISO2CountryCode' => 'KE',
            'CallBackUrl' => config('coop_bank.callback_base_url') . '/transfer',
            'Source' => [
                'AccountNumber' => config('coop_bank.account_number'),
                'Amount' => (string) $amount,
                'TransactionCurrency' => 'KES',
                'Narration' => $bankNarration,
            ],
            'Destinations' => [
                [
                    'ReferenceNumber' => $withdrawalId,
                    'MobileNumber' => $phone,
                    'Amount' => (string) $amount,
                    'Narration' => $bankNarration,
                ],
            ],
        ];

        $transferRecord = $this->createTransferRequest(
            $messageReference, 'B2C_MPESA', $withdrawalId, $internalTable,
            $amount, $phone, $internalNarration, $payload
        );

        try {
            $response = $this->client->request('POST', '/FundsTransfer/External/A2M/Mpesa_v2/2.0.0', $payload);

            if ($response['success']) {
                $this->updateTransferStatus($transferRecord->id, 'processing');
                $this->createTransferResponse($transferRecord->id, $messageReference, $response['body'], 'PENDING');

                return [
                    'success' => true,
                    'status' => 'processing',
                    'message_reference' => $messageReference,
                    'transfer_id' => $transferRecord->id,
                    'bank_response' => $response['body'],
                ];
            }

            $this->updateTransferStatus($transferRecord->id, 'failed', $response['body']['MessageDescription'] ?? 'Bank error');
            throw CoopTransferException::fromBankError(
                $response['body']['MessageCode'] ?? 'unknown',
                $response['body']['MessageDescription'] ?? 'Transfer failed',
                null
            );
        } catch (CoopBankException $e) {
            if (in_array($e->getErrorType(), ['connection_error', 'request_error', 'max_retries'], true)) {
                $this->updateTransferStatus($transferRecord->id, 'timeout', $e->getMessage());

                return [
                    'success' => true,
                    'status' => 'processing',
                    'message_reference' => $messageReference,
                    'transfer_id' => $transferRecord->id,
                    'bank_response' => null,
                    'outcome_unknown' => true,
                ];
            }

            $this->updateTransferStatus($transferRecord->id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    public function sendPesalink(string $withdrawalId, string $internalTable, float $amount, string $recipientAccount, string $bankCode, string $narration): array
    {
        $this->validateAmount($amount);
        $messageReference = $this->generateMessageReference('PESALINK', $withdrawalId);
        $bankNarration = $this->truncateNarration($narration);

        if (!$this->isConfigured()) {
            return $this->createMockTransfer($messageReference, $withdrawalId, $internalTable, $amount, $recipientAccount, $narration, 'PESALINK');
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'CallBackUrl' => config('coop_bank.callback_base_url') . '/transfer',
            'ISO2CountryCode' => 'KE',
            'Source' => [
                'AccountNumber' => config('coop_bank.account_number'),
                'Amount' => (string) $amount,
                'TransactionCurrency' => 'KES',
                'Narration' => $bankNarration,
            ],
            'Destinations' => [
                [
                    'ReferenceNumber' => $withdrawalId,
                    'AccountNumber' => $recipientAccount,
                    'BankCode' => $bankCode,
                    'Amount' => (string) $amount,
                    'TransactionCurrency' => 'KES',
                    'Narration' => $bankNarration,
                ],
            ],
        ];

        $transferRecord = $this->createTransferRequest(
            $messageReference, 'PESALINK', $withdrawalId, $internalTable,
            $amount, $recipientAccount, $narration, $payload
        );

        try {
            $response = $this->client->request('POST', '/FundsTransfer/External/PesaLinkBulk_v1/1.0.0/', $payload);

            if ($response['success']) {
                $this->updateTransferStatus($transferRecord->id, 'processing');
                $this->createTransferResponse($transferRecord->id, $messageReference, $response['body'], 'PENDING');

                return [
                    'success' => true,
                    'status' => 'processing',
                    'message_reference' => $messageReference,
                    'transfer_id' => $transferRecord->id,
                    'bank_response' => $response['body'],
                ];
            }

            $this->updateTransferStatus($transferRecord->id, 'failed', $response['body']['MessageDescription'] ?? 'Bank error');
            throw CoopTransferException::fromBankError(
                $response['body']['MessageCode'] ?? 'unknown',
                $response['body']['MessageDescription'] ?? 'Transfer failed',
                null
            );
        } catch (CoopBankException $e) {
            $this->updateTransferStatus($transferRecord->id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    public function internalTransfer(string $transferId, string $sourceAccount, string $destAccount, float $amount, string $narration): array
    {
        $this->validateAmount($amount);
        $messageReference = $this->generateMessageReference('IFT', $transferId);
        $bankNarration = $this->truncateNarration($narration);

        if (!$this->isConfigured()) {
            return $this->createMockTransfer($messageReference, $transferId, 'internal_transfer', $amount, $destAccount, $narration, 'IFT');
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'ISO2CountryCode' => 'KE',
            'CallBackUrl' => config('coop_bank.callback_base_url') . '/transfer',
            'Source' => [
                'AccountNumber' => $sourceAccount,
                'Amount' => (string) $amount,
                'TransactionCurrency' => 'KES',
                'Narration' => $bankNarration,
            ],
            'Destinations' => [
                [
                    'ReferenceNumber' => $transferId,
                    'AccountNumber' => $destAccount,
                    'Amount' => (string) $amount,
                    'TransactionCurrency' => 'KES',
                    'Narration' => $bankNarration,
                ],
            ],
        ];

        $transferRecord = $this->createTransferRequest(
            $messageReference, 'IFT', $transferId, 'internal_transfer',
            $amount, $destAccount, $narration, $payload
        );

        try {
            $response = $this->client->request('POST', '/FundsTransfer/Internal/A2A_v3/3.0.0', $payload);

            if ($response['success']) {
                $this->updateTransferStatus($transferRecord->id, 'processing');
                $this->createTransferResponse($transferRecord->id, $messageReference, $response['body'], 'PENDING');

                return [
                    'success' => true,
                    'status' => 'processing',
                    'message_reference' => $messageReference,
                    'transfer_id' => $transferRecord->id,
                    'bank_response' => $response['body'],
                ];
            }

            $this->updateTransferStatus($transferRecord->id, 'failed', $response['body']['MessageDescription'] ?? 'Bank error');
            throw CoopTransferException::fromBankError(
                $response['body']['MessageCode'] ?? 'unknown',
                $response['body']['MessageDescription'] ?? 'Transfer failed',
                null
            );
        } catch (CoopBankException $e) {
            $this->updateTransferStatus($transferRecord->id, 'failed', $e->getMessage());
            throw $e;
        }
    }

    public function validatePesalinkRecipient(string $accountNumber, string $bankCode): array
    {
        $messageReference = $this->generateMessageReference('VALIDATION', $accountNumber);

        if (!$this->isConfigured()) {
            return [
                'valid' => true,
                'message' => 'Mock validation passed',
                'message_reference' => $messageReference,
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'AccountNumber' => $accountNumber,
            'RecipientBankIdentifier' => $bankCode,
        ];

        $response = $this->client->request('POST', '/Enquiry/Validation/IPSL/1.0.0/', $payload);

        $messageCode = $response['body']['MessageCode'] ?? ($response['success'] ? '0' : '-2');
        $isSuccess = $messageCode === '0' || $messageCode === 0;

        return [
            'valid' => $isSuccess,
            'message_reference' => $messageReference,
            'message_code' => $messageCode,
            'message_description' => $response['body']['MessageDescription'] ?? 'Unknown',
            'bank_response' => $response['body'],
        ];
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

        $status = $body['Status'] ?? $body['status'] ?? null;
        if (!$status) {
            $resultCode = $body['ResultCode'] ?? $body['result_code'] ?? null;
            $messageCode = $body['MessageCode'] ?? null;
            $messageDescription = $body['MessageDescription'] ?? $body['error_description'] ?? null;

            if ($resultCode === 0 || $resultCode === '0' || $messageCode === '0' || $messageCode === 0) {
                $status = 'SUCCESS';
            } elseif ($messageCode === '1037' || stripos($messageDescription ?? '', 'No response from user') !== false) {
                $status = 'PENDING';
            } elseif (in_array($resultCode, ['1', 1, '-1', -1], true)) {
                $status = 'FAILED';
            } elseif ($messageCode || $messageDescription) {
                $status = 'FAILED';
            } else {
                $status = 'UNKNOWN';
            }
        }

        $isCustomerActionRequired = $messageCode === '1037' || stripos($messageDescription ?? '', 'No response from user') !== false;

        return [
            'status' => $status,
            'transaction_id' => $body['TransactionId'] ?? null,
            'amount' => (float) ($body['Amount'] ?? 0),
            'message_code' => $messageCode,
            'message_description' => $messageDescription,
            'is_customer_action_required' => $isCustomerActionRequired,
            'response' => $body,
        ];
    }

    public function processCallback(array $payload): array
    {
        $messageReference = $payload['MessageReference'] ?? null;

        if (!$messageReference) {
            throw new CoopBankException('MessageReference is required in callback', 'missing_reference');
        }

        $transferRequest = CoopTransferRequest::where('message_reference', $messageReference)->first();

        if (!$transferRequest) {
            Log::warning('CoopBank callback: message reference not found', [
                'message_reference' => $messageReference,
                'payload' => json_encode($payload),
            ]);
            return ['status' => 'reference_not_found', 'message' => 'No matching transfer found'];
        }

        $statusCode = $payload['Status'] ?? $payload['StatusCode'] ?? 'UNKNOWN';
        $isSuccess = ($statusCode === 'SUCCESS' || $statusCode === '0' || $statusCode === 0);

        DB::transaction(function () use ($transferRequest, $payload, $isSuccess) {
            $transactionId = $payload['TransactionId'] ?? null;

            $query = CoopTransferResponse::where('transfer_request_id', $transferRequest->id);

            if ($transactionId !== null) {
                $query->where('transaction_id', $transactionId);
            } else {
                $query->whereNull('transaction_id');
            }

            $existingResponse = $query->first();

            if (!$existingResponse) {
                $this->createTransferResponse(
                    $transferRequest->id,
                    $payload['MessageReference'] ?? $transferRequest->message_reference,
                    $payload,
                    $isSuccess ? 'SUCCESS' : 'FAILED'
                );
            }

            if ($isSuccess) {
                $this->updateTransferStatus($transferRequest->id, 'successful');
            } else {
                $this->updateTransferStatus(
                    $transferRequest->id,
                    'failed',
                    $payload['StatusDescription'] ?? $payload['MessageDescription'] ?? 'Transfer failed'
                );
            }
        });

        return [
            'status' => $isSuccess ? 'success' : 'failed',
            'transfer_id' => $transferRequest->id,
            'message' => $isSuccess ? 'Transfer completed successfully' : 'Transfer failed',
        ];
    }

    public function getTransfer(string $messageReference): ?CoopTransferRequest
    {
        return CoopTransferRequest::where('message_reference', $messageReference)->first();
    }

    public function getTransferByInternalRef(string $internalRef): ?CoopTransferRequest
    {
        return CoopTransferRequest::where('internal_reference', $internalRef)->first();
    }

    protected function isConfigured(): bool
    {
        $key = config('coop_bank.consumer_key');
        $secret = config('coop_bank.consumer_secret');
        return !empty($key) && !empty($secret) && config('coop_bank.enable_real_calls', false);
    }

    protected function generateMessageReference(string $type, string $internalId): string
    {
        $prefix = strtoupper(substr($type, 0, 4));
        $date = gmdate('ymd');
        $shortId = substr(substr($internalId, 0, 8), -4);
        $random = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $ref = "{$prefix}{$date}{$shortId}{$random}";

        return substr($ref, 0, 28);
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strpos($digits, '254') === 0) {
            return '0' . substr($digits, 3);
        }

        if (strpos($digits, '0') === 0) {
            return substr($digits, 0, 10);
        }

        if (strlen($digits) === 9 && strpos($digits, '7') === 0) {
            return '0' . $digits;
        }

        return $digits;
    }

    protected function truncateNarration(string $narration): string
    {
        return substr($narration, 0, 25);
    }

    protected function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new CoopTransferException('Amount must be greater than 0', 'invalid_amount');
        }
        if ($amount > 999999999) {
            throw new CoopTransferException('Amount exceeds maximum allowed', 'amount_too_large');
        }
    }

    protected function createTransferRequest(
        string $messageReference,
        string $transferType,
        string $internalRef,
        string $internalTable,
        float $amount,
        ?string $destinationAccount,
        string $narration,
        array $payload
    ): CoopTransferRequest {
        return DB::transaction(function () use ($messageReference, $transferType, $internalRef, $internalTable, $amount, $destinationAccount, $narration, $payload) {
            return CoopTransferRequest::create([
                'id' => Uuid::v4(),
                'message_reference' => $messageReference,
                'transfer_type' => $transferType,
                'status' => 'submitted',
                'destination_account' => $destinationAccount,
                'amount' => $amount,
                'currency' => 'KES',
                'narration_internal' => $narration,
                'narration_bank' => $this->truncateNarration($narration),
                'internal_reference' => $internalRef,
                'internal_table' => $internalTable,
                'request_payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    protected function createTransferResponse(
        string $transferRequestId,
        string $messageReference,
        array $responseBody,
        string $status
    ): CoopTransferResponse {
        return CoopTransferResponse::create([
            'id' => Uuid::v4(),
            'transfer_request_id' => $transferRequestId,
            'message_reference' => $messageReference,
            'transaction_id' => $responseBody['TransactionId'] ?? null,
            'status' => $status,
            'status_description' => $responseBody['StatusDescription'] ?? null,
            'response_payload' => json_encode($responseBody),
            'received_at' => now(),
            'processed_at' => now(),
        ]);
    }

    protected function updateTransferStatus(string $transferId, string $status, ?string $errorMessage = null): void
    {
        CoopTransferRequest::where('id', $transferId)->update([
            'status' => $status,
            'error_message' => $errorMessage,
            'updated_at' => now(),
        ]);
    }

    protected function createMockTransfer(
        string $messageReference,
        string $internalRef,
        string $internalTable,
        float $amount,
        ?string $destinationAccount,
        string $narration,
        string $transferType
    ): array {
        $transferRecord = CoopTransferRequest::create([
            'id' => Uuid::v4(),
            'message_reference' => $messageReference,
            'transfer_type' => $transferType,
            'status' => 'submitted',
            'destination_account' => $destinationAccount,
            'amount' => $amount,
            'currency' => 'KES',
            'narration_internal' => $narration,
            'narration_bank' => $this->truncateNarration($narration),
            'internal_reference' => $internalRef,
            'internal_table' => $internalTable,
            'request_payload' => json_encode(['mock' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'success' => true,
            'status' => 'processing',
            'message_reference' => $messageReference,
            'transfer_id' => $transferRecord->id,
            'bank_response' => ['mock' => true],
        ];
    }
}
