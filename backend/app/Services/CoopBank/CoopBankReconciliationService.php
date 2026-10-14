<?php

namespace App\Services\CoopBank;

use App\Models\CoopTransferRequest;
use App\Services\MemberFinancialProjectionService;
use App\Services\PaymentEngine;
use App\Services\PaymentLifecycleService;
use App\Services\SmsService;
use App\Services\Statement\PendingStkBankReconciliation;
use App\Services\WalletService;
use App\Support\Phone;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoopBankReconciliationService
{
    public function __construct(
        protected CoopBankAccountService $accountService,
        protected readonly PaymentEngine $paymentEngine,
        protected readonly PaymentLifecycleService $paymentLifecycle,
        protected readonly PendingStkBankReconciliation $pendingStkReconciliation,
    ) {}

    public function reconcileStatement(string $accountNumber, string $fromDate, string $toDate): array
    {
        $startedAt = now();

        $logId = Uuid::v4();
        DB::table('coop_reconciliation_logs')->insert([
            'id' => $logId,
            'reconciliation_type' => 'DAILY',
            'start_date' => $fromDate,
            'end_date' => $toDate,
            'status' => 'in_progress',
            'started_at' => $startedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $syncResult = $this->accountService->syncTransactions($accountNumber, $fromDate, $toDate);

            $bankTransactions = $syncResult['transactions'] ?? [];
            $bankCount = count($bankTransactions);

            $totalInternal = DB::table('payments')
                ->whereBetween('created_at', ["{$fromDate} 00:00:00", "{$toDate} 23:59:59"])
                ->count();

            $matched = 0;
            $unmatchedBank = 0;
            $duplicate = 0;
            $mismatches = [];

            foreach ($bankTransactions as $tx) {
                $txRef = $tx['TransactionReference'] ?? $tx['reference'] ?? '';
                if (! $txRef) {
                    continue;
                }

                $existing = DB::table('bank_transactions')
                    ->where('transaction_reference', $txRef)
                    ->first();

                if ($existing) {
                    $duplicate++;

                    continue;
                }

                $match = $this->tryMatchTransaction($tx, $fromDate, $toDate);
                if ($match) {
                    $matched++;
                } else {
                    $unmatchedBank++;

                    $bankAmount = (float) ($tx['CreditAmount'] ?? $tx['DebitAmount'] ?? 0);
                    $bankNarration = $tx['Narration'] ?? '';

                    if ($bankAmount > 0 && $bankNarration !== '') {
                        $mismatches[] = [
                            'transaction_reference' => $txRef,
                            'amount' => $bankAmount,
                            'narration' => $bankNarration,
                            'reason' => 'no matching internal transaction',
                        ];
                    }
                }
            }

            $completedAt = now();

            DB::table('coop_reconciliation_logs')->where('id', $logId)->update([
                'status' => 'success',
                'total_bank_transactions' => $bankCount,
                'total_internal_transactions' => $totalInternal,
                'matched_count' => $matched,
                'unmatched_bank_count' => $unmatchedBank,
                'unmatched_internal_count' => max(0, $totalInternal - $matched),
                'duplicate_count' => $duplicate,
                'amount_variance' => 0,
                'report_payload' => json_encode([
                    'mismatches' => $mismatches,
                    'started_at' => $startedAt->toDateTimeString(),
                    'completed_at' => $completedAt->toDateTimeString(),
                ]),
                'completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

            return [
                'status' => 'success',
                'reconciliation' => [
                    'type' => 'DAILY',
                    'start_date' => $fromDate,
                    'end_date' => $toDate,
                    'bank_transactions' => $bankCount,
                    'internal_transactions' => $totalInternal,
                    'matched' => $matched,
                    'unmatched_bank' => $unmatchedBank,
                    'unmatched_internal' => max(0, $totalInternal - $matched),
                    'duplicates' => $duplicate,
                    'variance' => 0,
                    'started_at' => $startedAt->toDateTimeString(),
                    'completed_at' => $completedAt->toDateTimeString(),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('CoopBank reconciliation failed', [
                'error' => $e->getMessage(),
                'account' => $accountNumber,
                'from' => $fromDate,
                'to' => $toDate,
            ]);

            DB::table('coop_reconciliation_logs')->where('id', $logId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'started_at' => $startedAt->toDateTimeString(),
                'completed_at' => now()->toDateTimeString(),
            ];
        }
    }

    public function tryMatchTransaction(array $bankTx, string $fromDate, string $toDate): bool
    {
        $amount = (float) ($bankTx['CreditAmount'] ?? $bankTx['DebitAmount'] ?? 0);
        $narration = $bankTx['Narration'] ?? '';
        $bankRef = $bankTx['TransactionReference'] ?? '';
        $creditDebit = $bankTx['TransactionType'] ?? '';

        $extractedPhone = Phone::extractFromNarration($narration);

        $dateRange = [
            'from' => "{$fromDate} 00:00:00",
            'to' => "{$toDate} 23:59:59",
        ];

        $extractedCheckoutId = null;
        if (preg_match('/(ws_CO_[a-f0-9]+)/i', $narration, $matches)) {
            $extractedCheckoutId = $matches[1];
        }

        if ($extractedPhone) {
            $member = Phone::findMemberByPhone($extractedPhone);

            if ($member) {
                $creditAmount = (float) ($bankTx['CreditAmount'] ?? 0);
                if ($creditAmount > 0) {
                    $occurredAt = $bankTx['TransactionDate'] ?? $bankTx['trans_date'] ?? $bankTx['ValueDate'] ?? now()->toDateTimeString();
                    $stkReconciliation = $this->pendingStkReconciliation->reconcile(
                        (string) $member->id,
                        $extractedPhone,
                        (int) round($creditAmount * 100),
                        (string) $occurredAt,
                        (string) $bankRef,
                        (string) $narration,
                    );

                    if ($stkReconciliation['status'] === 'reconciled') {
                        return true;
                    }
                    if ($stkReconciliation['status'] === 'conflict') {
                        return false;
                    }
                }

                $exists = DB::table('penalty_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'verified')
                    ->exists();

                if ($exists) {
                    return true;
                }

                $pending = DB::table('penalty_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'pending')
                    ->first();

                if ($pending) {
                    $this->settlePendingPenalty($pending, $bankTx);

                    return true;
                }

                $verifiedDonation = DB::table('donation_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'verified')
                    ->first();

                if ($verifiedDonation) {
                    $this->settlePendingDonation($verifiedDonation, $bankTx);

                    return true;
                }

                $pending = DB::table('donation_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'pending')
                    ->first();

                if ($pending) {
                    $this->settlePendingDonation($pending, $bankTx);

                    return true;
                }

                $exists = DB::table('operational_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'verified')
                    ->exists();

                if ($exists) {
                    return true;
                }

                $pending = DB::table('operational_payment_records')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'pending')
                    ->first();

                if ($pending) {
                    $this->settlePendingOperational($pending, $bankTx);

                    return true;
                }

                $exists = DB::table('contributions')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'paid')
                    ->exists();

                if ($exists) {
                    return true;
                }

                $pending = DB::table('contributions')
                    ->where('member_id', $member->id)
                    ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$amount])
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->where('status', 'pending')
                    ->first();

                if ($pending) {
                    $this->settlePendingContribution($pending, $bankTx);

                    return true;
                }
            }
        }

        if ($extractedCheckoutId) {
            $exists = DB::table('penalty_payment_records')
                ->where('mpesa_transaction_id', $extractedCheckoutId)
                ->where('status', 'verified')
                ->exists();

            if ($exists) {
                return true;
            }

            $verifiedDonation = DB::table('donation_payment_records')
                ->where('mpesa_transaction_id', $extractedCheckoutId)
                ->where('status', 'verified')
                ->first();

            if ($verifiedDonation) {
                $this->settlePendingDonation($verifiedDonation, $bankTx);

                return true;
            }

            $exists = DB::table('operational_payment_records')
                ->where('mpesa_transaction_id', $extractedCheckoutId)
                ->where('status', 'verified')
                ->exists();

            if ($exists) {
                return true;
            }

            $exists = DB::table('contributions')
                ->where('stk_checkout_request_id', $extractedCheckoutId)
                ->where('status', 'paid')
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    public function settleSuccessfulTransfer(CoopTransferRequest $transfer): bool
    {
        if ($transfer->transfer_type !== 'STK') {
            return false;
        }

        $internalCheckoutId = $this->extractInternalCheckoutId($transfer->internal_reference);

        if (! $internalCheckoutId) {
            return false;
        }

        $canonicalPayment = DB::table('payments')
            ->where(function ($query) use ($internalCheckoutId, $transfer): void {
                $query->where('internal_reference', $internalCheckoutId)
                    ->orWhere('provider_reference', $transfer->message_reference)
                    ->orWhere('transaction_ref', $transfer->message_reference);
            })
            ->first();

        if ($canonicalPayment) {
            $result = $this->paymentEngine->updateProviderStatus(
                (string) $canonicalPayment->id,
                'success',
                'Provider reconciliation confirmed payment',
                ['Status' => 'SUCCESS', 'MessageReference' => $transfer->message_reference],
            );
            if (($result['status'] ?? null) !== 'confirmed') {
                return false;
            }

            $paymentType = (string) ($canonicalPayment->payment_type ?? '');
            if (in_array($paymentType, ['penalty', 'donation', 'operational'], true)) {
                $this->paymentLifecycle->postPayment(
                    $paymentType,
                    (string) $canonicalPayment->member_id,
                    (float) ($canonicalPayment->amount_minor ?? 0) / 100,
                    (string) ($canonicalPayment->provider_reference ?: $transfer->message_reference),
                    $canonicalPayment->related_entity_id ?? null,
                    'stk_reconciliation',
                    (string) $canonicalPayment->id,
                    $canonicalPayment->internal_reference ?? null,
                );
            }

            $transfer->update(['status' => 'successful', 'updated_at' => now()]);

            return true;
        }

        return DB::transaction(function () use ($internalCheckoutId, $transfer) {
            Log::info('RECONCILIATION_SETTLE_START', [
                'transfer_id' => $transfer->id,
                'internal_checkout_id' => $internalCheckoutId,
                'internal_reference' => $transfer->internal_reference,
            ]);

            $penalty = DB::table('penalty_payment_records')
                ->where('mpesa_transaction_id', $internalCheckoutId)
                ->first();

            $donation = DB::table('donation_payment_records')
                ->where('mpesa_transaction_id', $internalCheckoutId)
                ->first();

            $contribution = DB::table('contributions')
                ->where('stk_checkout_request_id', $internalCheckoutId)
                ->first();

            if ($donation && in_array((string) $donation->status, ['pending', 'verified'], true)) {
                return $this->settlePendingDonation($donation, [
                    'TransactionReference' => $transfer->message_reference,
                ]);
            }

            if ($penalty) {
                $wasPending = (string) $penalty->status !== 'verified';
                $settledPenalty = $this->verifyPenaltyPaymentRecord(
                    (string) $penalty->id,
                    $transfer->message_reference,
                    $transfer->internal_reference,
                );
                if (! $settledPenalty) {
                    return false;
                }

                $walletCredited = $this->safeWalletCredit('penalty_wallet', (float) $penalty->amount, $penalty->id, 'penalty_payment_records', $transfer->internal_reference, $penalty->member_id);
                if (! $walletCredited) {
                    Log::warning('Reconciliation wallet credit failed for penalty; payment marked verified but wallet not credited', [
                        'member_id' => $penalty->member_id,
                        'payment_id' => $penalty->id,
                        'amount' => (float) $penalty->amount,
                        'bank_ref' => $transfer->internal_reference,
                    ]);
                }
                if ($wasPending) {
                    $this->sendStkSuccessSms($penalty->member_id, (float) $penalty->amount, $transfer->internal_reference);
                }

                return true;
            }

            if ($contribution && $contribution->status !== 'paid') {
                DB::table('contributions')
                    ->where('id', $contribution->id)
                    ->update([
                        'status' => 'paid',
                        'paid_date' => now()->format('Y-m-d'),
                        'payment_ref' => $transfer->internal_reference,
                        'updated_at' => now(),
                    ]);

                app(MemberFinancialProjectionService::class)
                    ->syncMember((string) $contribution->member_id);

                $this->sendStkSuccessSms($contribution->member_id, (float) $contribution->amount, $transfer->internal_reference);

                return true;
            }

            $operational = DB::table('operational_payment_records')
                ->where('mpesa_transaction_id', $internalCheckoutId)
                ->first();

            if ($operational && $operational->status !== 'verified') {
                DB::table('operational_payment_records')
                    ->where('id', $operational->id)
                    ->update([
                        'status' => 'verified',
                        'verified_at' => now(),
                        'updated_at' => now(),
                    ]);

                $walletCredited = $this->safeWalletCredit('operational_wallet', (float) $operational->amount, $operational->id, 'operational_payment_records', $transfer->internal_reference, $operational->member_id);
                if (! $walletCredited) {
                    Log::warning('Reconciliation wallet credit failed for operational; payment marked verified but wallet not credited', [
                        'member_id' => $operational->member_id,
                        'payment_id' => $operational->id,
                        'amount' => (float) $operational->amount,
                        'bank_ref' => $transfer->internal_reference,
                    ]);
                }
                $this->sendStkSuccessSms($operational->member_id, (float) $operational->amount, $transfer->internal_reference);

                return true;
            }

            return false;
        });
    }

    protected function safeWalletCredit(string $walletType, float $amount, string $paymentId, string $table, string $mpesaReceipt, ?string $memberId = null): bool
    {
        $walletTypeName = str_replace('_wallet', '', $walletType);

        $existingByReference = DB::table('wallet_transactions')
            ->where('wallet_type', $walletTypeName)
            ->where('reference_id', $paymentId)
            ->where('reference_table', $table)
            ->where('direction', 'credit')
            ->first();

        if ($existingByReference) {
            if (abs((float) $existingByReference->gross_amount - $amount) >= 0.01) {
                Log::error('Existing wallet credit conflicts with reconciled payment amount', [
                    'wallet_type' => $walletTypeName,
                    'payment_id' => $paymentId,
                    'table' => $table,
                    'expected_amount' => $amount,
                    'credited_amount' => (float) $existingByReference->gross_amount,
                ]);

                return false;
            }

            return true;
        }

        $receiptConflict = DB::table('wallet_transactions')
            ->where('mpesa_receipt', $mpesaReceipt)
            ->where('direction', 'credit')
            ->exists();
        if ($receiptConflict) {
            Log::error('Wallet receipt is already associated with a different credit', [
                'wallet_type' => $walletTypeName,
                'payment_id' => $paymentId,
                'table' => $table,
                'mpesa_receipt' => $mpesaReceipt,
            ]);

            return false;
        }

        try {
            app(WalletService::class)->credit(
                $walletTypeName,
                $memberId ?? 'system',
                $amount,
                $mpesaReceipt,
                'bank_reconciliation',
                $paymentId,
                $table,
                'Bank reconciliation - missed callback repair'
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Wallet credit failed during reconciliation settlement', [
                'wallet' => $walletType,
                'wallet_type' => $walletTypeName,
                'member_id' => $memberId,
                'amount' => $amount,
                'mpesa_receipt' => $mpesaReceipt,
                'payment_id' => $paymentId,
                'table' => $table,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    protected function sendStkSuccessSms(?string $memberId, float $amount, string $receipt): void
    {
        $sms = app(SmsService::class);

        if (! $memberId) {
            return;
        }

        try {
            $member = DB::table('members')->where('id', $memberId)->first();
            if (! $member || empty($member->phone)) {
                return;
            }

            $amountStr = number_format($amount, 0, '.', ',');
            $message = "Your payment of KES {$amountStr} has been received. M-Pesa receipt: {$receipt}. Thank you. -KHCWW";
            $sms->send($member->phone, $message, 'payment_success', $memberId);
        } catch (\Throwable $e) {
            Log::warning('Failed to send reconciliation success SMS', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function updateCampaignContribution(object $donation): void
    {
        if (! $donation->campaign_id || ! $donation->member_id) {
            return;
        }

        $campaign = DB::table('donation_campaigns')
            ->where('id', $donation->campaign_id)
            ->first();

        if (! $campaign) {
            return;
        }

        $targetAmount = isset($campaign->amount) && (float) $campaign->amount > 0
            ? (float) $campaign->amount
            : 0;

        $existing = DB::table('campaign_contributions')
            ->where('campaign_id', $donation->campaign_id)
            ->where('member_id', $donation->member_id)
            ->first();

        $amount = (float) $donation->amount;

        if ($existing) {
            $newTotal = (float) $existing->total_contributed + $amount;
            $newRemaining = max($targetAmount - $newTotal, 0);
            $newStatus = $newRemaining <= 0 ? 'completed' : 'active';

            DB::table('campaign_contributions')
                ->where('id', $existing->id)
                ->update([
                    'total_contributed' => $newTotal,
                    'remaining' => $newRemaining,
                    'status' => $newStatus,
                    'last_payment_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            $newTotal = $targetAmount > 0 ? $amount : 0;
            $newRemaining = max($targetAmount - $newTotal, 0);
            $newStatus = $newRemaining <= 0 ? 'completed' : 'active';

            DB::table('campaign_contributions')->insert([
                'id' => Uuid::v4(),
                'campaign_id' => $donation->campaign_id,
                'member_id' => $donation->member_id,
                'total_contributed' => $newTotal,
                'target_amount' => $targetAmount,
                'remaining' => $newRemaining,
                'status' => $newStatus,
                'last_payment_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function settlePendingPenalty(object $penalty, array $bankTx): void
    {
        $paymentRef = $bankTx['TransactionReference'] ?? $bankTx['MpesaReceipt'] ?? $bankTx['reference'] ?? null;

        $settledPenalty = $this->verifyPenaltyPaymentRecord(
            (string) $penalty->id,
            $paymentRef,
            $penalty->mpesa_transaction_id ?? null,
        );
        if (! $settledPenalty) {
            Log::warning('Reconciliation could not settle penalty payment record', [
                'payment_record_id' => $penalty->id,
                'member_id' => $penalty->member_id,
            ]);

            return;
        }

        $walletCredited = $this->safeWalletCredit('penalty_wallet', (float) $penalty->amount, $penalty->id, 'penalty_payment_records', $paymentRef ?? $penalty->id, $penalty->member_id);
        if (! $walletCredited) {
            Log::warning('Reconciliation settlePending wallet credit failed for penalty; payment marked verified but wallet not credited', [
                'member_id' => $penalty->member_id,
                'payment_id' => $penalty->id,
                'amount' => (float) $penalty->amount,
                'bank_ref' => $paymentRef,
            ]);
        }

        $this->sendStkSuccessSms($penalty->member_id, (float) $penalty->amount, $paymentRef ?? $penalty->id);

        Log::info('RECONCILIATION_SETTLE_PENDING_PENALTY', [
            'record_id' => $penalty->id,
            'member_id' => $penalty->member_id,
            'amount' => (float) $penalty->amount,
            'bank_ref' => $paymentRef,
        ]);
    }

    private function verifyPenaltyPaymentRecord(
        string $paymentRecordId,
        ?string $paymentReference,
        ?string $internalReference,
    ): ?object {
        return DB::transaction(function () use ($paymentRecordId, $paymentReference, $internalReference): ?object {
            $payment = DB::table('penalty_payment_records')
                ->where('id', $paymentRecordId)
                ->lockForUpdate()
                ->first();

            if (! $payment || ! in_array((string) $payment->status, ['pending', 'verified'], true)) {
                return null;
            }

            $penaltyId = $payment->penalty_id;
            if ($penaltyId && ! DB::table('penalties')
                ->where('id', $penaltyId)
                ->where('member_id', $payment->member_id)
                ->exists()) {
                Log::warning('Reconciliation penalty payment references a missing or foreign penalty', [
                    'payment_record_id' => $paymentRecordId,
                    'member_id' => $payment->member_id,
                    'penalty_id' => $penaltyId,
                ]);

                return null;
            }

            if (! $penaltyId) {
                $candidates = DB::table('penalties')
                    ->where('member_id', $payment->member_id)
                    ->where('is_paid', 0)
                    ->lockForUpdate()
                    ->get();
                $matchingPenaltyIds = [];

                foreach ($candidates as $candidate) {
                    $paid = (float) DB::table('penalty_payment_records')
                        ->where('penalty_id', $candidate->id)
                        ->where('status', 'verified')
                        ->sum('amount');
                    $remaining = max((float) $candidate->amount - $paid, 0);

                    if ($remaining > 0 && abs($remaining - (float) $payment->amount) < 0.01) {
                        $matchingPenaltyIds[] = (string) $candidate->id;
                    }
                }

                if (count($matchingPenaltyIds) === 1) {
                    $penaltyId = $matchingPenaltyIds[0];
                } elseif ($matchingPenaltyIds !== []) {
                    Log::warning('Reconciliation found multiple penalties matching a payment; penalty remains outstanding', [
                        'payment_record_id' => $paymentRecordId,
                        'member_id' => $payment->member_id,
                        'penalty_ids' => $matchingPenaltyIds,
                    ]);
                }
            }

            $updates = [
                'status' => 'verified',
                'payment_ref' => $paymentReference,
                'verified_at' => $payment->verified_at ?? now(),
                'updated_at' => now(),
            ];
            if ($penaltyId) {
                $updates['penalty_id'] = $penaltyId;
            }
            DB::table('penalty_payment_records')
                ->where('id', $paymentRecordId)
                ->update($updates);

            if ($penaltyId) {
                $penalty = DB::table('penalties')
                    ->where('id', $penaltyId)
                    ->where('member_id', $payment->member_id)
                    ->lockForUpdate()
                    ->first();

                if ($penalty) {
                    $paid = (float) DB::table('penalty_payment_records')
                        ->where('penalty_id', $penaltyId)
                        ->where('status', 'verified')
                        ->sum('amount');
                    DB::table('penalties')
                        ->where('id', $penaltyId)
                        ->update(['is_paid' => $paid >= (float) $penalty->amount ? 1 : 0]);
                }
            } else {
                Log::warning('Reconciliation verified a penalty payment but could not uniquely identify its penalty', [
                    'payment_record_id' => $paymentRecordId,
                    'member_id' => $payment->member_id,
                    'amount' => (float) $payment->amount,
                    'internal_reference' => $internalReference,
                ]);
            }

            return $payment;
        });
    }

    protected function settlePendingDonation(object $donation, array $bankTx): bool
    {
        $paymentRef = $bankTx['TransactionReference'] ?? $bankTx['MpesaReceipt'] ?? $bankTx['reference'] ?? null;

        return DB::transaction(function () use ($donation, $paymentRef): bool {
            $currentDonation = DB::table('donation_payment_records')
                ->where('id', $donation->id)
                ->lockForUpdate()
                ->first();
            if (! $currentDonation || ! in_array((string) $currentDonation->status, ['pending', 'verified'], true)) {
                return false;
            }

            $wasPending = (string) $currentDonation->status === 'pending';
            if ($wasPending) {
                DB::table('donation_payment_records')
                    ->where('id', $currentDonation->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'verified',
                        'payment_ref' => $paymentRef,
                        'verified_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($currentDonation->campaign_id) {
                    DB::table('donation_campaigns')
                        ->where('id', $currentDonation->campaign_id)
                        ->increment('total_collected', (float) $currentDonation->amount);
                }
            }

            $existingCredit = DB::table('wallet_transactions')
                ->where('wallet_type', 'donation')
                ->where('direction', 'credit')
                ->where('reference_id', (string) $currentDonation->id)
                ->where('reference_table', 'donation_payment_records')
                ->first();
            $receipt = (string) (
                $paymentRef
                ?: $currentDonation->payment_ref
                ?: $currentDonation->mpesa_transaction_id
                ?: $currentDonation->id
            );
            $walletCredited = $this->safeWalletCredit(
                'donation_wallet',
                (float) $currentDonation->amount,
                (string) $currentDonation->id,
                'donation_payment_records',
                $receipt,
                $currentDonation->member_id ?? 'system'
            );
            if (! $walletCredited) {
                Log::warning('Reconciliation settlePending wallet credit failed for donation; payment marked verified but wallet not credited', [
                    'member_id' => $currentDonation->member_id,
                    'payment_id' => $currentDonation->id,
                    'amount' => (float) $currentDonation->amount,
                    'bank_ref' => $paymentRef,
                ]);
            }

            if ($wasPending && $currentDonation->campaign_id && $currentDonation->member_id) {
                $this->updateCampaignContribution($currentDonation);
            }

            if ($wasPending) {
                $this->sendStkSuccessSms($currentDonation->member_id, (float) $currentDonation->amount, $paymentRef ?? $currentDonation->id);
            }

            Log::info('RECONCILIATION_SETTLE_PENDING_DONATION', [
                'record_id' => $currentDonation->id,
                'member_id' => $currentDonation->member_id,
                'amount' => (float) $currentDonation->amount,
                'campaign_id' => $currentDonation->campaign_id,
                'bank_ref' => $paymentRef,
            ]);

            return $wasPending || (! $existingCredit && $walletCredited);
        });
    }

    protected function settlePendingOperational(object $operational, array $bankTx): void
    {
        $paymentRef = $bankTx['TransactionReference'] ?? $bankTx['MpesaReceipt'] ?? $bankTx['reference'] ?? null;

        DB::table('operational_payment_records')
            ->where('id', $operational->id)
            ->update([
                'status' => 'verified',
                'payment_ref' => $paymentRef,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

        $walletCredited = $this->safeWalletCredit('operational_wallet', (float) $operational->amount, $operational->id, 'operational_payment_records', $paymentRef ?? $operational->id, $operational->member_id);
        if (! $walletCredited) {
            Log::warning('Reconciliation settlePending wallet credit failed for operational; payment marked verified but wallet not credited', [
                'member_id' => $operational->member_id,
                'payment_id' => $operational->id,
                'amount' => (float) $operational->amount,
                'bank_ref' => $paymentRef,
            ]);
        }
        $this->sendStkSuccessSms($operational->member_id, (float) $operational->amount, $paymentRef ?? $operational->id);

        Log::info('RECONCILIATION_SETTLE_PENDING_OPERATIONAL', [
            'record_id' => $operational->id,
            'member_id' => $operational->member_id,
            'amount' => (float) $operational->amount,
            'bank_ref' => $paymentRef,
        ]);
    }

    protected function settlePendingContribution(object $contribution, array $bankTx): void
    {
        $paymentRef = $bankTx['TransactionReference'] ?? $bankTx['MpesaReceipt'] ?? $bankTx['reference'] ?? null;

        $this->allocation->allocatePayment(
            $contribution->member_id,
            (float) $contribution->amount,
            now()->format('Y-m-d'),
            $paymentRef ?? $contribution->id,
            null
        );

        DB::table('contributions')
            ->where('id', $contribution->id)
            ->update([
                'status' => 'paid',
                'paid_date' => now()->format('Y-m-d'),
                'payment_ref' => $paymentRef,
                'updated_at' => now(),
            ]);

        $this->sendStkSuccessSms($contribution->member_id, (float) $contribution->amount, $paymentRef ?? $contribution->id);

        Log::info('RECONCILIATION_SETTLE_PENDING_CONTRIBUTION', [
            'contribution_id' => $contribution->id,
            'member_id' => $contribution->member_id,
            'amount' => (float) $contribution->amount,
            'month' => $contribution->month,
            'year' => $contribution->year,
            'bank_ref' => $paymentRef,
        ]);
    }

    protected function extractInternalCheckoutId(?string $internalReference): ?string
    {
        if (! $internalReference) {
            return null;
        }

        if (preg_match('/(ws_CO_[a-f0-9]+)/i', $internalReference, $matches)) {
            return $matches[1];
        }

        $parts = explode('-', $internalReference, 3);

        return $parts[2] ?? null;
    }

    public function getReconciliationStatus(): array
    {
        $pendingTransfers = CoopTransferRequest::whereIn('status', ['processing', 'submitted'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return [
            'pending_transfers' => $pendingTransfers->count(),
            'transfers_by_status' => CoopTransferRequest::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'recent_transfers' => $pendingTransfers->map(function ($t) {
                return [
                    'id' => $t->id,
                    'message_reference' => $t->message_reference,
                    'transfer_type' => $t->transfer_type,
                    'status' => $t->status,
                    'amount' => (float) $t->amount,
                    'created_at' => $t->created_at,
                ];
            })->toArray(),
        ];
    }

    protected function extractPhoneFromNarration(string $narration): ?string
    {
        return Phone::extractFromNarration($narration);
    }
}
