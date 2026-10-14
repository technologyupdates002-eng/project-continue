<?php

namespace App\Jobs\Banking;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LogicException;

class ExecuteWithdrawalB2CJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 120;

    public function __construct(
        protected string $transactionId,
        protected string $walletType,
        protected string $withdrawalId,
        protected float $amount,
        protected string $phoneNumber,
        protected string $narration,
        protected string $initiatedBy
    ) {}

    public function handle(): void
    {
        Log::critical('Legacy withdrawal B2C job cannot submit transfers; use the canonical withdrawal approval service.', [
            'transaction_id' => $this->transactionId,
            'withdrawal_id' => $this->withdrawalId,
            'wallet_type' => $this->walletType,
        ]);

        throw new LogicException('Legacy withdrawal B2C job is disabled to prevent bypassing canonical approvals, signatures, and idempotency.');
    }
}
