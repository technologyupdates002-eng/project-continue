<?php

namespace App\Services;

use App\Exceptions\HttpException;
use App\Models\CoopTransferRequest;
use App\Models\CoopTransferResponse;
use App\Models\Contribution;
use App\Models\DonationPaymentRecord;
use App\Models\PenaltyPaymentRecord;
use App\Models\WalletTransaction;
use App\Services\CoopBank\CoopBankAccountService;
use App\Services\CoopBank\CoopBankTransactionService;
use App\Services\CoopBank\CoopBankTransferService;
use App\Services\Statement\ContributionAllocationService;
use App\Support\Uuid;
use App\Support\ViewAsMemberContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private CoopBankTransactionService $stkService,
        private CoopBankAccountService $accountService,
        private CoopBankTransferService $transferService,
        private WalletService $walletService,
        private PaymentLifecycleService $lifecycle,
        private readonly PaymentEngine $paymentEngine,
        private ?SmsService $sms = null,
        private readonly ContributionAllocationService $allocation,
        private ?ContributionObligationService $obligation = null
    ) {}

    public function initiateStkPush(array $identity, array $data): array
    {
        $paymentType = $data['paymentType'];
        $memberId = $data['member_id'];
        $amount = (float) $data['amount'];
        $phone = $data['phone'];
        $reference = $data['accountReference'] ?? ($paymentType.'-'.$memberId);

        $this->assertCanWrite($identity, $memberId, $paymentType);

        $bankNarration = $this->generateBankNarration($paymentType, $amount, $data);

        $checkoutRequestId = 'ws_CO_'.bin2hex(random_bytes(12));

        DB::transaction(function () use ($paymentType, $memberId, $amount, $phone, $reference, $bankNarration, $checkoutRequestId, $data) {
            if ($paymentType === 'penalty') {
                PenaltyPaymentRecord::create([
                    'id' => Uuid::v4(),
                    'member_id' => $memberId,
                    'amount' => $amount,
                    'status' => 'pending',
                    'mpesa_transaction_id' => $checkoutRequestId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($paymentType === 'monthly_contribution') {
                $now = now();
                $currentMonth = (int) $now->format('m');
                $currentYear = (int) $now->format('Y');
                $monthly = $this->obligation
                    ? (float) $this->obligation->monthly()
                    : (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300);

                $existing = Contribution::where('member_id', $memberId)
                    ->where('month', $currentMonth)
                    ->where('year', $currentYear)
                    ->first();

                if (! $existing) {
                    $contribution = new Contribution();
                    $contribution->id = Uuid::v4();
                    $contribution->member_id = $memberId;
                    $contribution->month = $currentMonth;
                    $contribution->year = $currentYear;
                    $contribution->due_date = $now->format('Y-m-d');
                    $contribution->amount = $amount;
                    $contribution->status = 'pending';
                    $contribution->stk_checkout_request_id = $checkoutRequestId;
                    $contribution->created_at = $now;
                    $contribution->updated_at = $now;
                    $contribution->save();
                } else {
                    $existing->stk_checkout_request_id = $checkoutRequestId;
                    if ((float) $existing->amount <= 0) {
                        $existing->amount = $amount;
                    }
                    $existing->updated_at = $now;
                    $existing->save();
                }
            } elseif ($paymentType === 'operational') {
                \App\Models\OperationalPaymentRecord::create([
                    'id' => Uuid::v4(),
                    'member_id' => $memberId,
                    'amount' => $amount,
                    'status' => 'pending',
                    'mpesa_transaction_id' => $checkoutRequestId,
                    'source' => 'stk_push',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DonationPaymentRecord::create([
                    'id' => Uuid::v4(),
                    'campaign_id' => $data['campaignId'] ?? null,
                    'member_id' => $memberId,
                    'amount' => $amount,
                    'status' => 'pending',
                    'mpesa_transaction_id' => $checkoutRequestId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $relatedEntityId = $paymentType === 'donation'
            ? ($data['campaignId'] ?? null)
            : (($data['penaltyIds'][0] ?? null));
        $canonicalPending = $this->paymentEngine->createPendingPayment(
            $memberId,
            'mpesa',
            $checkoutRequestId,
            $checkoutRequestId,
            (int) round($amount * 100),
            null,
            $paymentType,
            $relatedEntityId,
        );

        $internalRef = $paymentType.'-'.$memberId.'-'.$checkoutRequestId;

        Log::info('PaymentService STK push initiated', [
            'payment_type' => $paymentType,
            'member_id' => $memberId,
            'amount' => $amount,
            'phone' => $phone,
            'checkout_request_id' => $checkoutRequestId,
            'campaign_id' => $data['campaignId'] ?? null,
            'internal_reference' => $internalRef,
        ]);

        $stkResult = $this->stkService->initiateStkPush([
            'member_id' => $memberId,
            'amount' => $amount,
            'phone' => $phone,
            'narration' => $bankNarration,
            'internal_reference' => $internalRef,
            'payment_type' => $paymentType,
        ]);

        if ($canonicalPending && ! empty($stkResult['message_reference'])) {
            $this->paymentEngine->updateProviderReference(
                $canonicalPending['payment_id'],
                (string) $stkResult['message_reference'],
                $stkResult['checkout_request_id'] ?? null,
            );
        }

        if (! empty($stkResult['success']) && ! empty($stkResult['transfer_id'])) {
            if (config('queue.default') === 'sync') {
                Log::info('PaymentService: Deferring initial STK reconciliation to the scheduled transfer scanner because the sync queue cannot honor delayed retries.', [
                    'transfer_id' => $stkResult['transfer_id'],
                ]);
            } else {
                try {
                    \App\Jobs\ReconcilePendingStkTransaction::dispatch($stkResult['transfer_id']);
                } catch (\Throwable $e) {
                    Log::warning('PaymentService: Failed to dispatch initial STK reconciliation job', [
                        'transfer_id' => $stkResult['transfer_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'status' => 'pending',
            'payment_id' => $canonicalPending['payment_id'] ?? null,
            'internal_reference' => $checkoutRequestId,
            'provider_reference' => $stkResult['message_reference'] ?? null,
            'checkoutRequestId' => $checkoutRequestId,
            'message' => 'STK Push initiated',
            'bank_response' => $stkResult,
        ];
    }

    protected function generateBankNarration(string $paymentType, float $amount, array $data): string
    {
        $amountStr = 'KES '.number_format($amount, 0, '.', ',');
        $clean = fn (?string $v) => mb_strtoupper(preg_replace('/\s+/', ' ', trim(preg_replace('/[^A-Za-z0-9 ]/', '', (string) $v))));

        if ($paymentType === 'monthly_contribution') {
            $rate = $this->obligation
                ? (float) $this->obligation->monthly()
                : (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300);
            $months = $rate > 0 ? (int) floor($amount / $rate) : 0;
            $cover = $months > 1 ? " ({$months} MONTHS)" : ($months === 1 ? ' (1 MONTH)' : '');

            return mb_substr("KHCWW MONTHLY CONTRIBUTION {$amountStr}{$cover}", 0, 100);
        }

        if ($paymentType === 'penalty') {
            return "KHCWW PENALTY PAYMENT {$amountStr}";
        }

        if ($paymentType === 'donation' || $paymentType === 'fund_drive') {
            $name = $data['campaign_name'] ?? (! empty($data['campaignId'])
                ? DB::table('donation_campaigns')->where('id', $data['campaignId'])->value('title')
                : null);

            return mb_substr($name
                ? "KHCWW DONATION - {$clean($name)} {$amountStr}"
                : "KHCWW DONATION - FUND DRIVE {$amountStr}", 0, 100);
        }

        return mb_strtoupper($paymentType)." PAYMENT TO KHCWW {$amountStr}";
    }

    protected function extractInternalCheckoutId(?string $internalReference): ?string
    {
        if (!$internalReference) {
            return null;
        }

        if (preg_match('/(ws_CO_[a-f0-9]+)/i', $internalReference, $matches)) {
            return $matches[1];
        }

        $parts = explode('-', $internalReference, 3);
        return $parts[2] ?? null;
    }

    protected function sendStkSuccessSms(?string $memberId, float $amount, string $receipt): void
    {
        if (! $this->sms || ! $memberId) {
            return;
        }

        $member = DB::table('members')->where('id', $memberId)->first();
        if (! $member || ! $member->phone) {
            return;
        }

        $amountStr = number_format($amount, 0, '.', ',');
        $message = "Your payment of KES {$amountStr} has been received. M-Pesa receipt: {$receipt}. Thank you. -KHCWW";

        try {
            $this->sms->send($member->phone, $message, 'payment_success', $memberId);
        } catch (\Throwable $e) {
            Log::warning('Failed to send STK success SMS', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendStkFailureSms(?string $memberId, float $amount): void
    {
        if (! $this->sms || ! $memberId || $amount <= 0) {
            return;
        }

        $member = DB::table('members')->where('id', $memberId)->first();
        if (! $member || ! $member->phone) {
            return;
        }

        $amountStr = number_format($amount, 0, '.', ',');
        $message = "Your STK payment of KES {$amountStr} could not be completed. Please try again or contact support. -KHCWW";

        try {
            $this->sms->send($member->phone, $message, 'payment_failed', $memberId);
        } catch (\Throwable $e) {
            Log::warning('Failed to send STK failure SMS', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getStatus(array $identity, string $checkoutRequestId): array
    {
        $canonical = DB::table('payments')
            ->where(function ($query) use ($checkoutRequestId): void {
                $query->where('id', $checkoutRequestId)
                    ->orWhere('internal_reference', $checkoutRequestId)
                    ->orWhere('provider_reference', $checkoutRequestId);
            })
            ->first();

        if ($canonical) {
            $roles = $identity['roles'] ?? [];
            $isStaff = AuthService::isStaff($roles);
            if (! $isStaff && ($identity['member_id'] ?? null) !== $canonical->member_id) {
                return ['success' => false, 'status' => 'forbidden', 'error' => 'Forbidden'];
            }

            if (in_array($canonical->status, ['confirmed', 'failed', 'cancelled', 'reversed'], true)) {
                if ($canonical->status === 'confirmed') {
                    $this->settleConfirmedCanonicalPayment($canonical);
                } else {
                    $this->syncLegacyStkStatus($canonical, (string) $canonical->status);
                }

                return [
                    'success' => $canonical->status === 'confirmed',
                    'status' => $canonical->status,
                    'payment_id' => $canonical->id,
                    'amount' => (float) ($canonical->amount ?? 0),
                    'final' => true,
                    'provider_reference' => $canonical->provider_reference,
                    'message' => $canonical->status === 'confirmed' ? 'Payment completed' : 'Payment finalized',
                ];
            }

            $providerReference = (string) ($canonical->provider_reference ?: $canonical->internal_reference);
            $providerStatus = $this->accountService->queryStkStatus($providerReference);
            $normalizedStatus = (string) ($providerStatus['status'] ?? 'unknown');
            if ($canonical->status === 'pending' && $normalizedStatus === 'unknown') {
                $normalizedStatus = 'pending';
                $providerStatus['status'] = 'pending';
                $providerStatus['message'] = $providerStatus['message'] ?? 'Payment still pending';
            }
            $updated = $this->paymentEngine->updateProviderStatus(
                (string) $canonical->id,
                $normalizedStatus,
                $providerStatus['message'] ?? null,
                $providerStatus['response'] ?? [],
            );

            if ($updated['status'] === 'confirmed') {
                $this->settleConfirmedCanonicalPayment($canonical);
            } else {
                $this->syncLegacyStkStatus($canonical, (string) $updated['status']);
            }

            return [
                'success' => $updated['status'] === 'confirmed',
                'status' => $updated['status'],
                'payment_id' => $updated['payment_id'],
                'amount' => (float) ($canonical->amount ?? 0),
                'final' => in_array($updated['status'], ['confirmed', 'failed', 'cancelled', 'reversed'], true),
                'internal_reference' => $updated['internal_reference'],
                'provider_reference' => $updated['provider_reference'],
                'message' => $providerStatus['message'] ?? 'Payment status checked',
                'provider_status' => $providerStatus['provider_status'] ?? null,
            ];
        }

        $penalty = PenaltyPaymentRecord::where('mpesa_transaction_id', $checkoutRequestId)->first();
        if ($penalty) {
            $status = $penalty->status;
            $bankStatus = $this->getBankStatusForRecord($checkoutRequestId);
            return [
                'status' => $status,
                'final' => in_array($status, ['verified', 'failed', 'reconciled']),
                'amount' => (float) $penalty->amount,
                'type' => 'PENALTY',
                'message' => $this->statusMessage($status),
                'is_customer_action_required' => $bankStatus['is_customer_action_required'] ?? false,
                'bank_status' => $bankStatus['status'] ?? null,
            ];
        }

        $donation = DonationPaymentRecord::where('mpesa_transaction_id', $checkoutRequestId)->first();
        if ($donation) {
            $status = $donation->status;
            $bankStatus = $this->getBankStatusForRecord($checkoutRequestId);
            return [
                'status' => $status,
                'final' => in_array($status, ['verified', 'failed', 'reconciled']),
                'amount' => (float) $donation->amount,
                'type' => 'DONATION',
                'message' => $this->statusMessage($status),
                'is_customer_action_required' => $bankStatus['is_customer_action_required'] ?? false,
                'bank_status' => $bankStatus['status'] ?? null,
            ];
        }

        $operational = \App\Models\OperationalPaymentRecord::where('mpesa_transaction_id', $checkoutRequestId)->first();
        if ($operational) {
            $status = $operational->status;
            $bankStatus = $this->getBankStatusForRecord($checkoutRequestId);
            return [
                'status' => $status,
                'final' => in_array($status, ['verified', 'failed', 'reconciled']),
                'amount' => (float) $operational->amount,
                'type' => 'OPERATIONAL',
                'message' => $this->statusMessage($status),
                'is_customer_action_required' => $bankStatus['is_customer_action_required'] ?? false,
                'bank_status' => $bankStatus['status'] ?? null,
            ];
        }

        $contribution = \App\Models\Contribution::where('stk_checkout_request_id', $checkoutRequestId)->first();
        if ($contribution) {
            $status = $contribution->status;
            $final = in_array($status, ['paid', 'failed', 'verified', 'reconciled']);

            return [
                'status' => $status,
                'final' => $final,
                'amount' => (float) $contribution->amount,
                'type' => 'CONTRIBUTION',
                'message' => $this->statusMessage($status),
                'is_customer_action_required' => false,
                'bank_status' => null,
            ];
        }

        return ['status' => 'unknown', 'final' => true, 'error' => 'Payment not found', 'message' => 'Payment record not found'];
    }

    private function settleConfirmedCanonicalPayment(object $payment): void
    {
        $type = (string) ($payment->payment_type ?? '');
        if (in_array($type, ['penalty', 'donation', 'operational'], true)) {
            $this->lifecycle->postPayment(
                $type,
                (string) $payment->member_id,
                (float) ($payment->amount_minor ?? 0) / 100,
                (string) ($payment->provider_reference ?: $payment->internal_reference),
                $payment->related_entity_id ?? null,
                'stk_status',
                (string) $payment->id,
                $payment->internal_reference ?? null,
            );
        } elseif ($type === 'monthly_contribution') {
            $this->allocation->projectCanonicalPayment((string) $payment->id);
        }
    }

    private function getBankStatusForRecord(string $checkoutRequestId): array
    {
        $transfer = CoopTransferRequest::where('internal_reference', 'like', '%' . $checkoutRequestId)
            ->where('transfer_type', 'STK')
            ->first();

        if (!$transfer) {
            return ['status' => null, 'is_customer_action_required' => false];
        }

        try {
            return $this->accountService->queryStkStatus($transfer->message_reference);
        } catch (\Throwable $e) {
            return ['status' => null, 'is_customer_action_required' => false];
        }
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'pending', 'processing' => 'Waiting for payment confirmation',
            'partial' => 'Payment is being confirmed',
            'verified', 'paid' => 'Payment successful',
            'failed' => 'Payment failed',
            'reconciled' => 'Payment reconciled',
            default => 'Payment status unknown',
        };
    }

    private function refreshStkStatus(string $checkoutRequestId, object $record): void
    {
        $transfer = CoopTransferRequest::where('internal_reference', 'like', '%' . $checkoutRequestId)
            ->where('transfer_type', 'STK')
            ->first();

        if (!$transfer) {
            return;
        }

        try {
            $bankStatus = $this->accountService->queryStkStatus($transfer->message_reference);
            $status = strtoupper($bankStatus['status'] ?? '');

            if ($status === 'SUCCESS' || $status === 'SUCCESSFUL') {
                $this->applyPolledStkStatus($checkoutRequestId, $transfer->message_reference, $bankStatus);
            } elseif ($status === 'FAILED' || $status === 'FAILURE') {
                $this->applyPolledStkStatus($checkoutRequestId, $transfer->message_reference, $bankStatus);
            } elseif ($status === 'PENDING' || $status === 'PROCESSING') {
                if ($record instanceof \App\Models\Contribution && $record->status !== 'pending') {
                    $record->update(['status' => 'pending', 'updated_at' => now()]);
                } elseif ($record instanceof \App\Models\PenaltyPaymentRecord && $record->status !== 'pending') {
                    $record->update(['status' => 'pending', 'updated_at' => now()]);
                } elseif ($record instanceof \App\Models\DonationPaymentRecord && $record->status !== 'pending') {
                    $record->update(['status' => 'pending', 'updated_at' => now()]);
                } elseif ($record instanceof \App\Models\OperationalPaymentRecord && $record->status !== 'pending') {
                    $record->update(['status' => 'pending', 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PaymentService: STK status refresh failed', [
                'checkout_request_id' => $checkoutRequestId,
                'message_reference' => $transfer->message_reference ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function processStkBankStatus(string $transferId, array $bankStatus): array
    {
        $transfer = CoopTransferRequest::find($transferId);

        if (!$transfer || $transfer->transfer_type !== 'STK') {
            return ['success' => false, 'message' => 'Transfer not found'];
        }

        $status = strtoupper($bankStatus['status'] ?? '');

        if (in_array($status, ['SUCCESS', 'SUCCESSFUL'], true)) {
            $paymentResult = $this->applyPolledStkStatus($transfer->internal_reference, $transfer->message_reference, $bankStatus);

            if ($paymentResult === null) {
                $settled = app(\App\Services\CoopBank\CoopBankReconciliationService::class)
                    ->settleSuccessfulTransfer($transfer);
                if (! $settled) {
                    $transfer->update([
                        'status' => 'reconciliation_required',
                        'error_message' => 'Provider confirmed STK but no matching payment record was found.',
                        'updated_at' => now(),
                    ]);

                    return ['success' => false, 'status' => 'reconciliation_required', 'message' => 'STK payment requires reconciliation'];
                }
            } elseif (($paymentResult['status'] ?? null) !== 'confirmed') {
                $transfer->update([
                    'status' => 'reconciliation_required',
                    'error_message' => 'Provider confirmed STK but canonical payment finalization conflicted.',
                    'updated_at' => now(),
                ]);

                return ['success' => false, 'status' => 'reconciliation_required', 'message' => 'STK payment requires reconciliation'];
            }

            $transfer->update([
                'status' => 'successful',
                'error_message' => null,
                'updated_at' => now(),
            ]);

            return ['success' => true, 'status' => 'successful', 'message' => 'STK payment reconciled'];
        }

        if (in_array($status, ['FAILED', 'FAILURE'], true)) {
            $paymentResult = $this->applyPolledStkStatus($transfer->internal_reference, $transfer->message_reference, $bankStatus);
            if ($paymentResult && ! in_array($paymentResult['status'] ?? null, ['failed', 'cancelled'], true)) {
                $transfer->update([
                    'status' => 'reconciliation_required',
                    'error_message' => 'Provider failure conflicts with finalized canonical payment.',
                    'updated_at' => now(),
                ]);

                return ['success' => false, 'status' => 'reconciliation_required', 'message' => 'STK payment requires reconciliation'];
            }

            $transfer->update([
                'status' => 'failed',
                'error_message' => $bankStatus['message'] ?? 'Failed',
                'updated_at' => now(),
            ]);

            return ['success' => true, 'status' => 'failed', 'message' => 'STK payment failed'];
        }

        return ['success' => true, 'status' => $status, 'message' => 'Still pending'];
    }

    private function applyPolledStkStatus(string $internalReference, ?string $providerReference, array $bankStatus): ?array
    {
        $paymentReferences = [$internalReference];
        if (preg_match('/(ws_CO_[a-f0-9]+)/i', $internalReference, $matches)) {
            $paymentReferences[] = $matches[1];
        }

        $payment = DB::table('payments')
            ->whereIn('internal_reference', $paymentReferences)
            ->first();

        if (! $payment && $providerReference) {
            $payment = DB::table('payments')->where('provider_reference', $providerReference)->first();
        }

        if (! $payment) {
            return null;
        }

        $providerStatus = strtoupper((string) ($bankStatus['status'] ?? 'UNKNOWN'));
        $normalized = match (true) {
            in_array($providerStatus, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED'], true) => 'success',
            in_array($providerStatus, ['FAILED', 'FAILURE', 'REJECTED'], true) => 'failed',
            in_array($providerStatus, ['CANCELLED', 'CANCELED'], true) => 'cancelled',
            in_array($providerStatus, ['PENDING', 'PROCESSING'], true) => 'pending',
            default => 'unknown',
        };

        $result = $this->paymentEngine->updateProviderStatus(
            (string) $payment->id,
            $normalized,
            $bankStatus['message'] ?? $bankStatus['ResultDesc'] ?? null,
            $bankStatus,
        );

        if (($result['status'] ?? null) === 'confirmed') {
            $confirmedPayment = DB::table('payments')->where('id', $payment->id)->first();
            if ($confirmedPayment) {
                $this->settleConfirmedCanonicalPayment($confirmedPayment);
            }
        } else {
            $this->syncLegacyStkStatus($payment, (string) ($result['status'] ?? ''));
        }

        return $result;
    }

    private function syncLegacyStkStatus(object $payment, string $status): void
    {
        if (! in_array($status, ['failed', 'cancelled'], true)) {
            return;
        }

        $table = match ((string) ($payment->payment_type ?? '')) {
            'donation' => 'donation_payment_records',
            'operational' => 'operational_payment_records',
            'penalty' => 'penalty_payment_records',
            default => null,
        };

        if (! $table || empty($payment->internal_reference)) {
            return;
        }

        DB::table($table)
            ->where('mpesa_transaction_id', $payment->internal_reference)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);
    }

    public function bankSync(array $identity, string $fromDate, string $toDate): array
    {
        $this->assertIsStaff($identity);

        $result = $this->accountService->getStatement('01134568843700', $fromDate, $toDate);

        return [
            'success' => true,
            'transactions' => $result['transactions'] ?? [],
            'transaction_count' => $result['transaction_count'] ?? 0,
        ];
    }

    public function reconcileStkTransfers(array $identity, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->assertIsStaff($identity);

        $fromDate = $fromDate ?: now()->subDays(7)->format('Y-m-d');
        $toDate = $toDate ?: now()->format('Y-m-d');

        $stuckTransfers = DB::table('coop_transfer_requests')
            ->where('transfer_type', 'STK')
            ->whereIn('status', ['processing', 'submitted', 'timeout'])
            ->whereBetween('created_at', ["{$fromDate} 00:00:00", "{$toDate} 23:59:59"])
            ->get();

        $reconciled = 0;
        $failed = 0;
        $results = [];

        foreach ($stuckTransfers as $transfer) {
            try {
                $bankStatus = $this->accountService->queryStkStatus($transfer->message_reference);
                $result = $this->processStkBankStatus((string) $transfer->id, $bankStatus);
                if (($result['status'] ?? null) === 'successful') {
                    $reconciled++;
                    $results[] = [
                        'transfer_id' => $transfer->id,
                        'message_reference' => $transfer->message_reference,
                        'status' => 'reconciled',
                        'bank_status' => $bankStatus,
                    ];
                } elseif (($result['status'] ?? null) === 'failed') {
                    $failed++;
                    $results[] = [
                        'transfer_id' => $transfer->id,
                        'message_reference' => $transfer->message_reference,
                        'status' => 'failed',
                        'bank_status' => $bankStatus,
                    ];
                } else {
                    $results[] = [
                        'transfer_id' => $transfer->id,
                        'message_reference' => $transfer->message_reference,
                        'status' => $result['status'] ?? 'pending',
                        'bank_status' => $bankStatus,
                    ];
                }
            } catch (\Throwable $e) {
                $results[] = [
                    'transfer_id' => $transfer->id,
                    'message_reference' => $transfer->message_reference,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'stuck_transfers' => $stuckTransfers->count(),
            'reconciled' => $reconciled,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    public function b2cTransfer(array $identity, array $data): array
    {
        $this->assertIsStaff($identity);

        $withdrawalId = (string) ($data['withdrawal_id'] ?? '');
        $walletType = strtolower((string) ($data['wallet_type'] ?? ''));
        if ($withdrawalId === '' || !array_key_exists($walletType, WithdrawalApprovalService::WALLET_TABLES)) {
            throw new HttpException(422, 'canonical_withdrawal_required', 'B2C transfers require an approved canonical withdrawal and wallet type.');
        }

        $amount = (float) ($data['amount'] ?? 0);
        $phone = (string) ($data['phone'] ?? $data['phone_number'] ?? '');

        if ($amount <= 0 || $phone === '') {
            throw new HttpException(400, 'invalid_request', 'Amount and phone are required');
        }

        $withdrawalTable = WithdrawalApprovalService::WALLET_TABLES[$walletType];
        $withdrawal = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();
        if (!$withdrawal) {
            throw new HttpException(404, 'withdrawal_not_found', 'Linked withdrawal not found.');
        }
        if ((float) $withdrawal->amount !== $amount
            || preg_replace('/\D+/', '', (string) $withdrawal->phone_number) !== preg_replace('/\D+/', '', $phone)) {
            throw new HttpException(409, 'withdrawal_details_mismatch', 'B2C amount and beneficiary must match the canonical withdrawal.');
        }

        $withdrawalService = app(WithdrawalApprovalService::class);
        $withdrawalService->setIdentity($identity);
        $withdrawalService->triggerB2CWithdrawal($walletType, $withdrawalId, $identity);
        $current = DB::table($withdrawalTable)->where('id', $withdrawalId)->first();

        return [
            'success' => in_array($current->status, ['submitted', 'processing', 'completed'], true),
            'transactionId' => $current->bank_transfer_id ?? null,
            'message_reference' => $current->b2c_reference ?? null,
            'status' => $current->status,
            'message' => 'Canonical withdrawal status returned.',
        ];
    }

    private function assertCanWrite(array $identity, string $memberId, string $paymentType): void
    {
        // Defense-in-depth: view-as-member sessions must never mutate data.
        if (! empty($identity['is_view_as_member'])) {
            throw new HttpException(403, 'view_mode_read_only', 'View Member mode is read-only. Payments cannot be initiated while viewing a member.');
        }

        if (AuthService::isStaff($identity['roles'])) {
            return;
        }

        if ($identity['member_id'] === $memberId) {
            return;
        }

        throw new HttpException(403, 'insufficient_permissions', 'You cannot initiate payments for other members.');
    }

    private function assertIsStaff(array $identity): void
    {
        if (! AuthService::isStaff($identity['roles'])) {
            throw new HttpException(403, 'insufficient_permissions', 'Staff access required.');
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
}
