<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class Phase1QueueTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $payload
    ) {}

    public function handle(): void
    {
        $attempt = $this->attempts();

        if ($this->payload === 'fail_once' && $attempt === 1) {
            throw new \RuntimeException('Intentional failure for retry test');
        }

        \Illuminate\Support\Facades\Redis::set('khwwc:phase1:queue:result', json_encode([
            'payload' => $this->payload,
            'attempt' => $attempt,
            'completed_at' => now()->toIso8601String(),
        ]), 60);
    }
}
