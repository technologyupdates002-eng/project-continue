<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNewsSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $memberId,
        protected string $newsId,
        protected string $phone,
        protected string $title,
        protected string $content
    ) {}

    public function handle(SmsService $sms): void
    {
        $referenceType = 'news';
        $referenceId = $this->newsId . ':' . $this->memberId;

        if ($sms->wasSent($this->phone, $referenceType, $referenceId)) {
            return;
        }

        $message = "KHCWW News: {$this->title}. " . strip_tags($this->content);
        $message = substr($message, 0, 160);

        $sms->send($this->phone, $message, $referenceType, $referenceId);
    }
}
