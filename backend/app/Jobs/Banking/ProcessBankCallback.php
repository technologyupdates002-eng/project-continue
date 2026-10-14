<?php

namespace App\Jobs\Banking;

use App\Models\CoopCallback;
use App\Services\CoopBank\CoopBankTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBankCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $callbackId
    ) {}

    public function handle(CoopBankTransferService $transferService): void
    {
        $callback = CoopCallback::find($this->callbackId);
        if (!$callback) {
            return;
        }

        if ($callback->processing_status === 'success') {
            return;
        }

        $payload = is_array($callback->request_payload) ? $callback->request_payload : json_decode($callback->request_payload, true);

        if (!$payload) {
            $callback->update(['processing_status' => 'error', 'error_message' => 'Invalid payload']);
            return;
        }

        try {
            $result = $transferService->processCallback($payload);

            $callback->update([
                'processing_status' => 'success',
                'response_payload' => json_encode($result),
                'processed_at' => now(),
            ]);

            Log::info('CoopBank callback processed', [
                'callback_id' => $this->callbackId,
                'callback_type' => $callback->callback_type,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $callback->update([
                'processing_status' => 'error',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);

            Log::error('CoopBank callback processing failed', [
                'callback_id' => $this->callbackId,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }
}
