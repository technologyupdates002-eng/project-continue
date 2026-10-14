<?php

namespace App\Jobs;

use App\Models\Contribution;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendDueReminderSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $memberId,
        protected string $contributionId,
        protected string $phone,
        protected float $amount,
        protected string $dueDate
    ) {}

    public function handle(SmsService $sms): void
    {
        $referenceId = $this->contributionId;
        $referenceType = 'contribution_reminder';

        if ($sms->wasSent($this->phone, $referenceType, $referenceId)) {
            return;
        }

        $message = sprintf(
            'Dear member, your KHWWC monthly contribution of KSh %.2f is due on %s. ' .
            'Please ensure you make your payment on time. Thank you.',
            $this->amount,
            $this->dueDate
        );

        $sms->send($this->phone, $message, $referenceType, $referenceId);
    }
}
