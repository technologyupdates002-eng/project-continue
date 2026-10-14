<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialReadModel
{
    private const CONTRIBUTION_SOURCES = [
        'stk',
        'stk_monthly_contribution',
        'bank_sync',
        'bank_import',
        'bank_reconciliation',
        'bank_manual_reconciliation',
        'legacy_reconciliation',
        'contribution_allocation',
        'manual',
    ];

    public function confirmedContributionTotals(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $query = DB::table('payments')
            ->where('status', 'confirmed');

        $this->scopeContributionPayments($query);

        if ($from !== null) {
            $query->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$from->format('Y-m-d H:i:s')]);
        }
        if ($to !== null) {
            $query->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$to->format('Y-m-d H:i:s')]);
        }

        $row = $query->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_minor), 0) as amount_minor')->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'amount_minor' => (int) ($row->amount_minor ?? 0),
        ];
    }

    public function contributionTotalsIncludingLegacy(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $canonical = $this->confirmedContributionTotals($from, $to);
        $legacy = $this->legacyContributionRows($from, $to);

        $adjustments = $this->adjustmentTotals($from, $to);
        return [
            'count' => $canonical['count'] + $legacy->count(),
            'amount_minor' => $canonical['amount_minor'] + (int) round((float) $legacy->sum('amount') * 100) + $adjustments,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function confirmedContributionSeries(\DateTimeInterface $from, \DateTimeInterface $to, ?string $memberId = null): array
    {
        $query = DB::table('payments')
            ->where('status', 'confirmed');
        $this->scopeContributionPayments($query);
        $query
            ->whereRaw('COALESCE(occurred_at, created_at) >= ?', [$from->format('Y-m-d H:i:s')])
            ->whereRaw('COALESCE(occurred_at, created_at) <= ?', [$to->format('Y-m-d H:i:s')]);

        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        }

        return $query->selectRaw("DATE_FORMAT(COALESCE(occurred_at, created_at), '%Y-%m') as period, SUM(amount_minor) / 100 as total")
            ->groupByRaw("DATE_FORMAT(COALESCE(occurred_at, created_at), '%Y-%m')")
            ->pluck('total', 'period')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    public function bankContributionTotals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->bankCreditTotals($from, $to, true);
    }

    public function bankIncomeTotals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->bankCreditTotals($from, $to);
    }

    public function bankIncomeSeries(\DateTimeInterface $from, \DateTimeInterface $to, ?string $memberId = null): array
    {
        $query = DB::table('bank_transactions')
            ->where('transaction_type', 'CREDIT')
            ->where('transaction_state', '!=', 'reversed')
            ->whereBetween('transaction_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        }
        return $query->selectRaw("DATE_FORMAT(transaction_date, '%Y-%m') as period, SUM(credit_amount) as total")
            ->groupByRaw("DATE_FORMAT(transaction_date, '%Y-%m')")
            ->pluck('total', 'period')
            ->map(fn ($total): float => (float) $total)
            ->all();
    }

    private function bankCreditTotals(\DateTimeInterface $from, \DateTimeInterface $to, bool $contributionsOnly = false): array
    {
        $query = DB::table('bank_transactions')
            ->where('transaction_type', 'CREDIT')
            ->where('transaction_state', '!=', 'reversed');
        if ($contributionsOnly) {
            $query->where('category', 'monthly_contribution');
        }
        $query
            ->whereBetween('transaction_date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);

        $row = $query->selectRaw('COUNT(*) as count, COALESCE(SUM(credit_amount), 0) as amount')->first();

        return [
            'count' => (int) ($row->count ?? 0),
            'amount' => (float) ($row->amount ?? 0),
        ];
    }

    public function recentConfirmedPayments(int $limit = 10): array
    {
        return DB::table('payments')
            ->join('members', 'members.id', '=', 'payments.member_id')
            ->where('payments.status', 'confirmed')
            ->orderByDesc(DB::raw('COALESCE(payments.occurred_at, payments.created_at)'))
            ->limit($limit)
            ->get([
                'payments.id',
                'payments.member_id',
                DB::raw('payments.amount_minor / 100 as amount'),
                'payments.status',
                'payments.provider_reference',
                DB::raw('COALESCE(payments.occurred_at, payments.created_at) as created_at'),
                'members.name as member_name',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function memberOverview(string $memberId): array
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (! $member) {
            return [];
        }

        $confirmedPayments = DB::table('payments')
            ->where('member_id', $memberId)
            ->where('status', 'confirmed');
        $this->scopeContributionPayments($confirmedPayments);
        $visibleObligations = collect($this->obligations($memberId));
        $allocations = DB::table('contribution_allocations')->where('member_id', $memberId);
        $carryForward = DB::table('carry_forward_balances')->where('member_id', $memberId);

        $required = (int) $visibleObligations->sum('required_minor');
        $paid = (int) $visibleObligations->sum('paid_minor');
        $carry = (int) $carryForward->sum('remaining_amount_minor');
        $cashReceived = (int) $confirmedPayments->sum('amount_minor');
        $legacyCompatibility = $this->legacyCompatibilityTotals($memberId);
        $cashReceived += $legacyCompatibility['cash_received_minor'] + $this->adjustmentTotals(null, null, $memberId);
        $cashPaidMonths = intdiv(max(0, $cashReceived), $monthlyMinor = max(1, (int) round(((float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300)) * 100)));
        $cashRemainderMinor = max(0, $cashReceived) % $monthlyMinor;
        $allocated = (int) $allocations->sum('amount_minor');
        $cashNextDueDate = $this->periodAwareNextDueDate(
            $memberId,
            $visibleObligations,
            $cashReceived,
            $allocated,
            $carry,
            $monthlyMinor,
            $member->first_contribution_date ?? $member->created_at,
        );
        $penalties = DB::table('penalties')->where('member_id', $memberId);
        $penaltiesAccrued = (int) round((float) (clone $penalties)->sum('amount') * 100);
        $penaltiesPaid = (int) round((float) (clone $penalties)->where('is_paid', 1)->sum('amount') * 100);
        $penaltiesWaived = Schema::hasTable('penalty_waivers')
            ? (int) DB::table('penalty_waivers')->where('member_id', $memberId)->sum('amount_minor')
            : 0;
        $penaltyRateMinor = (int) round(((float) (DB::table('welfare_settings')->value('penalty_amount') ?? 50)) * 100);
        $derivedPenaltyAccrued = (int) $visibleObligations->where('status', 'unpaid')->count() * $penaltyRateMinor;
        $penaltiesAccrued = max($penaltiesAccrued, $derivedPenaltyAccrued);
        $penaltiesOutstanding = max(0, $penaltiesAccrued - $penaltiesPaid - $penaltiesWaived);

        return [
            'member_id' => $memberId,
            'name' => $member->name,
            'status' => $member->status,
            'confirmed_payment_count' => (int) $confirmedPayments->count(),
            'confirmed_payment_minor' => $cashReceived,
            'cash_received_minor' => $cashReceived,
            'adjustment_minor' => $this->adjustmentTotals(null, null, $memberId),
            'allocated_minor' => $allocated,
            'unapplied_minor' => max(0, $cashReceived - $allocated),
            'partial_minor' => (int) $visibleObligations
                ->where('status', 'partial')
                ->sum(fn (array $row): int => max(0, $row['required_minor'] - $row['paid_minor'])),
            'advance_minor' => $carry,
            'required_minor' => $required,
            'paid_minor' => $paid,
            'outstanding_minor' => max(0, $required - $paid),
            'carry_forward_minor' => $carry,
            'penalties_accrued_minor' => $penaltiesAccrued,
            'penalties_paid_minor' => $penaltiesPaid,
            'penalties_waived_minor' => $penaltiesWaived,
            'penalties_outstanding_minor' => $penaltiesOutstanding,
            'obligation_counts' => [
                'unpaid' => $visibleObligations->where('status', 'unpaid')->count(),
                'partial' => $visibleObligations->where('status', 'partial')->count(),
                'paid' => $visibleObligations->where('status', 'paid')->count(),
                'advance_covered' => $visibleObligations->where('status', 'advance_covered')->count(),
            ],
            'paid_months' => $cashPaidMonths,
            'cash_paid_months' => $cashPaidMonths,
            'cash_remainder_minor' => $cashRemainderMinor,
            'cash_next_due_date' => $cashNextDueDate,
            'partial_months' => $visibleObligations->where('status', 'partial')->count(),
            'unpaid_months' => $visibleObligations->where('status', 'unpaid')->count(),
            'advance_months' => intdiv($carry, $monthlyMinor),
            'open_exception_count' => (int) DB::table('financial_exceptions')
                ->where(function ($query) use ($memberId): void {
                    $query->where('member_id', $memberId)->orWhereNull('member_id');
                })
                ->whereIn('investigation_status', ['open', 'investigating'])
                ->count(),
        ];
    }

    private function adjustmentTotals(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $memberId = null,
    ): int {
        if (! Schema::hasTable('contribution_adjustments')) {
            return 0;
        }
        $query = DB::table('contribution_adjustments');
        if ($memberId !== null) {
            $query->where('member_id', $memberId);
        }
        if ($from !== null) {
            $query->where('created_at', '>=', $from->format('Y-m-d H:i:s'));
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to->format('Y-m-d H:i:s'));
        }
        return (int) $query->sum('amount_minor');
    }

    private function periodAwareNextDueDate(
        string $memberId,
        \Illuminate\Support\Collection $obligations,
        int $cashReceivedMinor,
        int $allocatedMinor,
        int $storedCarryForwardMinor,
        int $monthlyMinor,
        mixed $memberStart,
    ): ?string {
        $periods = $obligations
            ->mapWithKeys(fn (array $row): array => [
                sprintf('%04d-%02d', $row['year'], $row['month']) => [
                    'required_minor' => $row['required_minor'],
                    'paid_minor' => $row['paid_minor'],
                ],
            ]);

        if (Schema::hasTable('contributions')) {
            DB::table('contributions')
                ->where('member_id', $memberId)
                ->whereIn('status', ['paid', 'partial'])
                ->orderBy('year')
                ->orderBy('month')
                ->get(['year', 'month', 'amount'])
                ->each(function (object $row) use ($periods, $monthlyMinor): void {
                    $key = sprintf('%04d-%02d', $row->year, $row->month);
                    if (! $periods->has($key)) {
                        $periods->put($key, [
                            'required_minor' => $monthlyMinor,
                            'paid_minor' => (int) round((float) $row->amount * 100),
                        ]);
                    }
                });
        }

        $remainingCarry = max(
            $storedCarryForwardMinor,
            $cashReceivedMinor - $allocatedMinor,
        );
        $firstOpenPeriod = null;
        $lastCoveredPeriod = null;

        foreach ($periods->sortKeys() as $period => $values) {
            $requiredMinor = max($monthlyMinor, (int) $values['required_minor']);
            $paidMinor = min($requiredMinor, (int) $values['paid_minor']);
            $carryApplied = min(max(0, $requiredMinor - $paidMinor), $remainingCarry);
            $remainingCarry -= $carryApplied;
            if ($paidMinor + $carryApplied < $requiredMinor) {
                $firstOpenPeriod ??= $period;
                continue;
            }
            $lastCoveredPeriod = $period;
        }

        $nextPeriod = $firstOpenPeriod
            ?: $lastCoveredPeriod;

        if ($nextPeriod === null) {
            if (! $memberStart) {
                return null;
            }
            $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $memberStart, 0, 10));
            return $startDate === false
                ? null
                : $startDate->modify('+' . intdiv(max(0, $cashReceivedMinor), $monthlyMinor) . ' months')->format('Y-m-d');
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $nextPeriod . '-01');
        if ($date === false) {
            return null;
        }

        if ($firstOpenPeriod === null) {
            $date = $date->modify('+1 month');
        }

        return $date->format('Y-m-d');
    }

    /**
     * Preserve historical contribution rows that have no confirmed canonical
     * payment/allocation, without counting rows already represented canonically.
     *
     * @return array{cash_received_minor:int,paid_months:int}
     */
    private function legacyCompatibilityTotals(string $memberId): array
    {
        if (! Schema::hasTable('contributions')) {
            return ['cash_received_minor' => 0, 'paid_months' => 0];
        }

        $rows = DB::table('contributions as c')
            ->leftJoin('payments as p', 'p.id', '=', 'c.payment_id')
            ->where('c.member_id', $memberId)
            ->whereIn('c.status', ['paid', 'partial'])
            ->whereNull('p.id')
            ->get(['c.amount', 'c.status', 'c.year', 'c.month']);

        return [
            'cash_received_minor' => (int) round((float) $rows->sum('amount') * 100),
            'paid_months' => $rows
                ->filter(fn (object $row): bool => (string) $row->status === 'paid')
                ->map(fn (object $row): string => "{$row->year}-{$row->month}")
                ->unique()
                ->count(),
        ];
    }

    public function obligations(string $memberId): array
    {
        $monthlyMinor = (int) round(((float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300)) * 100);
        $storedCarryForwardMinor = (int) DB::table('carry_forward_balances')
            ->where('member_id', $memberId)
            ->sum('remaining_amount_minor');
        $confirmedPayments = DB::table('payments')
            ->where('member_id', $memberId)
            ->where('status', 'confirmed');
        $this->scopeContributionPayments($confirmedPayments);
        $cashReceivedMinor = (int) $confirmedPayments->sum('amount_minor');
        $cashReceivedMinor += $this->legacyCompatibilityTotals($memberId)['cash_received_minor'];
        $allocatedMinor = (int) DB::table('contribution_allocations')
            ->where('member_id', $memberId)
            ->sum('amount_minor');
        $unappliedCashMinor = max(0, $cashReceivedMinor - $allocatedMinor);
        $remainingCarryForward = max($storedCarryForwardMinor, $unappliedCashMinor);
        $canonicalPaid = DB::table('contribution_allocations as allocations')
            ->join('monthly_obligations as obligations', 'obligations.id', '=', 'allocations.obligation_id')
            ->where('obligations.member_id', $memberId)
            ->groupBy('obligations.period_year', 'obligations.period_month')
            ->selectRaw('obligations.period_year, obligations.period_month, SUM(allocations.amount_minor) as paid_minor')
            ->get()
            ->keyBy(fn (object $row): string => "{$row->period_year}-{$row->period_month}");

        $legacyPaid = Schema::hasTable('contributions')
            ? DB::table('contributions')
                ->where('member_id', $memberId)
                ->whereIn('status', ['paid', 'partial'])
                ->groupBy('year', 'month')
                ->selectRaw('year, month, SUM(amount) as paid')
                ->get()
                ->keyBy(fn (object $row): string => "{$row->year}-{$row->month}")
            : collect();

        return DB::table('monthly_obligations')
            ->where('member_id', $memberId)
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get()
            ->map(function (object $obligation) use ($monthlyMinor, $canonicalPaid, $legacyPaid, &$remainingCarryForward): array {
                $key = "{$obligation->period_year}-{$obligation->period_month}";
                $allocatedMinor = (int) ($canonicalPaid->get($key)->paid_minor ?? 0);
                $legacyMinor = (int) round((float) ($legacyPaid->get($key)->paid ?? 0) * 100);
                $paidMinor = max((int) $obligation->paid_amount_minor, $allocatedMinor, $legacyMinor);
                $requiredMinor = max((int) $obligation->required_amount_minor, $monthlyMinor);
                $carryAppliedMinor = min(max(0, $requiredMinor - $paidMinor), $remainingCarryForward);
                $remainingCarryForward -= $carryAppliedMinor;
                $effectivePaidMinor = $paidMinor + $carryAppliedMinor;
                $status = $effectivePaidMinor >= $requiredMinor
                    ? 'paid'
                    : ($effectivePaidMinor > 0 ? 'partial' : 'unpaid');

                return [
                    'id' => $obligation->id,
                    'year' => (int) $obligation->period_year,
                    'month' => (int) $obligation->period_month,
                    'required_minor' => $requiredMinor,
                    'paid_minor' => min($effectivePaidMinor, $requiredMinor),
                    'status' => $status,
                ];
            })
            ->all();
    }

    public function statement(string $memberId): array
    {
        $canonicalRows = DB::table('payments as payments')
            ->where('payments.member_id', $memberId)
            ->where('payments.status', 'confirmed')
            ->orderBy('payments.occurred_at')
            ->orderBy('payments.created_at')
            ->get([
                'payments.id as payment_id',
                'payments.occurred_at',
                'payments.provider_name',
                'payments.provider_reference',
                'payments.source',
                'payments.payment_type',
                'payments.status',
                'payments.amount_minor as payment_amount_minor',
            ])
            ->map(function (object $row): array {
                $allocatedMinor = (int) DB::table('contribution_allocations')
                    ->where('payment_id', $row->payment_id)
                    ->sum('amount_minor');

                return [
                    'payment_id' => $row->payment_id,
                    'occurred_at' => $row->occurred_at,
                    'provider_name' => $row->provider_name,
                    'provider_reference' => $row->provider_reference,
                    'source' => $row->source,
                    'payment_type' => $row->payment_type,
                    'status' => $row->status,
                    'payment_amount_minor' => (int) $row->payment_amount_minor,
                    'allocated_amount_minor' => $allocatedMinor,
                    'allocation_type' => null,
                    'period_year' => null,
                    'period_month' => null,
                ];
            })
            ->all();

        $legacyRows = $this->legacyContributionRows(null, null, $memberId)
            ->map(fn (object $row): array => [
                'payment_id' => null,
                'occurred_at' => $row->paid_date ?? $row->created_at,
                'provider_name' => 'Historical record',
                'provider_reference' => $row->payment_ref,
                'source' => 'legacy_compatibility',
                'payment_type' => 'monthly_contribution',
                'status' => 'confirmed',
                'payment_amount_minor' => (int) round((float) $row->amount * 100),
                'allocated_amount_minor' => (int) round((float) $row->amount * 100),
                'allocation_type' => 'legacy_compatibility',
                'period_year' => $row->year,
                'period_month' => $row->month,
            ])
            ->all();

        $adjustmentRows = Schema::hasTable('contribution_adjustments')
            ? DB::table('contribution_adjustments')
                ->where('member_id', $memberId)
                ->orderBy('created_at')
                ->get()
                ->map(fn (object $row): array => [
                    'payment_id' => $row->id,
                    'occurred_at' => $row->created_at,
                    'provider_name' => 'Internal adjustment',
                    'provider_reference' => null,
                    'source' => 'internal_adjustment',
                    'payment_type' => 'monthly_contribution',
                    'status' => 'confirmed',
                    'payment_amount_minor' => (int) $row->amount_minor,
                    'allocated_amount_minor' => 0,
                    'allocation_type' => 'adjustment',
                    'period_year' => null,
                    'period_month' => null,
                    'adjustment_reason' => $row->reason,
                    'adjustment_category' => $row->category,
                ])
                ->all()
            : [];

        return collect(array_merge($canonicalRows, $legacyRows, $adjustmentRows))
            ->sortBy('occurred_at')
            ->values()
            ->all();
    }

    private function legacyContributionRows(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?string $memberId = null
    ): \Illuminate\Support\Collection {
        if (! Schema::hasTable('contributions')) {
            return collect();
        }

        $query = DB::table('contributions as c')
            ->leftJoin('payments as p', 'p.id', '=', 'c.payment_id')
            ->whereIn('c.status', ['paid', 'partial'])
            ->whereNull('p.id');

        if ($memberId !== null) {
            $query->where('c.member_id', $memberId);
        }
        if ($from !== null) {
            $query->whereRaw('COALESCE(c.paid_date, c.created_at) >= ?', [$from->format('Y-m-d H:i:s')]);
        }
        if ($to !== null) {
            $query->whereRaw('COALESCE(c.paid_date, c.created_at) <= ?', [$to->format('Y-m-d H:i:s')]);
        }

        return $query->get([
            'c.amount', 'c.status', 'c.year', 'c.month', 'c.paid_date',
            'c.payment_ref', 'c.created_at',
        ]);
    }

    public function summary(): array
    {
        $totalMembers = (int) DB::table('members')->count();
        $activeMembers = (int) DB::table('members')->where('is_active', 1)->count();

        $confirmedContribution = $this->contributionTotalsIncludingLegacy();
        $totalContributions = (float) ($confirmedContribution['amount_minor'] ?? 0) / 100;
        $currentMonth = now();
        $currentMonthContribution = $this->bankContributionTotals(
            $currentMonth->copy()->startOfMonth(),
            $currentMonth->copy()->endOfMonth(),
        );
        $currentMonthIncome = $this->bankIncomeTotals(
            $currentMonth->copy()->startOfMonth(),
            $currentMonth->copy()->endOfMonth(),
        );
        $expectedContributionTotals = $this->expectedContributionTotals();

        $requiredMinor = 0;
        $paidMinor = 0;
        $partialMinor = 0;
        $unpaidMinor = 0;
        $overdueMinor = 0;

        if (Schema::hasTable('monthly_obligations')) {
            $requiredMinor = (int) DB::table('monthly_obligations')->sum('required_amount_minor');
            $paidMinor = (int) DB::table('monthly_obligations')->sum('paid_amount_minor');
            $partialMinor = (int) DB::table('monthly_obligations')
                ->where('status', 'partial')
                ->sum(DB::raw('required_amount_minor - paid_amount_minor'));
            $unpaidMinor = (int) DB::table('monthly_obligations')
                ->where('status', 'unpaid')
                ->sum('required_amount_minor');
            $overdueMinor = max(0, $requiredMinor - $paidMinor - $partialMinor);
        }

        $totalPayments = (int) DB::table('payments')->where('status', 'confirmed')->count();
        $derivedPenaltiesAccrued = $this->overdueObligations()->count() * $this->penaltyAmount();
        $recordedPenaltiesAccrued = (float) DB::table('penalties')->sum('amount');
        $totalPenaltiesAccrued = max($recordedPenaltiesAccrued, $derivedPenaltiesAccrued);
        $totalPenaltiesPaid = (int) round((float) DB::table('penalties')->where('is_paid', 1)->sum('amount') * 100);
        $totalPenaltiesWaived = Schema::hasTable('penalty_waivers')
            ? (int) DB::table('penalty_waivers')->sum('amount_minor')
            : 0;
        $totalPenaltiesOutstanding = max(0, $totalPenaltiesAccrued * 100 - $totalPenaltiesPaid - $totalPenaltiesWaived) / 100;
        $unpaidPenaltyAmounts = DB::table('penalties')
            ->where('is_paid', 0)
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(amount) * 100 as outstanding_minor')
            ->get()
            ->keyBy('member_id');
        $waivedPenaltyAmounts = Schema::hasTable('penalty_waivers')
            ? DB::table('penalty_waivers')
                ->groupBy('member_id')
                ->selectRaw('member_id, SUM(amount_minor) as waived_minor')
                ->get()
                ->keyBy('member_id')
            : collect();
        $penaltyMembers = $unpaidPenaltyAmounts
            ->filter(fn (object $row): bool =>
                (int) $row->outstanding_minor
                > (int) ($waivedPenaltyAmounts->get($row->member_id)->waived_minor ?? 0)
            )
            ->count();

        return [
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'inactive_members' => max(0, $totalMembers - $activeMembers),
            'total_contributions' => $totalContributions,
            'current_month_contributions' => $currentMonthContribution['amount'],
            'current_month_income' => $currentMonthIncome['amount'],
            'total_expected' => $expectedContributionTotals['amount'],
            'total_paid' => $totalContributions,
            'total_pending' => (float) ($partialMinor / 100),
            'total_overdue' => (float) ($overdueMinor / 100),
            'total_overdue_members' => $this->overdueObligations()->pluck('member_id')->unique()->count(),
            'total_defaulters' => count($this->defaulters()),
            'total_penalties_accrued' => $totalPenaltiesAccrued,
            'total_penalties_paid' => $totalPenaltiesPaid / 100,
            'total_penalties_outstanding' => $totalPenaltiesOutstanding,
            'total_penalty_members' => $penaltyMembers,
            'total_contribution_outstanding' => max(0, $expectedContributionTotals['amount'] - $totalContributions),
            'total_outstanding' => max(0, $expectedContributionTotals['amount'] - $totalContributions) + $totalPenaltiesOutstanding,
            'members_with_first_date' => (int) DB::table('members')->whereNotNull('first_contribution_date')->count(),
            'members_without_first_date' => (int) DB::table('members')->whereNull('first_contribution_date')->count(),
            'confirmed_payment_count' => $totalPayments,
        ];
    }

    /**
     * Expected collection from each member's verified first contribution
     * month through the current month, inclusive.
     */
    private function expectedContributionTotals(): array
    {
        $today = new \DateTimeImmutable('first day of this month');
        $memberMonths = 0;

        foreach (DB::table('members')->get(['first_contribution_date', 'created_at']) as $member) {
            $startValue = $member->first_contribution_date ?? $member->created_at;
            $start = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $startValue, 0, 10));
            if ($start === false) {
                continue;
            }

            $start = $start->modify('first day of this month');
            if ($start > $today) {
                continue;
            }

            $memberMonths += ((int) $today->format('Y') - (int) $start->format('Y')) * 12
                + (int) $today->format('n') - (int) $start->format('n') + 1;
        }

        return [
            'member_months' => $memberMonths,
            'amount' => round($memberMonths * (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300), 2),
        ];
    }

    /**
     * Return members with currently unpaid monthly obligations for staff
     * dashboards. This is deliberately derived from obligations, not legacy
     * member projection columns.
     */
    public function unpaidObligationMembers(int $limit = 100): array
    {
        $overdueIds = $this->overdueObligations()->pluck('id');

        return DB::table('monthly_obligations as obligations')
            ->join('members', 'members.id', '=', 'obligations.member_id')
            ->whereIn('obligations.id', $overdueIds)
            ->select([
                'members.id',
                'members.name',
                DB::raw('SUM(obligations.required_amount_minor - obligations.paid_amount_minor) / 100 as outstanding'),
            ])
            ->groupBy('members.id', 'members.name')
            ->orderByDesc('outstanding')
            ->limit($limit)
            ->get()
            ->map(fn ($member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'outstanding' => (float) $member->outstanding,
            ])
            ->all();
    }

    /**
     * Return members with overdue monthly obligations or a positive penalty
     * balance. The same set backs both the admin count and the Defaulters page.
     */
    public function defaulters(): array
    {
        $obligations = $this->resolvedObligations();
        $graceDays = (int) (DB::table('welfare_settings')->value('penalty_grace_days') ?? 30);
        $overdueByMember = $obligations
            ->filter(fn (array $obligation): bool => $this->isOverdue($obligation, $graceDays))
            ->groupBy('member_id');
        $unpaidObligationsByMember = $obligations
            ->where('status', 'unpaid')
            ->groupBy('member_id');
        $partialObligationsByMember = $obligations
            ->where('status', 'partial')
            ->groupBy('member_id');

        $penaltiesByMember = Schema::hasTable('penalties')
            ? DB::table('penalties')
                ->groupBy('member_id')
                ->selectRaw(
                    'member_id, SUM(amount) as accrued, SUM(CASE WHEN is_paid = 1 THEN amount ELSE 0 END) as paid, SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count'
                )
                ->get()
                ->keyBy('member_id')
            : collect();
        $contributionCounts = DB::table('payments')->where('status', 'confirmed');
        $this->scopeContributionPayments($contributionCounts);
        $contributionCounts = $contributionCounts
            ->groupBy('member_id')
            ->selectRaw('member_id, COUNT(*) as payment_count')
            ->pluck('payment_count', 'member_id');
        $waivedByMember = Schema::hasTable('penalty_waivers')
            ? DB::table('penalty_waivers')
                ->groupBy('member_id')
                ->selectRaw('member_id, SUM(amount_minor) as waived_minor')
                ->get()
                ->keyBy('member_id')
            : collect();

        $penaltyRateMinor = (int) round($this->penaltyAmount() * 100);
        $penaltyMemberIds = $penaltiesByMember
            ->filter(function (object $penalties) use ($waivedByMember, $unpaidObligationsByMember, $penaltyRateMinor): bool {
                $accruedMinor = max(
                    (int) round((float) $penalties->accrued * 100),
                    $unpaidObligationsByMember->get($penalties->member_id, collect())->count() * $penaltyRateMinor,
                );

                return max(
                    0,
                    $accruedMinor
                    - (int) round((float) $penalties->paid * 100)
                    - (int) ($waivedByMember->get($penalties->member_id)->waived_minor ?? 0),
                ) > 0;
            })
            ->keys();
        $memberIds = $overdueByMember->keys()
            ->merge($penaltyMemberIds)
            ->unique()
            ->values();

        if ($memberIds->isEmpty()) {
            return [];
        }

        $members = DB::table('members')
            ->whereIn('id', $memberIds)
            ->get(['id', 'name', 'phone', 'member_id', 'status'])
            ->keyBy('id');
        $defaulters = [];

        foreach ($memberIds as $memberId) {
            $member = $members->get($memberId);
            if (! $member) {
                continue;
            }

            $overdue = $overdueByMember->get($memberId, collect());
            $contributionOutstandingMinor = (int) $overdue->sum(
                fn (array $obligation): int => max(
                    0,
                    $obligation['required_minor'] - $obligation['paid_minor'],
                ),
            );
            $penalties = $penaltiesByMember->get($memberId);
            $penaltiesAccruedMinor = max(
                (int) round((float) ($penalties->accrued ?? 0) * 100),
                $unpaidObligationsByMember->get($memberId, collect())->count() * $penaltyRateMinor,
            );
            $penaltyOutstandingMinor = max(
                0,
                $penaltiesAccruedMinor
                - (int) round((float) ($penalties->paid ?? 0) * 100)
                - (int) ($waivedByMember->get($memberId)->waived_minor ?? 0),
            );

            if ($contributionOutstandingMinor === 0 && $penaltyOutstandingMinor === 0) {
                continue;
            }

            $defaulters[] = [
                'id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone,
                'member_id' => $member->member_id,
                'status' => $member->status,
                'is_defaulter' => true,
                'contribution_outstanding' => $contributionOutstandingMinor / 100,
                'penalty_outstanding' => $penaltyOutstandingMinor / 100,
                'total_outstanding' => ($contributionOutstandingMinor + $penaltyOutstandingMinor) / 100,
                'pending_months' => $partialObligationsByMember->get($memberId, collect())->count()
                    + $unpaidObligationsByMember->get($memberId, collect())->count(),
                'overdue_months' => $overdue->count(),
                'contribution_count' => (int) ($contributionCounts->get($memberId) ?? 0),
                'penalty_count' => (int) ($penalties->unpaid_count ?? 0),
                'penalty_amount' => $penaltiesAccruedMinor / 100,
            ];
        }

        usort($defaulters, static fn (array $left, array $right): int =>
            $right['total_outstanding'] <=> $left['total_outstanding']
        );

        return $defaulters;
    }

    /**
     * Return only obligations that are past their configured due-date grace
     * period. Current-month and future obligations remain pending, not
     * defaulters, until their due date and grace period have elapsed.
     */
    private function overdueObligations(): \Illuminate\Support\Collection
    {
        $graceDays = (int) (DB::table('welfare_settings')->value('penalty_grace_days') ?? 30);

        return $this->resolvedObligations()
            ->filter(fn (array $obligation): bool => $this->isOverdue($obligation, $graceDays))
            ->map(fn (array $obligation): array => [
                'id' => $obligation['id'],
                'member_id' => $obligation['member_id'],
                'period_year' => $obligation['year'],
                'period_month' => $obligation['month'],
                'required_amount_minor' => $obligation['required_minor'],
                'paid_amount_minor' => $obligation['paid_minor'],
                'status' => $obligation['status'],
            ])
            ->values();
    }

    /**
     * Resolve obligation payments and carry-forward in batches for staff
     * financial reads, avoiding one set of queries per member.
     */
    private function resolvedObligations(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('monthly_obligations')) {
            return collect();
        }

        $obligations = DB::table('monthly_obligations as obligations')
            ->join('members', 'members.id', '=', 'obligations.member_id')
            ->orderBy('obligations.member_id')
            ->orderBy('obligations.period_year')
            ->orderBy('obligations.period_month')
            ->get([
                'obligations.id',
                'obligations.member_id',
                'obligations.period_year',
                'obligations.period_month',
                'obligations.required_amount_minor',
                'obligations.paid_amount_minor',
            ]);

        if ($obligations->isEmpty()) {
            return collect();
        }

        $monthlyMinor = (int) round(
            (float) (DB::table('welfare_settings')->value('monthly_contribution_amount') ?? 300) * 100
        );
        $allocatedByObligation = DB::table('contribution_allocations')
            ->join('monthly_obligations', 'monthly_obligations.id', '=', 'contribution_allocations.obligation_id')
            ->groupBy('monthly_obligations.member_id', 'monthly_obligations.period_year', 'monthly_obligations.period_month')
            ->selectRaw(
                'monthly_obligations.member_id, monthly_obligations.period_year, monthly_obligations.period_month, SUM(contribution_allocations.amount_minor) as paid_minor'
            )
            ->get()
            ->keyBy(fn (object $row): string => "{$row->member_id}:{$row->period_year}-{$row->period_month}");
        $allocatedByMember = DB::table('contribution_allocations')
            ->join('monthly_obligations', 'monthly_obligations.id', '=', 'contribution_allocations.obligation_id')
            ->groupBy('monthly_obligations.member_id')
            ->selectRaw('monthly_obligations.member_id, SUM(contribution_allocations.amount_minor) as amount_minor')
            ->pluck('amount_minor', 'member_id');
        $storedCarryByMember = DB::table('carry_forward_balances')
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(remaining_amount_minor) as amount_minor')
            ->pluck('amount_minor', 'member_id');

        $confirmedPayments = DB::table('payments')->where('status', 'confirmed');
        $this->scopeContributionPayments($confirmedPayments);
        $confirmedCashByMember = $confirmedPayments
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(amount_minor) as amount_minor')
            ->pluck('amount_minor', 'member_id');

        $legacyPaidByPeriod = collect();
        $legacyCashByMember = collect();
        if (Schema::hasTable('contributions')) {
            $legacyPaidByPeriod = DB::table('contributions')
                ->whereIn('status', ['paid', 'partial'])
                ->groupBy('member_id', 'year', 'month')
                ->selectRaw('member_id, year, month, SUM(amount) as amount')
                ->get()
                ->keyBy(fn (object $row): string => "{$row->member_id}:{$row->year}-{$row->month}");

            $legacyCashByMember = DB::table('contributions as contributions')
                ->leftJoin('payments', 'payments.id', '=', 'contributions.payment_id')
                ->whereIn('contributions.status', ['paid', 'partial'])
                ->whereNull('payments.id')
                ->groupBy('contributions.member_id')
                ->selectRaw('contributions.member_id, SUM(contributions.amount) * 100 as amount_minor')
                ->pluck('amount_minor', 'member_id');
        }

        $carryByMember = [];
        foreach ($obligations->pluck('member_id')->unique() as $memberId) {
            $cashReceivedMinor = (int) ($confirmedCashByMember->get($memberId) ?? 0)
                + (int) round((float) ($legacyCashByMember->get($memberId) ?? 0));
            $unappliedCashMinor = max(
                0,
                $cashReceivedMinor - (int) ($allocatedByMember->get($memberId) ?? 0),
            );
            $carryByMember[$memberId] = max(
                (int) ($storedCarryByMember->get($memberId) ?? 0),
                $unappliedCashMinor,
            );
        }

        $resolved = [];
        foreach ($obligations as $obligation) {
            $memberId = (string) $obligation->member_id;
            $periodKey = "{$memberId}:{$obligation->period_year}-{$obligation->period_month}";
            $requiredMinor = max((int) $obligation->required_amount_minor, $monthlyMinor);
            $allocatedMinor = (int) ($allocatedByObligation->get($periodKey)->paid_minor ?? 0);
            $legacyMinor = (int) round((float) ($legacyPaidByPeriod->get($periodKey)->amount ?? 0) * 100);
            $paidMinor = max((int) $obligation->paid_amount_minor, $allocatedMinor, $legacyMinor);
            $carryAppliedMinor = min(max(0, $requiredMinor - $paidMinor), $carryByMember[$memberId] ?? 0);
            $carryByMember[$memberId] = max(0, ($carryByMember[$memberId] ?? 0) - $carryAppliedMinor);
            $effectivePaidMinor = $paidMinor + $carryAppliedMinor;
            $resolved[] = [
                'id' => $obligation->id,
                'member_id' => $memberId,
                'year' => (int) $obligation->period_year,
                'month' => (int) $obligation->period_month,
                'required_minor' => $requiredMinor,
                'paid_minor' => min($effectivePaidMinor, $requiredMinor),
                'status' => $effectivePaidMinor >= $requiredMinor
                    ? 'paid'
                    : ($effectivePaidMinor > 0 ? 'partial' : 'unpaid'),
            ];
        }

        return collect($resolved);
    }

    private function isOverdue(array $obligation, int $graceDays): bool
    {
        if (! in_array($obligation['status'], ['unpaid', 'partial'], true)) {
            return false;
        }

        $dueDate = now()
            ->setDate((int) $obligation['year'], (int) $obligation['month'], 1)
            ->endOfMonth()
            ->addDays($graceDays);

        return $dueDate->isPast();
    }

    public function overdueObligationsForMember(string $memberId): \Illuminate\Support\Collection
    {
        return $this->overdueObligations()->where('member_id', $memberId)->values();
    }

    public function overdueObligationsForAccrual(): \Illuminate\Support\Collection
    {
        return $this->overdueObligations();
    }

    public function penaltyAmount(): float
    {
        return (float) (DB::table('welfare_settings')->value('penalty_amount') ?? 50);
    }

    /**
     * Return members with unpaid penalties for staff dashboards.
     */
    public function unpaidPenaltyMembers(int $limit = 100): array
    {
        return DB::table('penalties')
            ->join('members', 'members.id', '=', 'penalties.member_id')
            ->where('penalties.is_paid', 0)
            ->select([
                'members.id',
                'members.name',
                DB::raw('SUM(penalties.amount) as outstanding'),
            ])
            ->groupBy('members.id', 'members.name')
            ->orderByDesc('outstanding')
            ->limit($limit)
            ->get()
            ->map(fn ($member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'outstanding' => (float) $member->outstanding,
            ])
            ->all();
    }

    public function openExceptions(?string $memberId = null): array
    {
        $query = DB::table('financial_exceptions')
            ->whereIn('investigation_status', ['open', 'investigating'])
            ->orderBy('created_at');

        if ($memberId !== null) {
            $query->where(function ($builder) use ($memberId): void {
                $builder->where('member_id', $memberId)->orWhereNull('member_id');
            });
        }

        return $query->get()->map(fn (object $exception): array => (array) $exception)->all();
    }

    private function scopeContributionPayments(\Illuminate\Database\Query\Builder $query): void
    {
        $hasPaymentType = Schema::hasColumn('payments', 'payment_type');
        $hasSource = Schema::hasColumn('payments', 'source');

        if (! $hasPaymentType && ! $hasSource) {
            return;
        }

        $query->where(function ($builder) use ($hasPaymentType, $hasSource): void {
            if ($hasPaymentType) {
                $builder->where('payment_type', 'monthly_contribution');
            }

            if ($hasSource) {
                $sourceClause = $hasPaymentType ? 'orWhere' : 'where';
                $builder->{$sourceClause}(function ($sourceQuery): void {
                    $sourceQuery->whereIn('source', self::CONTRIBUTION_SOURCES);
                    if (Schema::hasColumn('payments', 'payment_type')) {
                        $sourceQuery->whereNull('payment_type');
                    }
                });
            }
        });
    }
}
