<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Services\PaymentEngine;
use App\Services\PaymentService;
use App\Support\Phone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PendingStkBankReconciliation
{
    private const MATCH_WINDOW_HOURS = 48;

    public function __construct(
        private readonly PaymentEngine $paymentEngine,
        private readonly PaymentService $paymentService,
    ) {}

    /**
     * @return array{status:string,payment_id?:string,reason?:string}
     */
    public function reconcile(
        string $memberId,
        string $phone,
        int $amountMinor,
        string $occurredAt,
        string $bankReference,
        string $narration = '',
    ): array {
        $checkoutId = null;
        if (preg_match('/(ws_CO_[a-f0-9]+)/i', $narration, $matches)) {
            $checkoutId = $matches[1];
        }

        $date = Carbon::parse($occurredAt);
        $hasTime = preg_match('/(?:T|\s)\d{1,2}:\d{2}/', $occurredAt) === 1;
        $windowEnd = $hasTime ? $date->copy()->addMinutes(5) : $date->copy()->endOfDay();
        $payments = DB::table('payments')
            ->where('member_id', $memberId)
            ->where('amount_minor', $amountMinor)
            ->where('source', 'like', 'stk%')
            ->whereIn('status', ['pending', 'confirmed', 'failed', 'cancelled', 'reversed'])
            ->when($checkoutId, fn ($query) => $query->where('internal_reference', $checkoutId))
            ->when(! $checkoutId, fn ($query) => $query->whereBetween('created_at', [
                $date->copy()->subHours(self::MATCH_WINDOW_HOURS)->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            ]))
            ->orderBy('created_at')
            ->get();

        $normalizedPhone = Phone::normalize($phone);
        $transferQuery = DB::table('coop_transfer_requests')
            ->where('transfer_type', 'STK')
            ->whereIn('status', ['processing', 'submitted', 'timeout', 'successful', 'reconciliation_required', 'failed']);
        if (! $checkoutId) {
            $transferQuery->whereBetween('created_at', [
                $date->copy()->subHours(self::MATCH_WINDOW_HOURS)->toDateTimeString(),
                $windowEnd->toDateTimeString(),
            ]);
        }
        $transfers = $transferQuery->get();

        $candidates = [];
        foreach ($payments as $payment) {
            $internalReference = (string) ($payment->internal_reference ?? '');
            if ($internalReference === '') {
                continue;
            }

            foreach ($transfers as $transfer) {
                if (! str_ends_with((string) $transfer->internal_reference, $internalReference)) {
                    continue;
                }
                if ((int) round((float) $transfer->amount * 100) !== $amountMinor) {
                    continue;
                }
                if (Phone::normalize((string) $transfer->destination_account) !== $normalizedPhone) {
                    continue;
                }

                $candidates[] = ['payment' => $payment, 'transfer' => $transfer];
            }
        }

        if (count($candidates) !== 1) {
            if ($candidates !== []) {
                return $this->recordException(
                    'ambiguous_stk_bank_match',
                    $bankReference,
                    $memberId,
                    $amountMinor,
                    array_map(static fn (array $candidate): string => (string) $candidate['payment']->id, $candidates),
                );
            }

            if ($payments->isNotEmpty()) {
                return $this->recordException(
                    'stk_bank_payment_conflict',
                    $bankReference,
                    $memberId,
                    $amountMinor,
                    $payments->pluck('id')->map(static fn ($id): string => (string) $id)->all(),
                );
            }

            if ($checkoutId) {
                $payment = DB::table('payments')->where('internal_reference', $checkoutId)->first();
                if ($payment) {
                    return $this->recordException(
                        'stk_bank_payment_conflict',
                        $bankReference,
                        $memberId,
                        $amountMinor,
                        [(string) $payment->id],
                    );
                }
            }

            return ['status' => 'none'];
        }

        $candidate = $candidates[0];
        $payment = $candidate['payment'];
        $transfer = $candidate['transfer'];

        if (in_array((string) $payment->status, ['failed', 'cancelled', 'reversed'], true)
            || (string) $transfer->status === 'failed') {
            return $this->recordException(
                'stk_bank_payment_conflict',
                $bankReference,
                $memberId,
                $amountMinor,
                [(string) $payment->id],
            );
        }

        $result = $this->paymentService->processStkBankStatus((string) $transfer->id, [
            'status' => 'SUCCESS',
            'Status' => 'SUCCESS',
            'provider_status' => 'BANK_STATEMENT_CREDIT',
            'message' => 'Bank statement confirms the STK credit.',
            'TransactionReference' => $bankReference,
        ]);

        if (($result['status'] ?? null) !== 'successful') {
            return $this->recordException(
                'stk_bank_payment_conflict',
                $bankReference,
                $memberId,
                $amountMinor,
                [(string) $payment->id],
            );
        }

        return ['status' => 'reconciled', 'payment_id' => (string) $payment->id];
    }

    /**
     * @param list<string> $paymentIds
     * @return array{status:string,reason:string}
     */
    private function recordException(
        string $type,
        string $bankReference,
        string $memberId,
        int $amountMinor,
        array $paymentIds,
    ): array {
        $this->paymentEngine->createFinancialException($type, 'bank_statement_sync', [
            'bank_reference' => $bankReference,
            'member_id' => $memberId,
            'amount_minor' => $amountMinor,
            'candidate_payment_ids' => $paymentIds,
        ]);

        return ['status' => 'conflict', 'reason' => $type];
    }
}
