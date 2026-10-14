<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DonationPaymentRecord;
use App\Models\PenaltyPaymentRecord;
use App\Models\WalletTransaction;
use App\Support\Uuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentLifecycleService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly PaymentEngine $paymentEngine,
    ) {}

    public function postPayment(string $paymentType, string $memberId, float $amount, string $providerReference, ?string $relatedEntityId = null, ?string $source = 'stk_push', ?string $existingPaymentId = null, ?string $internalReference = null): array
    {
        $paymentType = strtolower($paymentType);

        $result = match ($paymentType) {
            'monthly_contribution' => $this->postMonthlyContribution($memberId, $amount, $providerReference, $source),
            'fund_drive' => $this->postFundDrive($memberId, $amount, $providerReference, $relatedEntityId, $source, $existingPaymentId, $internalReference),
            'donation' => $relatedEntityId
                ? $this->postFundDrive($memberId, $amount, $providerReference, $relatedEntityId, $source, $existingPaymentId, $internalReference)
                : $this->postGeneralDonation($memberId, $amount, $providerReference, $source, $internalReference),
            'penalty' => $this->postPenalty($memberId, $amount, $providerReference, $relatedEntityId, $source, $existingPaymentId),
            'operational' => $this->postOperational($memberId, $amount, $providerReference, $source, $existingPaymentId, $internalReference),
            default => throw new \InvalidArgumentException("Unknown payment type: {$paymentType}"),
        };

        if (in_array($paymentType, ['donation', 'fund_drive'], true)
            && ($result['success'] ?? false) && ! ($result['idempotent'] ?? false)) {
            $this->sendDonationReceipt($memberId, $amount, $providerReference, $relatedEntityId);
        }

        return $result;
    }

    /** Email the member a donation receipt using the email settings configured on the dashboard. Never blocks the payment. */
    private function sendDonationReceipt(string $memberId, float $amount, string $receipt, ?string $campaignId): void
    {
        try {
            $member = DB::table('members')->where('id', $memberId)->first();
            $email = $member->email ?? null;
            foreach (['users', 'auth_users'] as $t) {
                if (! $email && ! empty($member->user_id) && \Illuminate\Support\Facades\Schema::hasTable($t)) {
                    $email = DB::table($t)->where('id', $member->user_id)->value('email');
                }
            }
            if (! $email) {
                return;
            }
            $mail = app(\App\Services\WebsiteMailService::class);
            if (! ($mail->emailSettings()['enabled'] ?? false)) {
                return;
            }
            $campaign = $campaignId ? DB::table('donation_campaigns')->where('id', $campaignId)->value('title') : null;
            $vars = [
                'name' => $member->name ?? 'Member',
                'amount' => 'KES '.number_format($amount, 2),
                'campaign' => $campaign ?: 'General donation',
                'receipt' => $receipt,
                'date' => now()->format('d M Y H:i'),
            ];
            app()->terminating(fn () => rescue(fn () => $mail->sendTemplate('donation_receipt', $email, $vars), report: true));
        } catch (\Throwable $e) {
            Log::warning('Donation receipt email skipped', ['member_id' => $memberId, 'error' => $e->getMessage()]);
        }
    }

    private function postMonthlyContribution(string $memberId, float $amount, string $providerReference, string $source): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $monthlyMinor = (int) round(((float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300)) * 100);
        $remainingMinor = (int) round($amount * 100);
        $cursor = new \DateTimeImmutable('first day of this month');
        $allocations = [];
        while ($remainingMinor > 0 && count($allocations) < 240) {
            $allocationAmount = min($monthlyMinor, $remainingMinor);
            $allocations[] = [
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'amount_minor' => $allocationAmount,
                'required_amount_minor' => $monthlyMinor,
            ];
            $remainingMinor -= $allocationAmount;
            $cursor = $cursor->modify('+1 month');
        }

        // Compatibility adapter: legacy callers enter the canonical manual-payment workflow.
        $result = $this->paymentEngine->processProviderEvent([
            'provider' => 'manual',
            'provider_event_id' => $providerReference,
            'event_type' => 'manual_confirmed',
            'member_id' => $memberId,
            'amount_minor' => (int) round($amount * 100),
            'provider_reference' => $providerReference,
            'internal_reference' => $providerReference,
            'source' => 'manual',
            'occurred_at' => now()->toDateTimeString(),
            'allocations' => $allocations,
        ]);

        $allocatedMinor = (int) DB::table('contribution_allocations')
            ->where('payment_id', $result['payment_id'])
            ->sum('amount_minor');
        $creditRemaining = max(0, $amount - ($allocatedMinor / 100));

        return [
            'success' => true,
            'payment_type' => 'monthly_contribution',
            'member_id' => $memberId,
            'amount' => $amount,
            'months_fully_paid' => (int) DB::table('contribution_allocations as allocations')
                ->join('monthly_obligations as obligations', 'obligations.id', '=', 'allocations.obligation_id')
                ->where('allocations.payment_id', $result['payment_id'])
                ->where('obligations.status', 'paid')
                ->count(),
            'credit_remaining' => $creditRemaining,
            'next_due_date' => null,
            'provider_reference' => $providerReference,
            'source' => $source,
        ];
    }

    private function postGeneralDonation(
        string $memberId,
        float $amount,
        string $providerReference,
        string $source,
        ?string $internalReference
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use ($memberId, $amount, $providerReference, $source, $internalReference) {
            $references = array_values(array_unique(array_filter([$internalReference, $providerReference])));
            $paymentRecord = DB::table('donation_payment_records')
                ->where('member_id', $memberId)
                ->whereNull('campaign_id')
                ->whereIn('mpesa_transaction_id', $references)
                ->lockForUpdate()
                ->first();

            if ($paymentRecord && $paymentRecord->status === 'failed') {
                throw new \RuntimeException('A failed donation payment cannot be credited.');
            }

            if ($paymentRecord && abs((float) $paymentRecord->amount - $amount) >= 0.01) {
                throw new \RuntimeException('Donation amount does not match the pending payment record.');
            }

            $paymentRecordId = $paymentRecord?->id ?? Uuid::v4();
            $existingTransaction = WalletTransaction::where('wallet_type', 'donation')
                ->where('direction', 'credit')
                ->where('reference_id', $paymentRecordId)
                ->where('reference_table', 'donation_payment_records')
                ->lockForUpdate()
                ->first();

            if ($existingTransaction && abs((float) $existingTransaction->gross_amount - $amount) >= 0.01) {
                throw new \RuntimeException('Existing donation wallet credit has a conflicting amount.');
            }

            if (! $paymentRecord) {
                DB::table('donation_payment_records')->insert([
                    'id' => $paymentRecordId,
                    'campaign_id' => null,
                    'member_id' => $memberId,
                    'amount' => $amount,
                    'status' => 'verified',
                    'mpesa_transaction_id' => $providerReference,
                    'payment_ref' => $providerReference,
                    'created_at' => now(),
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('donation_payment_records')
                    ->where('id', $paymentRecordId)
                    ->update([
                        'status' => 'verified',
                        'mpesa_transaction_id' => $providerReference,
                        'payment_ref' => $providerReference,
                        'verified_at' => $paymentRecord->verified_at ?? now(),
                        'updated_at' => now(),
                    ]);
            }

            $walletResult = $this->wallet->credit(
                'donation',
                $memberId,
                $amount,
                $providerReference,
                $source,
                (string) $paymentRecordId,
                'donation_payment_records',
                'General donation payment',
            );

            return [
                'success' => true,
                'payment_type' => 'donation',
                'member_id' => $memberId,
                'amount' => $amount,
                'provider_reference' => $providerReference,
                'source' => $source,
                'idempotent' => (bool) ($existingTransaction !== null),
                'wallet' => $walletResult,
            ];
        });
    }

    private function postFundDrive(string $memberId, float $amount, string $providerReference, ?string $campaignId, string $source, ?string $existingPaymentId = null, ?string $internalReference = null): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        if (!$campaignId) {
            throw new \InvalidArgumentException('Fund drive payment requires campaign_id');
        }

        return DB::transaction(function () use ($memberId, $amount, $providerReference, $campaignId, $source, $existingPaymentId, $internalReference) {
            $existingWalletTx = WalletTransaction::where('mpesa_receipt', $providerReference)
                ->where('wallet_type', 'donation')
                ->exists();

            if ($existingWalletTx) {
                Log::info('PaymentLifecycleService: idempotent fund drive skip', [
                    'member_id' => $memberId,
                    'campaign_id' => $campaignId,
                    'provider_reference' => $providerReference,
                    'amount' => $amount,
                ]);

                $campaign = DB::table('donation_campaigns')->where('id', $campaignId)->first();
                $memberContrib = DB::table('campaign_contributions')
                    ->where('campaign_id', $campaignId)
                    ->where('member_id', $memberId)
                    ->first();

                return [
                    'success' => true,
                    'payment_type' => 'fund_drive',
                    'member_id' => $memberId,
                    'campaign_id' => $campaignId,
                    'amount' => $amount,
                    'total_contributed' => $memberContrib ? (float) $memberContrib->total_contributed : 0,
                    'remaining' => $memberContrib ? (float) $memberContrib->remaining : ($campaign ? (float) $campaign->amount : $amount),
                    'status' => $memberContrib ? $memberContrib->status : 'active',
                    'provider_reference' => $providerReference,
                    'source' => $source,
                    'idempotent' => true,
                ];
            }

            $campaign = DB::table('donation_campaigns')->where('id', $campaignId)->first();
            if (!$campaign) {
                throw new \RuntimeException("Fund drive not found: {$campaignId}");
            }

            $targetAmount = (float) ($campaign->amount ?? 0);
            $allowPartial = (bool) ($campaign->allow_partial ?? true);

            if (!$allowPartial && $amount < $targetAmount) {
                throw new \InvalidArgumentException("Partial payments not allowed for this fund drive. Required: {$targetAmount}");
            }

            if ($amount > $targetAmount) {
                $acceptedAmount = $targetAmount;
                $excess = $amount - $targetAmount;
                Log::warning('PaymentLifecycleService: fund drive overpayment', [
                    'member_id' => $memberId,
                    'campaign_id' => $campaignId,
                    'requested' => $amount,
                    'accepted' => $acceptedAmount,
                    'excess' => $excess,
                ]);
            } else {
                $acceptedAmount = $amount;
                $excess = 0.0;
            }

            $existingDonation = DB::table('donation_payment_records')
                ->where('member_id', $memberId)
                ->where('campaign_id', $campaignId)
                ->where('status', '!=', 'verified')
                ->whereRaw('ABS(CAST(amount AS DECIMAL(14,2)) - ?) < 0.01', [$acceptedAmount])
                ->whereIn('mpesa_transaction_id', array_values(array_filter([$internalReference, $providerReference])))
                ->lockForUpdate()
                ->first();

            $paymentRecordId = $existingDonation?->id ?? Uuid::v4();
            if ($existingDonation) {
                DB::table('donation_payment_records')
                    ->where('id', $paymentRecordId)
                    ->update([
                        'status' => 'verified',
                        'mpesa_transaction_id' => $providerReference,
                        'payment_ref' => $providerReference,
                        'verified_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('donation_payment_records')->insert([
                    'id' => $paymentRecordId,
                    'campaign_id' => $campaignId,
                    'member_id' => $memberId,
                    'amount' => $acceptedAmount,
                    'status' => 'verified',
                    'mpesa_transaction_id' => $providerReference,
                    'payment_ref' => $providerReference,
                    'created_at' => now(),
                    'verified_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $memberContrib = DB::table('campaign_contributions')
                ->where('campaign_id', $campaignId)
                ->where('member_id', $memberId)
                ->first();

            if ($memberContrib) {
                $newTotal = (float) $memberContrib->total_contributed + $acceptedAmount;
                $newRemaining = max($targetAmount - $newTotal, 0);
                $newStatus = $newRemaining <= 0 ? 'completed' : 'active';

                DB::table('campaign_contributions')
                    ->where('id', $memberContrib->id)
                    ->update([
                        'total_contributed' => $newTotal,
                        'remaining' => $newRemaining,
                        'status' => $newStatus,
                        'last_payment_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                $newTotal = $targetAmount > 0 ? $acceptedAmount : 0;
                $newRemaining = max($targetAmount - $newTotal, 0);
                $newStatus = $newRemaining <= 0 ? 'completed' : 'active';

                DB::table('campaign_contributions')->insert([
                    'id' => Uuid::v4(),
                    'campaign_id' => $campaignId,
                    'member_id' => $memberId,
                    'total_contributed' => $newTotal,
                    'target_amount' => $targetAmount,
                    'remaining' => $newRemaining,
                    'status' => $newStatus,
                    'last_payment_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('donation_campaigns')
                ->where('id', $campaignId)
                ->increment('total_collected', $acceptedAmount);

            $this->wallet->credit(
                'donation',
                $memberId,
                $acceptedAmount,
                $providerReference,
                $source,
                $paymentRecordId,
                'donation_payment_records',
                "Fund drive payment - campaign {$campaignId}"
            );

            $finalContrib = DB::table('campaign_contributions')
                ->where('campaign_id', $campaignId)
                ->where('member_id', $memberId)
                ->first();

            return [
                'success' => true,
                'payment_type' => 'fund_drive',
                'member_id' => $memberId,
                'campaign_id' => $campaignId,
                'amount' => $acceptedAmount,
                'excess' => $excess,
                'total_contributed' => $finalContrib ? (float) $finalContrib->total_contributed : $acceptedAmount,
                'remaining' => $finalContrib ? (float) $finalContrib->remaining : max($targetAmount - $acceptedAmount, 0),
                'status' => $finalContrib ? $finalContrib->status : ($acceptedAmount >= $targetAmount ? 'completed' : 'active'),
                'provider_reference' => $providerReference,
                'source' => $source,
                'idempotent' => false,
            ];
        });
    }

    private function postPenalty(string $memberId, float $amount, string $providerReference, ?string $penaltyId, string $source): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use ($memberId, $amount, $providerReference, $penaltyId, $source) {
            $existingWalletTx = WalletTransaction::where('mpesa_receipt', $providerReference)
                ->where('wallet_type', 'penalty')
                ->exists();

            if ($existingWalletTx) {
                Log::info('PaymentLifecycleService: idempotent penalty skip', [
                    'member_id' => $memberId,
                    'penalty_id' => $penaltyId,
                    'provider_reference' => $providerReference,
                    'amount' => $amount,
                ]);

                $penalty = $penaltyId ? DB::table('penalties')->where('id', $penaltyId)->first() : null;
                $required = $penalty ? (float) $penalty->amount : $amount;
                $paid = $penaltyId ? (float) DB::table('penalty_payment_records')->where('penalty_id', $penaltyId)->sum('amount') : $amount;
                $remaining = max($required - $paid, 0);
                $isFullyPaid = $remaining <= 0;

                return [
                    'success' => true,
                    'payment_type' => 'penalty',
                    'member_id' => $memberId,
                    'penalty_id' => $penaltyId,
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'remaining' => $remaining,
                    'status' => $isFullyPaid ? 'paid' : 'partially_paid',
                    'provider_reference' => $providerReference,
                    'source' => $source,
                    'idempotent' => true,
                ];
            }

            $paymentRecordId = Uuid::v4();
            DB::table('penalty_payment_records')->insert([
                'id' => $paymentRecordId,
                'member_id' => $memberId,
                'penalty_id' => $penaltyId,
                'amount' => $amount,
                'status' => 'verified',
                'mpesa_transaction_id' => $providerReference,
                'payment_ref' => $providerReference,
                'created_at' => now(),
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

            if ($penaltyId) {
                $penalty = DB::table('penalties')->where('id', $penaltyId)->first();
                if ($penalty) {
                    $required = (float) $penalty->amount;
                    $paid = (float) DB::table('penalty_payment_records')->where('penalty_id', $penaltyId)->sum('amount');
                    $remaining = max($required - $paid, 0);
                    $isFullyPaid = $remaining <= 0;

                    DB::table('penalties')
                        ->where('id', $penaltyId)
                        ->update([
                            'is_paid' => $isFullyPaid ? 1 : 0,
                        ]);
                }
            }

            $this->wallet->credit(
                'penalty',
                $memberId,
                $amount,
                $providerReference,
                $source,
                $paymentRecordId,
                'penalty_payment_records',
                "Penalty payment - penalty {$penaltyId}"
            );

            $finalPaid = $penaltyId ? (float) DB::table('penalty_payment_records')->where('penalty_id', $penaltyId)->sum('amount') : $amount;
            $finalRequired = $penaltyId ? (float) DB::table('penalties')->where('id', $penaltyId)->value('amount') : $amount;
            $finalRemaining = max($finalRequired - $finalPaid, 0);

            return [
                'success' => true,
                'payment_type' => 'penalty',
                'member_id' => $memberId,
                'penalty_id' => $penaltyId,
                'amount' => $amount,
                'paid_amount' => $finalPaid,
                'remaining' => $finalRemaining,
                'status' => $finalRemaining <= 0 ? 'paid' : 'partially_paid',
                'provider_reference' => $providerReference,
                'source' => $source,
                'idempotent' => false,
            ];
        });
    }

    private function postOperational(
        string $memberId,
        float $amount,
        string $providerReference,
        string $source,
        ?string $existingPaymentId,
        ?string $internalReference
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        return DB::transaction(function () use ($memberId, $amount, $providerReference, $source, $existingPaymentId, $internalReference) {
            $paymentRecord = $internalReference
                ? DB::table('operational_payment_records')
                    ->where('mpesa_transaction_id', $internalReference)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($paymentRecord && $paymentRecord->status === 'failed') {
                throw new \RuntimeException('A failed operational payment record cannot be credited.');
            }

            $referenceId = $paymentRecord?->id ?? $existingPaymentId ?? $providerReference;
            $referenceTable = $paymentRecord ? 'operational_payment_records' : 'payments';
            $result = $this->wallet->credit(
                'operational',
                $memberId,
                $amount,
                $providerReference,
                $source,
                (string) $referenceId,
                $referenceTable,
                'STK operational payment',
            );

            if ($paymentRecord) {
                DB::table('operational_payment_records')
                    ->where('id', $paymentRecord->id)
                    ->update([
                        'status' => 'verified',
                        'payment_ref' => $providerReference,
                        'verified_at' => $paymentRecord->verified_at ?? now(),
                        'updated_at' => now(),
                    ]);
            }

            return [
                'success' => true,
                'payment_type' => 'operational',
                'amount' => $amount,
                'provider_reference' => $providerReference,
                'wallet' => $result,
            ];
        });
    }
}
