<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one chunk of a bulk SMS off the request thread. SmsService::send
 * logs each message and skips numbers already sent for this job id, so
 * retries never double-send.
 */
class SendBulkSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param list<string> $phones */
    public function __construct(public array $phones, public string $message, public string $jobId)
    {
        $this->onQueue('sms');
    }

    public function handle(SmsService $sms): void
    {
        foreach ($this->phones as $phone) {
            if ($sms->wasSent($phone, 'bulk_sms', $this->jobId)) {
                continue;
            }
            try {
                $sms->send($phone, $this->message, 'bulk_sms', $this->jobId);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
