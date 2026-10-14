<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOverdueReminderSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $memberId,
        protected string $contributionId,
        protected string $phone,
        protected float $amount,
        protected string $dueDate,
        protected int $year,
        protected int $month
    ) {}

    public function handle(SmsService $sms): void
    {
        $referenceType = 'overdue_reminder';
        $referenceId = $this->contributionId;

        if ($sms->wasSent($this->phone, $referenceType, $referenceId)) {
            return;
        }

        $message = "Dear KHCWW member, your contribution of KSh " . number_format($this->amount, 2) . " for " . date('F Y', mktime(0, 0, 0, $this->month, 1, $this->year)) . " is overdue. Due date was " . date('d/m/Y', strtotime($this->dueDate)) . ". Please make payment immediately to avoid penalties.";

        $sms->send($this->phone, $message, $referenceType, $referenceId);
    }
}
