<?php

namespace App\Services;

class MockPaymentProvider
{
    private array $pendingPush = [];

    private array $bankTransactions = [
        ['id' => 'bank-1', 'amount' => 500, 'phone' => '254712345678', 'date' => '2024-01-15', 'status' => 'completed', 'narration' => 'School fees'],
        ['id' => 'bank-2', 'amount' => 1200, 'phone' => '254723456789', 'date' => '2024-01-16', 'status' => 'completed', 'narration' => 'Monthly contribution'],
        ['id' => 'bank-3', 'amount' => 300, 'phone' => '254734567890', 'date' => '2024-01-17', 'status' => 'pending', 'narration' => 'Penalty'],
    ];

    public function simulateStkPush(string $phone, float $amount, string $checkoutRequestId): void
    {
        $this->pendingPush[$checkoutRequestId] = [
            'phone' => $phone,
            'amount' => $amount,
            'status' => 'pending',
            'retries' => 0,
        ];
    }

    public function simulateCallback(string $checkoutRequestId, bool $success = true): array
    {
        if (! isset($this->pendingPush[$checkoutRequestId])) {
            return [
                'CheckoutRequestID' => $checkoutRequestId,
                'ResultCode' => $success ? 0 : 1,
                'ResultDesc' => $success ? 'Success' : 'Failed',
                'CallbackMetadata' => $success ? [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => 0],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'MOCK'.rand(10000, 99999)],
                        ['Name' => 'TransactionDate', 'Value' => (int) now()->format('YmdHis')],
                    ],
                ] : [],
            ];
        }

        $entry = $this->pendingPush[$checkoutRequestId];
        $entry['status'] = $success ? 'success' : 'failed';
        $entry['retries']++;

        return [
            'CheckoutRequestID' => $checkoutRequestId,
            'ResultCode' => $success ? 0 : 1,
            'ResultDesc' => $success ? 'Success' : 'Failed',
            'CallbackMetadata' => $success ? [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => (int) $entry['amount']],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'MOCK'.rand(10000, 99999)],
                    ['Name' => 'TransactionDate', 'Value' => (int) now()->format('YmdHis')],
                ],
            ] : [],
        ];
    }

    public function fetchBankTransactions(string $fromDate, string $toDate): array
    {
        return array_filter($this->bankTransactions, function ($tx) use ($fromDate, $toDate) {
            return $tx['date'] >= $fromDate && $tx['date'] <= $toDate;
        });
    }

    public function initiateB2C(float $amount, string $phone): array
    {
        return [
            'success' => true,
            'transactionId' => 'B2C'.bin2hex(random_bytes(8)),
            'message' => 'B2C transfer initiated',
        ];
    }

    public function isPending(string $checkoutRequestId): bool
    {
        return isset($this->pendingPush[$checkoutRequestId]) && $this->pendingPush[$checkoutRequestId]['status'] === 'pending';
    }
}
