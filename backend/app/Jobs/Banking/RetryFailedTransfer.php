<?php

namespace App\Jobs\Banking;

use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryFailedTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $transferId,
        protected int $attempt = 1
    ) {}

    public function handle(CoopBankTransferService $transferService): void
    {
        $transfer = CoopTransferRequest::find($this->transferId);
        if (!$transfer || !$transfer->isPending()) {
            return;
        }

        $transfer->update([
            'retry_count' => $transfer->retry_count + 1,
            'updated_at' => now(),
        ]);

        if ($transfer->retry_count >= 5) {
            $transfer->update(['status' => 'timeout']);
            return;
        }

        try {
            $result = $transferService->queryTransactionStatus($transfer->message_reference);

            $status = $result['status'] ?? 'unknown';

            if (strtoupper($status) === 'SUCCESS') {
                $transfer->update(['status' => 'successful']);
            } elseif (strtoupper($status) === 'FAILED') {
                $transfer->update(['status' => 'failed', 'error_message' => $result['response']['MessageDescription'] ?? 'Transfer failed']);
            } else {
                dispatch(new self($this->transferId, $this->attempt + 1))->delay(now()->addMinutes(5));
            }
        } catch (\Throwable $e) {
            if ($this->attempt < 5) {
                dispatch(new self($this->transferId, $this->attempt + 1))->delay(now()->addMinutes(5));
            } else {
                $transfer->update(['status' => 'timeout', 'error_message' => $e->getMessage()]);
            }
        }
    }
}
