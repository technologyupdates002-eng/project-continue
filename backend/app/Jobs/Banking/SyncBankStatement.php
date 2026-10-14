<?php

namespace App\Jobs\Banking;

use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankReconciliationService;
use App\Services\RedisLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncBankStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        protected string $accountNumber,
        protected string $fromDate,
        protected string $toDate
    ) {}

    public function handle(CoopBankAccountService $accountService, RedisLockService $lock): void
    {
        $lockKey = "coop:statement-sync:{$this->accountNumber}";
        $result = $lock->run($lockKey, 300, function () use ($accountService) {
            return $accountService->syncTransactions($this->accountNumber, $this->fromDate, $this->toDate);
        });

        if ($result === null) {
            throw new \RuntimeException("Statement sync already running for account {$this->accountNumber}");
        }
    }
}
