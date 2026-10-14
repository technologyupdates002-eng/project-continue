<?php

namespace App\Jobs;

use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReconcilePendingStkTransaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FAST_DELAYS = [
        10,
        30,
        60,
        120,
        300,
        600,
        900,
        1800,
        1800,
    ];

    public function __construct(
        public string $transferId,
        public int $attempt = 1,
        public int $maxAttempts = 10
    ) {}

    public function handle(PaymentService $paymentService, CoopBankAccountService $accountService): void
    {
        $transfer = CoopTransferRequest::find($this->transferId);

        if (!$transfer || $transfer->transfer_type !== 'STK') {
            return;
        }

        if ($transfer->isComplete() && $transfer->status !== 'failed') {
            return;
        }

        try {
            $startTime = microtime(true);
            $bankStatus = $accountService->queryStkStatus($transfer->message_reference);
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            Log::info('STK reconciliation status check', [
                'transfer_id' => $this->transferId,
                'message_reference' => $transfer->message_reference,
                'attempt' => $this->attempt,
                'bank_status' => $bankStatus['status'] ?? null,
                'duration_ms' => $durationMs,
            ]);

            $result = $paymentService->processStkBankStatus($this->transferId, $bankStatus);

            if (in_array($result['status'] ?? '', ['successful', 'failed'], true)) {
                Log::info('STK reconciliation terminal status', [
                    'transfer_id' => $this->transferId,
                    'message_reference' => $transfer->message_reference,
                    'final_status' => $result['status'],
                    'attempt' => $this->attempt,
                    'duration_ms' => $durationMs,
                ]);
                return;
            }

            if ($this->attempt < $this->maxAttempts) {
                $delaySeconds = self::FAST_DELAYS[min($this->attempt - 1, count(self::FAST_DELAYS) - 1)];

                self::dispatch($this->transferId, $this->attempt + 1, $this->maxAttempts)
                    ->delay(now()->addSeconds($delaySeconds));

                Log::info('STK reconciliation retry scheduled', [
                    'transfer_id' => $this->transferId,
                    'message_reference' => $transfer->message_reference,
                    'next_attempt' => $this->attempt + 1,
                    'delay_seconds' => $delaySeconds,
                ]);
            } else {
                $transfer->update([
                    'status' => 'timeout',
                    'error_message' => 'STK status resolution timeout',
                    'updated_at' => now(),
                ]);

                Log::warning('STK reconciliation max attempts reached', [
                    'transfer_id' => $this->transferId,
                    'message_reference' => $transfer->message_reference,
                    'max_attempts' => $this->maxAttempts,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('STK reconciliation check failed', [
                'transfer_id' => $this->transferId,
                'message_reference' => $transfer->message_reference ?? null,
                'attempt' => $this->attempt,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempt < $this->maxAttempts) {
                $delaySeconds = self::FAST_DELAYS[min($this->attempt - 1, count(self::FAST_DELAYS) - 1)];

                self::dispatch($this->transferId, $this->attempt + 1, $this->maxAttempts)
                    ->delay(now()->addSeconds($delaySeconds));
            }
        }
    }
}
