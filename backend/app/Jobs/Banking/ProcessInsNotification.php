<?php

namespace App\Jobs\Banking;

use App\Services\WalletService;
use App\Services\PaymentEngine;
use App\Support\Phone;
use App\Support\Uuid;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessInsNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $notification
    ) {}

    public function handle(WalletService $walletService, PaymentEngine $paymentEngine): void
    {
        $transactionId = $this->notification['TransactionId'] ?? null;
        $paymentRef = $this->notification['PaymentRef'] ?? null;
        $amount = (float) ($this->notification['Amount'] ?? 0);
        $transactionType = strtoupper($this->notification['TransactionType'] ?? 'C');
        $isCredit = $transactionType === 'C';
        $narration = $this->notification['Narration'] ?? null;

        if (!$transactionId) {
            Log::warning('PAYMENT_INS_NO_TXN_ID', [
                'payment_ref' => $paymentRef,
                'amount' => $amount,
            ]);
            return;
        }

        $insertData = [
            'id' => Uuid::v4(),
            'payment_ref' => $paymentRef,
            'account_number' => $this->notification['AccountNumber'] ?? null,
            'amount' => $amount,
            'event_type' => $isCredit ? 'CREDIT' : 'DEBIT',
            'transaction_type' => $transactionType,
            'booked_balance' => $this->notification['BookedBalance'] ?? null,
            'cleared_balance' => $this->notification['ClearedBalance'] ?? null,
            'narration' => $narration,
            'transaction_date' => $this->notification['TransactionDate'] ?? now()->toDateString(),
            'transaction_time' => $this->notification['TransactionTime'] ?? null,
            'transaction_id' => $transactionId,
            'notification_payload' => json_encode($this->notification),
            'processed_at' => now(),
            'created_at' => now(),
        ];

        $existingNotif = DB::table('coop_notifications_ins')
            ->where('transaction_id', $transactionId)
            ->first();

        if ($existingNotif) {
            Log::info('PAYMENT_INS_DUPLICATE', [
                'transaction_id' => $transactionId,
                'already_processed' => $existingNotif->processed_at,
            ]);
            return;
        }

        DB::table('coop_notifications_ins')->insert($insertData);

        Log::info('PAYMENT_INS_RECEIVED', [
            'transaction_id' => $transactionId,
            'payment_ref' => $paymentRef,
            'amount' => $amount,
            'is_credit' => $isCredit,
            'narration' => $narration,
        ]);

        if (!$isCredit) {
            Log::info('PAYMENT_INS_SKIPPED_NON_CREDIT', [
                'transaction_id' => $transactionId,
                'transaction_type' => $transactionType,
            ]);
            return;
        }

        $settled = DB::transaction(function () use ($paymentRef, $transactionId, $amount, $walletService, $narration) {
            $matched = $this->tryMatchContribution($paymentRef, $transactionId, $amount, $narration, $paymentEngine);
            if ($matched) {
                Log::info('PAYMENT_INS_FINALIZED', [
                    'type' => 'monthly_contribution',
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                ]);
                return 'contribution';
            }

            $matched = $this->tryMatchWalletPayment($paymentRef, $transactionId, $amount, $walletService, $narration);
            if ($matched) {
                Log::info('PAYMENT_INS_FINALIZED', [
                    'type' => $matched,
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                ]);
                return $matched;
            }

            Log::info('PAYMENT_INS_NO_MATCH', [
                'transaction_id' => $transactionId,
                'payment_ref' => $paymentRef,
                'amount' => $amount,
                'narration' => $narration,
            ]);

            return false;
        });

        if (!$settled) {
            Log::warning('PAYMENT_INS_UNMATCHED', [
                'transaction_id' => $transactionId,
                'payment_ref' => $paymentRef,
                'amount' => $amount,
                'narration' => $narration,
            ]);
        }
    }

    protected function tryMatchContribution(?string $paymentRef, string $transactionId, float $amount, ?string $narration, PaymentEngine $paymentEngine): bool
    {
        $query = DB::table('contributions')
            ->where('status', 'pending')
            ->whereRaw("ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01", [$amount]);

        if ($paymentRef) {
            $query->where(function ($q) use ($paymentRef) {
                $q->where('payment_ref', $paymentRef)
                    ->orWhere('stk_checkout_request_id', $paymentRef);
            });
        }

        if ($transactionId) {
            $query->orWhere('payment_ref', $transactionId);
        }

        if ($narration) {
            $phone = Phone::extractFromNarration($narration);
            if ($phone) {
                $member = Phone::findMemberByPhone($phone);
                if ($member) {
                    $query->where('member_id', $member->id);
                }
            }
        }

        $dateRange = [
            'from' => now()->subDays(2)->format('Y-m-d H:i:s'),
            'to' => now()->addHour()->format('Y-m-d H:i:s'),
        ];
        $query->whereBetween('created_at', [$dateRange['from'], $dateRange['to']]);

        $contribution = $query->first();

        if (!$contribution) {
            return false;
        }

        $existing = DB::table('contributions')
            ->where('id', $contribution->id)
            ->where('payment_ref', $transactionId)
            ->exists();

        if ($existing) {
            return true;
        }

        $reference = $paymentRef ?: $transactionId;
        $paymentEngine->processProviderEvent([
            'provider' => 'coop_ins',
            'provider_event_id' => $transactionId,
            'event_type' => 'bank_confirmed',
            'member_id' => $contribution->member_id,
            'amount_minor' => (int) round($amount * 100),
            'provider_reference' => $reference,
            'internal_reference' => $reference,
            'source' => 'bank_ins',
            'occurred_at' => $this->notification['TransactionDate'] ?? now()->toDateTimeString(),
            'payload' => $this->notification,
        ]);

        DB::table('contributions')->where('id', $contribution->id)->update([
            'status' => 'paid',
            'paid_date' => now()->format('Y-m-d'),
            'payment_ref' => $transactionId,
            'updated_at' => now(),
        ]);

        Log::info('PAYMENT_FINALIZATION_STARTED', [
            'type' => 'monthly_contribution',
            'member_id' => $contribution->member_id,
            'amount' => $amount,
        ]);

        Log::info('CONTRIBUTION_UPDATED', [
            'contribution_id' => $contribution->id,
            'member_id' => $contribution->member_id,
            'new_status' => 'paid',
        ]);

        Log::info('PAYMENT_FINALIZED', [
            'type' => 'monthly_contribution',
            'member_id' => $contribution->member_id,
            'amount' => $amount,
            'transaction_id' => $transactionId,
        ]);

        return true;
    }

    protected function tryMatchWalletPayment(?string $paymentRef, string $transactionId, float $amount, WalletService $walletService, ?string $narration): ?string
    {
        $tables = ['penalty_payment_records', 'donation_payment_records', 'operational_payment_records'];
        $walletMap = [
            'penalty_payment_records' => 'penalty',
            'donation_payment_records' => 'donation',
            'operational_payment_records' => 'operational',
        ];

        foreach ($tables as $table) {
            $query = DB::table($table)
                ->where('status', 'pending')
                ->whereRaw("ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01", [$amount]);

            if ($paymentRef) {
                $query->where(function ($q) use ($paymentRef) {
                    $q->where('payment_ref', $paymentRef)
                        ->orWhere('mpesa_transaction_id', $paymentRef);
                });
            }

            if ($narration) {
                $phone = Phone::extractFromNarration($narration);
                if ($phone) {
                    $member = Phone::findMemberByPhone($phone);
                    if ($member) {
                        $query->where('member_id', $member->id);
                    }
                }
            }

            $dateRange = [
                'from' => now()->subDays(2)->format('Y-m-d H:i:s'),
                'to' => now()->addHour()->format('Y-m-d H:i:s'),
            ];
            $query->whereBetween('created_at', [$dateRange['from'], $dateRange['to']]);

            $record = $query->first();

            if (!$record) {
                continue;
            }

            if ($record->status === 'verified') {
                continue;
            }

            DB::table($table)->where('id', $record->id)->update([
                'status' => 'verified',
                'payment_ref' => $transactionId,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

            if ($table === 'donation_payment_records' && $record->campaign_id) {
                DB::table('donation_campaigns')
                    ->where('id', $record->campaign_id)
                    ->increment('total_collected', (float) $record->amount);

                $this->updateCampaignContribution($record);
            }

            $walletType = $walletMap[$table];
            $memberId = $record->member_id ?? 'system';

            $existingWalletTx = DB::table('wallet_transactions')
                ->where('mpesa_receipt', $transactionId)
                ->where('wallet_type', $walletType)
                ->where('direction', 'credit')
                ->exists();

            if (!$existingWalletTx && $table !== 'operational_payment_records') {
                $walletService->credit(
                    $walletType,
                    $memberId,
                    $amount,
                    $transactionId,
                    'coop_ins',
                    $record->id,
                    $table,
                    'INS notification - auto matched'
                );
            }

            $this->sendStkSuccessSms($record->member_id, (float) $record->amount, $transactionId);

            return $walletType . '_wallet';
        }

        return null;
    }

    protected function updateCampaignContribution(object $donation): void
    {
        if (!$donation->campaign_id || !$donation->member_id) {
            return;
        }

        $campaign = DB::table('donation_campaigns')
            ->where('id', $donation->campaign_id)
            ->first();

        if (!$campaign) {
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

    protected function sendStkSuccessSms(?string $memberId, float $amount, string $receipt): void
    {
        if (!$memberId) {
            return;
        }

        try {
            $member = DB::table('members')->where('id', $memberId)->first();
            if (!$member || empty($member->phone)) {
                return;
            }

            $amountStr = number_format($amount, 0, '.', ',');
            $message = "Your payment of KES {$amountStr} has been received. M-Pesa receipt: {$receipt}. Thank you. -KHCWW";

            $sms = app(\App\Services\SmsService::class);
            $sms->send($member->phone, $message, 'payment_success', $memberId);
        } catch (\Throwable $e) {
            Log::warning('Failed to send INS success SMS', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
