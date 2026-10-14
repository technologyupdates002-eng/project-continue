<?php

namespace App\Services\CoopBank;

use App\Models\BankTransaction;
use App\Models\CoopAccountEnquiry;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoopBankAccountService
{
    public function __construct(
        protected CoopBankClient $client
    ) {}

    public function getAccountBalance(?string $accountNumber = null): array
    {
        $accountNumber = $accountNumber ?? config('coop_bank.account_number');
        $messageReference = $this->generateMessageReference();

        if (!$this->isConfigured()) {
            return [
                'status' => 'success',
                'message_reference' => $messageReference,
                'account_number' => $accountNumber,
                'available_balance' => 0,
                'booked_balance' => 0,
                'currency' => 'KES',
                'message_description' => 'Mock response - bank not configured',
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'AccountNumber' => $accountNumber,
        ];

        $this->logEnquiry($messageReference, $accountNumber, null);

        $response = $this->client->request('POST', '/Enquiry/AccountBalance_v2/2.0.0/', $payload);
        $body = $response['body'];

        $this->logEnquiry($messageReference, $accountNumber, $response['success'], $body);

        if (!$response['success']) {
            throw new \App\Exceptions\CoopBankException(
                $body['MessageDescription'] ?? 'Balance enquiry failed',
                'balance_error'
            );
        }

        return [
            'status' => 'success',
            'message_reference' => $messageReference,
            'account_number' => $accountNumber,
            'available_balance' => (float) ($body['AvailableBalance'] ?? 0),
            'booked_balance' => (float) ($body['BookBalance'] ?? 0),
            'currency' => $body['Currency'] ?? 'KES',
            'message_description' => $body['MessageDescription'] ?? 'Success',
        ];
    }

    public function getMiniStatement(?string $accountNumber = null, int $limit = 10): array
    {
        $accountNumber = $accountNumber ?? config('coop_bank.account_number');
        $messageReference = $this->generateMessageReference();

        if (!$this->isConfigured()) {
            return [
                'status' => 'success',
                'message_reference' => $messageReference,
                'account_number' => $accountNumber,
                'transactions' => [],
                'message_description' => 'Mock response - bank not configured',
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'AccountNumber' => $accountNumber,
        ];

        $this->logEnquiry($messageReference, $accountNumber, null);

        $response = $this->client->request('POST', '/Enquiry/MiniStatement/Account_v2/2.0.0/', $payload);
        $body = $response['body'];

        $this->logEnquiry($messageReference, $accountNumber, $response['success'], $body);

        if (!$response['success']) {
            throw new \App\Exceptions\CoopBankException(
                $body['MessageDescription'] ?? 'Mini statement failed',
                'statement_error'
            );
        }

        return [
            'status' => 'success',
            'message_reference' => $messageReference,
            'account_number' => $accountNumber,
            'transactions' => $body['Transactions'] ?? [],
            'message_description' => $body['MessageDescription'] ?? 'Success',
        ];
    }

    public function getStatement(string $accountNumber, string $startDate, string $endDate): array
    {
        $messageReference = $this->generateMessageReference();

        if (!$this->isConfigured()) {
            return [
                'status' => 'success',
                'message_reference' => $messageReference,
                'account_number' => $accountNumber,
                'transactions' => [],
                'message_description' => 'Mock response - bank not configured',
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
            'ISO2CountryCode' => 'KE',
            'AccountNumber' => $accountNumber,
            'StartDate' => $startDate,
            'EndDate' => $endDate,
        ];

        $this->logEnquiry($messageReference, $accountNumber, null);

        $response = $this->client->request('POST', '/Enquiry/AccountFullStatementPaginated/1.0.0/', $payload);
        $body = $response['body'];

        $this->logEnquiry($messageReference, $accountNumber, $response['success'], $body);

        if (!$response['success']) {
            throw new \App\Exceptions\CoopBankException(
                $body['MessageDescription'] ?? 'Statement failed',
                'statement_error'
            );
        }

        $transactions = $body['Transactions'] ?? $body['transactions'] ?? [];

        $this->storeBankTransactions($accountNumber, $transactions);

        return [
            'status' => 'success',
            'message_reference' => $messageReference,
            'account_number' => $accountNumber,
            'transaction_count' => count($transactions),
            'transactions' => $transactions,
            'message_description' => $body['MessageDescription'] ?? 'Success',
        ];
    }

    public function syncTransactions(string $accountNumber, string $fromDate, string $toDate): array
    {
        return $this->getStatement($accountNumber, $fromDate, $toDate);
    }

    public function getStatementAllPages(
        string $accountNumber,
        string $startDate,
        string $endDate,
        int $pageSize = 500,
        int $maxPages = 100
    ): array {
        $allTransactions = [];
        $pageNumber = 1;
        $totalPages = 1;
        $lastBalance = null;
        $messageReference = null;

        if (! $this->isConfigured()) {
            return [
                'status' => 'success',
                'message_reference' => $this->generateMessageReference(),
                'account_number' => $accountNumber,
                'transactions' => [],
                'total_pages' => 0,
                'total_transactions' => 0,
                'bank_balance' => null,
                'message_description' => 'Mock response - bank not configured',
            ];
        }

        while ($pageNumber <= $totalPages && $pageNumber <= $maxPages) {
            $messageReference = $this->generateMessageReference();

            $payload = [
                'MessageReference' => $messageReference,
                'UserId' => config('coop_bank.default_user_id'),
                'ISO2CountryCode' => 'KE',
                'AccountNumber' => $accountNumber,
                'StartDate' => $startDate,
                'EndDate' => $endDate,
                'PageNumber' => $pageNumber,
                'PageSize' => $pageSize,
            ];

            $this->logEnquiry($messageReference, $accountNumber, null);

            $response = $this->client->request(
                'POST',
                '/Enquiry/AccountFullStatementPaginated/1.0.0/',
                $payload
            );

            $body = $response['body'];

            $this->logEnquiry($messageReference, $accountNumber, $response['success'], $body);

            if (! $response['success']) {
                $message = (string) ($body['MessageDescription'] ?? $body['message'] ?? '');
                if (strcasecmp(trim($message), 'Sorry!! No records found') === 0) {
                    return [
                        'status' => 'success',
                        'message_reference' => $messageReference,
                        'account_number' => $accountNumber,
                        'transactions' => [],
                        'total_pages' => 0,
                        'total_transactions' => 0,
                        'bank_balance' => null,
                        'message_description' => $message,
                    ];
                }

                throw new \App\Exceptions\CoopBankException(
                    $message ?: 'Statement failed',
                    'statement_error'
                );
            }

            $transactions = $body['Transactions'] ?? $body['transactions'] ?? [];
            $totalPages = (int) ($body['TotalPages'] ?? $body['total_pages'] ?? (isset($body['TotalRecords']) && $body['TotalRecords'] ? (int) ceil($body['TotalRecords'] / $pageSize) : 1));

            if (empty($transactions) || count($transactions) < $pageSize) {
                $totalPages = $pageNumber;
            }

            foreach ($transactions as $tx) {
                $allTransactions[] = $tx;
                if (isset($tx['BookBalance']) || isset($tx['booked_balance']) || isset($tx['RunningBalance'])) {
                    $lastBalance = (float) ($tx['BookBalance'] ?? $tx['booked_balance'] ?? $tx['RunningBalance'] ?? null);
                }
            }

            if (count($transactions) === 0 || count($transactions) < $pageSize) {
                break;
            }

            $pageNumber++;
        }

        return [
            'status' => 'success',
            'message_reference' => $messageReference,
            'account_number' => $accountNumber,
            'transactions' => $allTransactions,
            'total_pages' => $pageNumber,
            'total_transactions' => count($allTransactions),
            'bank_balance' => $lastBalance,
            'message_description' => $body['MessageDescription'] ?? 'Success',
        ];
    }

    public function queryStkStatus(string $messageReference): array
    {
        if (!$this->isConfigured()) {
            return [
                'status' => 'unknown',
                'message_reference' => $messageReference,
                'message' => 'Mock response - bank not configured',
            ];
        }

        $payload = [
            'MessageReference' => $messageReference,
            'UserId' => config('coop_bank.default_user_id'),
        ];

        $response = $this->client->request('POST', '/Enquiry/STK/1.0.0/', $payload);
        $body = $response['body'];

        $status = $body['Status'] ?? $body['status'] ?? null;
        $messageDescription = (string) ($body['MessageDescription'] ?? $body['error_description'] ?? '');
        if (!$status) {
            $resultCode = $body['ResultCode'] ?? $body['result_code'] ?? null;
            $messageCode = $body['MessageCode'] ?? null;

            if (
                ($resultCode === 0 || $resultCode === '0' || $messageCode === '0' || $messageCode === 0)
                && preg_match('/FULL\s+SUCCESS|SUCCESSFULLY|COMPLETED|PROCESSED/i', $messageDescription)
            ) {
                $status = 'SUCCESS';
            } elseif ($messageCode === '1037' || stripos($messageDescription ?? '', 'No response from user') !== false) {
                $status = 'PENDING';
            } elseif (in_array($resultCode, ['1', 1, '-1', -1], true)) {
                $status = 'FAILED';
            } elseif (preg_match('/\b(FAILED|FAILURE|REJECTED|DECLINED|CANCELLED|CANCELED)\b/i', $messageDescription)) {
                $status = 'FAILED';
            } else {
                $status = 'PENDING';
            }
        }

        $isCustomerActionRequired = $messageCode === '1037' || stripos($messageDescription ?? '', 'No response from user') !== false;

        return [
            'status' => $status,
            'message_reference' => $messageReference,
            'transaction_id' => $body['TransactionId'] ?? null,
            'amount' => (float) ($body['Amount'] ?? 0),
            'message_code' => $messageCode,
            'message_description' => $messageDescription,
            'is_customer_action_required' => $isCustomerActionRequired,
            'response' => $body,
        ];
    }

    public function getReconciliationStatus(): array
    {
        $pendingTransfers = \App\Models\CoopTransferRequest::whereIn('status', ['processing', 'submitted'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $recentLogs = \App\Models\CoopReconciliationLog::orderBy('started_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'pending_transfers' => $pendingTransfers->count(),
            'transfers_by_status' => \App\Models\CoopTransferRequest::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'recent_transfers' => $pendingTransfers->map(function ($t) {
                return [
                    'id' => $t->id,
                    'message_reference' => $t->message_reference,
                    'transfer_type' => $t->transfer_type,
                    'status' => $t->status,
                    'amount' => (float) $t->amount,
                    'created_at' => $t->created_at,
                ];
            })->toArray(),
            'recent_reconciliation' => $recentLogs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'reconciliation_type' => $log->reconciliation_type,
                    'start_date' => $log->start_date,
                    'end_date' => $log->end_date,
                    'status' => $log->status,
                    'matched' => $log->matched_count,
                    'unmatched_bank' => $log->unmatched_bank_count,
                    'unmatched_internal' => $log->unmatched_internal_count,
                    'duplicates' => $log->duplicate_count,
                    'variance' => (float) $log->amount_variance,
                    'started_at' => $log->started_at,
                    'completed_at' => $log->completed_at,
                ];
            })->toArray(),
        ];
    }

    protected function isConfigured(): bool
    {
        $key = config('coop_bank.consumer_key');
        $secret = config('coop_bank.consumer_secret');
        return !empty($key) && !empty($secret) && config('coop_bank.enable_real_calls', false);
    }

    protected function generateMessageReference(): string
    {
        $timestamp = gmdate('ymdHis');
        $random = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        return substr('ENQ' . $timestamp . substr($random, 0, 4), 0, 28);
    }

    protected function logEnquiry(string $messageReference, string $accountNumber, $success, ?array $responseBody = null): void
    {
        CoopAccountEnquiry::create([
            'id' => Uuid::v4(),
            'message_reference' => $messageReference,
            'account_number' => $accountNumber,
            'no_of_transactions' => null,
            'response_payload' => $responseBody ? json_encode($responseBody) : null,
            'message_code' => $responseBody['MessageCode'] ?? null,
            'message_description' => $responseBody['MessageDescription'] ?? null,
            'status' => $success === null ? 'pending' : ($success ? 'completed' : 'error'),
            'error_message' => $success === false ? ($responseBody['MessageDescription'] ?? 'Unknown error') : null,
            'created_at' => now(),
        ]);
    }

    protected function storeBankTransactions(string $accountNumber, array $transactions): void
    {
        foreach ($transactions as $tx) {
            $txRef = $tx['TransactionReference'] ?? $tx['reference'] ?? '';

            if (!$txRef) {
                continue;
            }

            $existing = BankTransaction::where('transaction_reference', $txRef)->first();

            if ($existing) {
                continue;
            }

            BankTransaction::create([
                'id' => Uuid::v4(),
                'bank_name' => 'coop',
                'bank_transaction_reference' => $txRef,
                'account_number' => $accountNumber,
                'narration' => $tx['Narration'] ?? $tx['narration'] ?? '',
                'transaction_type' => $tx['TransactionType'] ?? $tx['type'] ?? '',
                'credit_amount' => (float) ($tx['CreditAmount'] ?? 0),
                'debit_amount' => (float) ($tx['DebitAmount'] ?? 0),
                'transaction_date' => $tx['TransactionDate'] ?? now()->toDateString(),
                'source' => 'COOP_ACCOUNT_ENQUIRY',
                'reconciliation_status' => 'unmatched',
                'created_at' => now(),
            ]);
        }
    }
}
