<?php

namespace App\Console\Commands;

use App\Models\CoopTransferRequest;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankReconciliationService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Services\PaymentService;
use App\Services\WithdrawalApprovalService;
use Illuminate\Console\Command;

class CoopReconcileTransfers extends Command
{
    protected $signature = 'coop:reconcile-transfers {--limit=100}';
    protected $description = 'Check status of pending transfers from Co-operative Bank';

    public function handle(CoopBankTransferService $transferService, CoopBankAccountService $accountService, CoopBankReconciliationService $reconciliationService, PaymentService $paymentService, WithdrawalApprovalService $withdrawalService): int
    {
        $limit = (int) $this->option('limit');
        $pending = CoopTransferRequest::whereIn('status', ['processing', 'submitted', 'timeout'])
            ->where('transfer_type', '!=', 'STK')
            ->where('retry_count', '<', 5)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $pendingStk = CoopTransferRequest::whereIn('status', ['processing', 'submitted', 'timeout'])
            ->where('transfer_type', 'STK')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $this->info('Found '.($pending->count() + $pendingStk->count()).' pending transfers to check');

        $checked = 0;
        $updated = 0;

        foreach ($pending as $transfer) {
            try {
                $result = $transferService->queryTransactionStatus($transfer->message_reference);
                $checked++;

                $status = strtoupper($result['status'] ?? '');

                if ($status === 'SUCCESS') {
                    $transfer->update(['status' => 'successful']);
                    $updated++;
                    $this->line("  ✓ Transfer {$transfer->message_reference}: successful");

                    if ($transfer->transfer_type === 'B2C_MPESA') {
                        $withdrawalService->applyProviderOutcome($transfer->fresh());
                    } else {
                        $settled = $reconciliationService->settleSuccessfulTransfer($transfer);
                        if ($settled) {
                            $this->line("  → Business settlement applied for transfer {$transfer->message_reference}");
                        }
                    }
                } elseif ($status === 'FAILED') {
                    $transfer->update([
                        'status' => 'failed',
                        'error_message' => $result['response']['StatusDescription'] ?? 'Failed',
                    ]);
                    $updated++;
                    $this->line("  ✗ Transfer {$transfer->message_reference}: failed");
                    if ($transfer->transfer_type === 'B2C_MPESA') {
                        $withdrawalService->applyProviderOutcome($transfer->fresh());
                    }
                } elseif ($status === 'REVERSED') {
                    $transfer->update([
                        'status' => 'reversed',
                        'error_message' => $result['response']['StatusDescription'] ?? 'Provider reported reversal',
                    ]);
                    $updated++;
                    $this->line("  ! Transfer {$transfer->message_reference}: reversed; financial reconciliation required");
                    if ($transfer->transfer_type === 'B2C_MPESA') {
                        $withdrawalService->applyProviderOutcome($transfer->fresh());
                    }
                } else {
                    $this->line("  … Transfer {$transfer->message_reference}: {$status}");
                }
            } catch (\Throwable $e) {
                $this->warn("  ! Transfer {$transfer->message_reference}: error - " . $e->getMessage());
            }
        }

        foreach ($pendingStk as $transfer) {
            try {
                $bankStatus = $accountService->queryStkStatus($transfer->message_reference);
                $result = $paymentService->processStkBankStatus((string) $transfer->id, $bankStatus);
                $checked++;

                if (($result['status'] ?? null) === 'successful') {
                    $updated++;
                    $this->line("  ✓ STK {$transfer->message_reference}: successful and settled");
                } elseif (($result['status'] ?? null) === 'failed') {
                    $updated++;
                    $this->line("  ✗ STK {$transfer->message_reference}: failed");
                } else {
                    $this->line("  … STK {$transfer->message_reference}: ".($result['status'] ?? 'pending'));
                }
            } catch (\Throwable $e) {
                $this->warn("  ! STK {$transfer->message_reference}: error - ".$e->getMessage());
            }
        }

        $this->info("Checked {$checked} transfers, updated {$updated}");

        return 0;
    }
}
