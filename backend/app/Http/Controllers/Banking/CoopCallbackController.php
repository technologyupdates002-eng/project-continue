<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Models\CoopCallback;
use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoopCallbackController extends Controller
{
    public function __construct(
        protected CoopBankTransferService $transferService,
        protected \App\Services\WithdrawalApprovalService $withdrawalService,
        protected ?\App\Services\SmsService $sms = null
    ) {}

    public function handleTransfer(Request $request)
    {
        $payload = $request->all();
        $callback = CoopCallback::create([
            'id' => \App\Support\Uuid::v4(),
            'callback_type' => 'TRANSFER',
            'message_reference' => $payload['MessageReference'] ?? null,
            'transaction_reference' => $payload['TransactionId'] ?? null,
            'request_payload' => json_encode($payload),
            'http_status' => 200,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        try {
            $result = $this->transferService->processCallback($payload);
            $callback->update([
                'processing_status' => 'success',
                'response_payload' => json_encode($result),
                'processed_at' => now(),
            ]);
            $this->processTransferWalletImpact($result);
            return response()->json(['success' => true, 'result' => $result], 200);
        } catch (\Throwable $e) {
            Log::error('CoopBank transfer callback error', ['error' => $e->getMessage()]);
            $callback->update([
                'processing_status' => 'error',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
            return response()->json(['success' => false, 'message' => 'Callback processing failed'], 200);
        }
    }

    public function handleIns(Request $request)
    {
        $payload = $request->all();
        $callback = CoopCallback::create([
            'id' => \App\Support\Uuid::v4(),
            'callback_type' => 'INS',
            'message_reference' => $payload['TransactionId'] ?? null,
            'transaction_reference' => $payload['TransactionReference'] ?? null,
            'request_payload' => json_encode($payload),
            'http_status' => 200,
            'processing_status' => 'pending',
            'received_at' => now(),
        ]);

        try {
            \App\Jobs\Banking\ProcessInsNotification::dispatch($payload);
            $callback->update(['processing_status' => 'dispatched', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('CoopBank INS callback error', ['error' => $e->getMessage()]);
            $callback->update([
                'processing_status' => 'error',
                'error_message' => $e->getMessage(),
                'processed_at' => now(),
            ]);
        }

        return response()->json(['success' => true], 200);
    }

    public function handleValidation(Request $request)
    {
        CoopCallback::create([
            'id' => \App\Support\Uuid::v4(),
            'callback_type' => 'VALIDATION',
            'message_reference' => $request->input('MessageReference'),
            'transaction_reference' => $request->input('TransactionId'),
            'request_payload' => json_encode($request->all()),
            'http_status' => 200,
            'processing_status' => 'success',
            'processed_at' => now(),
            'received_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Validation callback acknowledged'], 200);
    }

    protected function processTransferWalletImpact(array $result): void
    {
        $transfer = isset($result['transfer_id']) ? CoopTransferRequest::find($result['transfer_id']) : null;
        if (!$transfer) {
            return;
        }

        $this->withdrawalService->applyProviderOutcome($transfer);
        if ($transfer->status === 'successful') {
            $this->sendTransferSms($transfer->internal_table, $transfer->internal_reference, (float) $transfer->amount, true);
        } elseif ($transfer->status === 'failed') {
            $this->sendTransferSms($transfer->internal_table, $transfer->internal_reference, (float) $transfer->amount, false);
        }
    }

    private function sendTransferSms(string $internalTable, string $internalRef, float $amount, bool $success): void
    {
        if (!$this->sms) return;
        try {
            if (!in_array($internalTable, ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'], true)) return;
            $record = DB::table($internalTable)->where('id', $internalRef)->first();
            if (!$record || empty($record->phone)) return;
            $message = $success
                ? "Your withdrawal of KES " . number_format($amount, 0, '.', ',') . " has been processed successfully to M-Pesa. -KHCWW"
                : "Your withdrawal of KES " . number_format($amount, 0, '.', ',') . " failed. Funds have been returned to your wallet. -KHCWW";
            $this->sms->send($record->phone, $message, $success ? 'withdrawal_success' : 'withdrawal_failed', $internalRef);
        } catch (\Throwable $e) {
            Log::warning('Withdrawal SMS failed', ['reference' => $internalRef, 'error' => $e->getMessage()]);
        }
    }
}
