<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\ContributionObligationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ContributionReconciliationService
{
    public function __construct(
        private readonly ContributionObligationService $obligations,
        private readonly PaymentEngine $paymentEngine
    ) {}

    public function reconcileMember(string $memberId, bool $dryRun = true): array
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (! $member) {
            return ['member_id' => $memberId, 'error' => 'Member not found'];
        }

        $monthly = $this->obligations->monthly();

        $bankTransactions = $this->deduplicatedBankCredits($memberId);
        $bankTotal = array_sum(array_map(
            static fn (object $transaction): float => (float) $transaction->credit_amount,
            $bankTransactions
        ));

        $canonicalOverview = app(FinancialReadModel::class)->memberOverview($memberId);
        $canonicalTotal = (float) (($canonicalOverview['cash_received_minor'] ?? 0) / 100);
        $legacyTotal = 0.0;
        if ($canonicalTotal <= 0.0 && Schema::hasTable('contributions')) {
            $legacyTotal = (float) DB::table('contributions')
                ->where('member_id', $memberId)
                ->where('status', 'paid')
                ->sum('amount');
        }

        $totalPaid = $bankTotal > 0.0 ? $bankTotal : max($canonicalTotal, $legacyTotal);

        if ($totalPaid <= 0.0) {
            if (! $dryRun && $member->total_contributions != 0) {
                app(MemberFinancialProjectionService::class)->syncMember($memberId);
                DB::table('members')->where('id', $memberId)->update([
                    'total_penalties' => 0,
                    'updated_at' => now(),
                ]);
                $wasFixed = true;
            } else {
                $wasFixed = false;
            }

            return [
                'member_id' => $memberId,
                'member_name' => $member->name,
                'required_months' => 0,
                'paid_months' => 0,
                'overdue_months' => 0,
                'advance_months' => 0,
                'total_paid' => 0.0,
                'credit' => 0.0,
                'months_covered' => [],
                'fixed' => $wasFixed,
            ];
        }

        $startDate = $member->first_contribution_date ?? $member->created_at;
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', substr($startDate, 0, 10)) ?: new \DateTimeImmutable();
        $start = $start->setTime(0, 0);
        $now = new \DateTimeImmutable('today');

        $paidMonths = (int) floor($totalPaid / $monthly);
        $credit = $totalPaid - ($paidMonths * $monthly);

        $nextDue = $start->modify('+' . $paidMonths . ' months');
        $nextDue = \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');

        $requiredMonths = 0;
        $cursor = $start;
        while ($cursor <= $now) {
            $requiredMonths++;
            $cursor = $cursor->modify('+1 month');
        }

        $overdueMonths = max(0, $requiredMonths - $paidMonths);
        $advanceMonths = max(0, $paidMonths - $requiredMonths);

        $monthsCovered = [];
        $cursor = $start;
        for ($i = 0; $i < $paidMonths && $cursor <= $now; $i++) {
            $monthsCovered[] = ['year' => (int) $cursor->format('Y'), 'month' => (int) $cursor->format('n')];
            $cursor = $cursor->modify('+1 month');
        }

        $result = [
            'member_id' => $memberId,
            'member_name' => $member->name,
            'required_months' => $requiredMonths,
            'paid_months' => $paidMonths,
            'overdue_months' => $overdueMonths,
            'advance_months' => $advanceMonths,
            'total_paid' => round($totalPaid, 2),
            'credit' => round($credit, 2),
            'months_covered' => $monthsCovered,
            'next_due_date' => $nextDue->format('Y-m-d'),
            'fixed' => false,
        ];

        if (! $dryRun && $totalPaid > 0) {
            DB::transaction(function () use ($memberId, $totalPaid, $bankTotal) {
                if ($bankTotal > 0.0) {
                foreach ($this->deduplicatedBankCredits($memberId) as $tx) {
                    $reference = $this->normaliseBankReference((string) $tx->transaction_reference);
                    if (DB::table('payments')
                        ->where('member_id', $memberId)
                        ->where('status', 'confirmed')
                        ->where(function ($query) use ($reference): void {
                            $query->where('provider_reference', $reference)
                                ->orWhere('transaction_ref', $reference)
                                ->orWhere('transaction_reference', $reference);
                        })
                        ->exists()) {
                        continue;
                    }

                    try {
                        $this->paymentEngine->processProviderEvent([
                                'provider' => 'bank',
                                'provider_event_id' => $tx->transaction_reference,
                                'event_type' => 'bank_confirmed',
                                'member_id' => $memberId,
                                'amount_minor' => (int) round((float) $tx->credit_amount * 100),
                                'provider_reference' => $tx->transaction_reference,
                                'internal_reference' => $tx->transaction_reference,
                                'source' => 'bank_reconciliation',
                                'status' => 'confirmed',
                                'occurred_at' => $tx->transaction_date,
                                'payload' => ['source_id' => $tx->id],
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('ContributionReconciliationService: bank allocation failed', [
                                'member_id' => $memberId,
                                'bank_tx_id' => $tx->id,
                                'amount' => $tx->credit_amount,
                                'error' => $e->getMessage(),
                            ]);
                        }

                    }
                } else {
                    $paidContributions = DB::table('contributions')
                        ->where('member_id', $memberId)
                        ->where('status', 'paid')
                        ->orderBy('paid_date')
                        ->orderBy('created_at')
                        ->get(['id', 'amount', 'month', 'year', 'paid_date', 'status', 'payment_ref']);

                    $payments = [];
                    foreach ($paidContributions as $c) {
                        $ref = $c->payment_ref ?: ('__no_ref_' . $c->paid_date);
                        if (! isset($payments[$ref])) {
                            $payments[$ref] = [
                                'amount' => 0.0,
                                'paid_date' => $c->paid_date,
                                'payment_ref' => $c->payment_ref,
                            ];
                        }
                        $payments[$ref]['amount'] += (float) $c->amount;
                        if ($c->paid_date && (! $payments[$ref]['paid_date'] || $c->paid_date < $payments[$ref]['paid_date'])) {
                            $payments[$ref]['paid_date'] = $c->paid_date;
                        }
                    }

                    uasort($payments, function ($a, $b) {
                        return $a['paid_date'] <=> $b['paid_date'];
                    });

                    foreach ($payments as $payment) {
                        try {
                            $reference = $payment['payment_ref'] ?: 'legacy-' . $memberId . '-' . $payment['paid_date'];
                            $this->paymentEngine->processProviderEvent([
                                'provider' => 'legacy_manual',
                                'provider_event_id' => $reference,
                                'event_type' => 'manual_confirmed',
                                'member_id' => $memberId,
                                'amount_minor' => (int) round($payment['amount'] * 100),
                                'provider_reference' => $reference,
                                'internal_reference' => $reference,
                                'source' => 'legacy_reconciliation',
                                'status' => 'confirmed',
                                'occurred_at' => $payment['paid_date'],
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning('ContributionReconciliationService: contribution allocation failed', [
                                'member_id' => $memberId,
                                'payment_ref' => $payment['payment_ref'],
                                'amount' => $payment['amount'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            });

            $result['fixed'] = true;
        }

        return $result;
    }

    public function reconcileAll(bool $dryRun = true): array
    {
        $members = DB::table('members')
            ->where('is_active', 1)
            ->get(['id', 'name']);

        $results = [];
        foreach ($members as $member) {
            $results[] = $this->reconcileMember($member->id, $dryRun);
        }

        return $results;
    }

    /**
     * Returns one row per normalized bank reference. Import aliases such as
     * 078CB123456 and CB123456 represent the same statement event.
     */
    private function deduplicatedBankCredits(string $memberId): array
    {
        $rows = DB::table('bank_transactions')
            ->where('member_id', $memberId)
            ->where(function ($query): void {
                $query->whereNull('transaction_type')->orWhere('transaction_type', 'CREDIT');
            })
            ->where('credit_amount', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('category')->orWhere('category', '!=', 'donation');
            })
            ->orderByDesc('source')
            ->orderBy('transaction_date')
            ->orderBy('created_at')
            ->get(['id', 'member_id', 'credit_amount', 'transaction_date', 'transaction_reference', 'source']);

        $deduplicated = [];
        foreach ($rows as $row) {
            $reference = $this->normaliseBankReference((string) $row->transaction_reference);
            if ($reference === '') {
                continue;
            }
            if (! isset($deduplicated[$reference])) {
                $deduplicated[$reference] = $row;
            }
        }

        return array_values($deduplicated);
    }

    private function normaliseBankReference(string $reference): string
    {
        return preg_replace('/^\d{3}(?=CB)/', '', trim($reference)) ?: trim($reference);
    }
}
