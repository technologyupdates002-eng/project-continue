<?php

namespace App\Services;

use App\Models\Contribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContributionObligationService
{
    public function settings(): object
    {
        static $settings;
        if (!$settings) {
            $settings = DB::table('welfare_settings')->first() ?: (object)[
                'monthly_contribution_amount' => 300,
                'contribution_due_day' => 15,
                'penalty_amount' => 50,
                'penalty_grace_days' => 30,
            ];
        }
        return $settings;
    }

    public function monthly(): float
    {
        return (float) ($this->settings()->monthly_contribution_amount ?? 300);
    }

    public function dueDay(): int
    {
        return (int) ($this->settings()->contribution_due_day ?? 15);
    }

    public function penaltyAmount(): float
    {
        return (float) ($this->settings()->penalty_amount ?? 50);
    }

    public function graceDays(): int
    {
        return (int) ($this->settings()->penalty_grace_days ?? 30);
    }

    public function startDate(string $memberId, ?string $firstContributionDate = null): \DateTimeImmutable
    {
        $candidateDates = [
            $firstContributionDate,
            DB::table('members')->where('id', $memberId)->value('first_contribution_date'),
            DB::table('contributions')->where('member_id', $memberId)->where('amount', '>', 0)->min('due_date'),
            DB::table('contributions')->where('member_id', $memberId)->whereNotNull('paid_date')->min('paid_date'),
            DB::table('members')->where('id', $memberId)->value('created_at'),
        ];
        $candidate = collect($candidateDates)->filter()->sort()->first();

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr($candidate, 0, 10)) ?: new \DateTimeImmutable();
        return $date->setTime(0, 0);
    }

    public function memberSummary(string $memberId, ?iterable $preloadedContributions = null, ?iterable $preloadedPenalties = null): array
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        if (!$member) {
            return [];
        }

        $hasCanonicalObligationRows = Schema::hasTable('monthly_obligations')
            && DB::table('monthly_obligations')->where('member_id', $memberId)->exists();

        if ($hasCanonicalObligationRows && Schema::hasTable('payments')) {
            $canonical = app(FinancialReadModel::class)->memberOverview($memberId);
            if ($canonical !== []) {
                $obligationRows = DB::table('monthly_obligations')
                    ->where('member_id', $memberId)
                    ->orderBy('period_year')
                    ->orderBy('period_month')
                    ->get();

                $obligations = $obligationRows->map(function ($row) {
                    $required = (float) ($row->required_amount_minor ?? 0) / 100;
                    $paid = (float) ($row->paid_amount_minor ?? 0) / 100;
                    $amountDue = max(0, $required - $paid);

                    return [
                        'year' => (int) $row->period_year,
                        'month' => (int) $row->period_month,
                        'expected' => round($required, 2),
                        'is_paid' => (string) ($row->status ?? '') === 'paid',
                        'is_pending' => (string) ($row->status ?? '') === 'partial',
                        'is_overdue' => (string) ($row->status ?? '') === 'unpaid',
                        'received' => round($paid, 2),
                        'due_date' => sprintf('%04d-%02d-%02d', (int) $row->period_year, (int) $row->period_month, 1),
                        'grace_deadline' => sprintf('%04d-%02d-%02d', (int) $row->period_year, (int) $row->period_month, 1),
                    ];
                })->all();

                $paidMonths = (int) ($canonical['paid_months'] ?? 0);
                $partialMonths = (int) ($canonical['partial_months'] ?? 0);
                $unpaidMonths = (int) ($canonical['unpaid_months'] ?? 0);
                $overdueObligations = app(FinancialReadModel::class)->overdueObligationsForMember($memberId);
                $overdueMonths = $overdueObligations->count();
                $storedPenaltyAccrued = (float) DB::table('penalties')->where('member_id', $memberId)->sum('amount');
                $storedPenaltyPaid = (float) DB::table('penalties')
                    ->where('member_id', $memberId)
                    ->where('is_paid', 1)
                    ->sum('amount');
                $derivedPenaltyAccrued = $overdueMonths * $this->penaltyAmount();
                $penaltyAccrued = max($storedPenaltyAccrued, $derivedPenaltyAccrued);
                $penaltyOutstanding = (float) ($canonical['penalties_outstanding_minor'] ?? 0) / 100;
                $totalOutstanding = (float) (($canonical['outstanding_minor'] ?? 0) / 100) + $penaltyOutstanding;

                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'name' => $member->name,
                    'phone' => $member->phone,
                    'member_id' => $member->member_id,
                    'total_contributions' => (float) (($canonical['cash_received_minor'] ?? 0) / 100),
                    'contribution_count' => (int) ($canonical['confirmed_payment_count'] ?? 0),
                    'unpaid_count' => $partialMonths + $unpaidMonths,
                    'total_penalties' => $penaltyOutstanding,
                    'penalty_count' => (int) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->count(),
                    'last_contribution_date' => null,
                    'first_contribution_date' => $member->first_contribution_date ?? $member->created_at,
                    'member_since' => $member->first_contribution_date ?? $member->created_at,
                    'is_active' => (bool) $member->is_active,
                    'source' => $member->source,
                    'outstanding_amount' => $totalOutstanding,
                    'next_due_date' => null,
                    'days_remaining' => 0,
                    'is_overdue' => $overdueMonths > 0,
                    'days_overdue' => 0,
                    'paid_months' => $paidMonths,
                    'pending_months' => $partialMonths,
                    'overdue_months' => $overdueMonths,
                    'penalty_months' => (int) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->count(),
                    'penalty_amount' => $penaltyAccrued,
                    'penalty_paid' => $storedPenaltyPaid,
                    'penalty_outstanding' => $penaltyOutstanding,
                    'contribution_outstanding' => (float) (($canonical['outstanding_minor'] ?? 0) / 100),
                    'total_outstanding' => $totalOutstanding,
                    'is_defaulter' => $overdueMonths > 0,
                    'obligations' => $obligations,
                ];
            }
        }

        $start = $this->startDate($memberId);
        $monthly = $this->monthly();
        $graceDays = $this->graceDays();
        $today = new \DateTimeImmutable('today');

        if ($preloadedContributions !== null) {
            $contributions = collect($preloadedContributions)->filter(fn ($c) => $c->member_id === $memberId);
        } else {
            $contributions = DB::table('contributions')
                ->where('member_id', $memberId)
                ->get();
        }

        $receivedRows = $contributions->filter(fn ($c) => (float) $c->amount > 0 && (in_array($c->status, ['paid', 'partial'], true) || $c->paid_date !== null));
        $paidRows = $contributions->filter(fn ($c) => (float) $c->amount > 0 && ($c->status === 'paid' || $c->paid_date !== null));
        $totalPaid = (float) $receivedRows->sum('amount');
        $paidMonths = (int) $paidRows->unique(fn ($c) => (int) $c->year . '-' . (int) $c->month)->count();

        // Calculate months elapsed since first contribution date
        $monthsElapsed = 0;
        $cursor = $start;
        while (true) {
            $y = (int) $cursor->format('Y');
            $m = (int) $cursor->format('n');
            if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
                break;
            }
            $monthsElapsed++;
            $cursor = $cursor->modify('first day of next month');
            if ($monthsElapsed > 120) break;
        }

        // Calculate how many full months are covered by total payments
        $fullMonthsCovered = $totalPaid > 0 ? (int) floor($totalPaid / $monthly) : 0;
        $partialAmountRemaining = $totalPaid - ($fullMonthsCovered * $monthly);
        
        // Calculate outstanding based on elapsed time vs payment coverage
        $totalExpectedForElapsedMonths = $monthsElapsed * $monthly;
        $contributionOutstanding = max(0, $totalExpectedForElapsedMonths - $totalPaid);
        
        // Determine overdue vs pending based on grace period for current month
        $currentMonthDue = $today->modify('last day of this month');
        $currentMonthGrace = $currentMonthDue->modify('+' . $graceDays . ' days');
        $currentMonthIsPending = $today <= $currentMonthGrace;
        
        // Calculate how many months are owed
        $monthsOwed = max(0, $monthsElapsed - $fullMonthsCovered);
        
        // If we have partial payment, that reduces what's owed
        if ($partialAmountRemaining > 0 && $monthsOwed > 0) {
            // There's a partial payment covering part of the next month
            $hasPartialMonth = true;
            $monthsFullyOwed = max(0, $monthsOwed - 1);
        } else {
            $hasPartialMonth = false;
            $monthsFullyOwed = $monthsOwed;
        }
        
        // Split into overdue and pending
        if ($currentMonthIsPending && $monthsOwed > 0) {
            $pendingCount = 1;
            $overdueCount = max(0, $monthsFullyOwed - 1);
        } else {
            $pendingCount = 0;
            $overdueCount = $monthsFullyOwed;
        }
        
        $penaltyCount = $overdueCount;
        $penaltyAmount = (float) ($overdueCount * $this->penaltyAmount());
        
        $pendingAmount = $pendingCount > 0 ? min($contributionOutstanding, $monthly - $partialAmountRemaining) : 0;
        $overdueAmount = max(0, $contributionOutstanding - $pendingAmount);

        // Build obligations array for display/audit purposes
        $obligations = [];
        $current = $start;
        $idx = 0;
        while (true) {
            $y = (int) $current->format('Y');
            $m = (int) $current->format('n');
            if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
                break;
            }

            $row = $contributions->first(fn ($c) => (int) $c->year === $y && (int) $c->month === $m);
            $receivedAmount = $row ? (float) $row->amount : 0.0;

            $dueDate = \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $y, $m))->modify('last day of this month');
            $graceDeadline = $dueDate->modify('+' . $graceDays . ' days');
            
            // Determine status: is this month covered by payments?
            // Month is covered if it's within the number of full months we've paid for
            $monthIndex = $idx; // 0-based: 0 = first month
            $isFullyPaid = $monthIndex < $fullMonthsCovered;
            $isPartiallyPaid = !$isFullyPaid && $monthIndex == $fullMonthsCovered && $partialAmountRemaining > 0;
            $isCovered = $isFullyPaid || $isPartiallyPaid;
            
            $isPending = !$isCovered && $today <= $graceDeadline;
            $isOverdue = !$isCovered && $today > $graceDeadline;

            $obligations[] = [
                'year' => $y,
                'month' => $m,
                'expected' => $monthly,
                'is_paid' => $isFullyPaid,
                'is_pending' => $isPending || $isPartiallyPaid,
                'is_overdue' => $isOverdue,
                'received' => $receivedAmount,
                'due_date' => $dueDate->format('Y-m-d'),
                'grace_deadline' => $graceDeadline->format('Y-m-d'),
            ];

            $current = $current->modify('first day of next month');
            $idx++;
            if ($idx > 120) {
                break;
            }
        }

        if ($preloadedPenalties !== null) {
            $penaltyOutstanding = (float) collect($preloadedPenalties)
                ->filter(fn ($p) => $p->member_id === $memberId && !$p->is_paid)
                ->sum('amount');
        } else {
            $penaltyOutstanding = (float) DB::table('penalties')
                ->where('member_id', $memberId)
                ->where('is_paid', 0)
                ->sum('amount');
        }
        if (Schema::hasTable('penalty_waivers') && ! $hasCanonicalObligationRows) {
            $penaltyOutstanding = max(
                0,
                $penaltyOutstanding - (float) DB::table('penalty_waivers')
                    ->where('member_id', $memberId)
                    ->sum('amount_minor') / 100
            );
        }

        $totalOutstanding = $contributionOutstanding + $penaltyOutstanding;

        $oldestOverdue = collect($obligations)->where('is_overdue', true)->sortBy(fn ($o) => $o['year'] . '-' . str_pad($o['month'], 2, '0', STR_PAD_LEFT))->first();
        $isDefaulter = $overdueCount > 0;

        $nextDue = $this->nextDueDate($memberId);
        $todayDate = new \DateTimeImmutable('today');
        $daysRemaining = 0;
        if ($nextDue) {
            $daysRemaining = (int) $todayDate->diff($nextDue)->days;
        }

        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'name' => $member->name,
            'phone' => $member->phone,
            'member_id' => $member->member_id,
            'total_contributions' => $totalPaid,
            'contribution_count' => (int) $receivedRows->count(),
            'unpaid_count' => $pendingCount + $overdueCount,
            'total_penalties' => $penaltyOutstanding,
            'penalty_count' => (int) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 0)->count(),
            'last_contribution_date' => $paidRows->max('paid_date') ?: null,
            'first_contribution_date' => $start->format('Y-m-d'),
            'member_since' => $start->format('Y-m-d'),
            'is_active' => (bool) $member->is_active,
            'source' => $member->source,
            'outstanding_amount' => $totalOutstanding,
            'next_due_date' => $nextDue ? $nextDue->format('Y-m-d') : null,
            'days_remaining' => $daysRemaining,
            'is_overdue' => $isDefaulter,
            'days_overdue' => $oldestOverdue ? (int) $todayDate->diff(\DateTimeImmutable::createFromFormat('Y-m-d', $oldestOverdue['grace_deadline']))->days : 0,
            'paid_months' => $paidMonths,
            'pending_months' => $pendingCount,
            'overdue_months' => $overdueCount,
            'penalty_months' => $penaltyCount,
            'penalty_amount' => $penaltyAmount,
            'penalty_paid' => (float) DB::table('penalties')->where('member_id', $memberId)->where('is_paid', 1)->sum('amount'),
            'penalty_outstanding' => $penaltyOutstanding,
            'contribution_outstanding' => $contributionOutstanding,
            'total_outstanding' => $totalOutstanding,
            'is_defaulter' => $isDefaulter,
            'obligations' => $obligations,
        ];
    }

    public function nextDueDate(string $memberId): ?\DateTimeImmutable
    {
        $monthly = $this->monthly();
        $start = $this->startDate($memberId);
        $today = new \DateTimeImmutable('today');

        $contributions = DB::table('contributions')
            ->where('member_id', $memberId)
            ->get();

        // Calculate total paid and how many months that covers
        $totalPaid = (float) $contributions->sum('amount');
        $fullMonthsCovered = $totalPaid > 0 ? (int) floor($totalPaid / $monthly) : 0;
        $partialAmount = $totalPaid - ($fullMonthsCovered * $monthly);
        
        // Calculate months elapsed
        $monthsElapsed = 0;
        $cursor = $start;
        while (true) {
            $y = (int) $cursor->format('Y');
            $m = (int) $cursor->format('n');
            if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
                break;
            }
            $monthsElapsed++;
            $cursor = $cursor->modify('first day of next month');
            if ($monthsElapsed > 120) break;
        }
        
        // If payments cover all elapsed months plus more, next due is in the future
        if ($fullMonthsCovered >= $monthsElapsed) {
            $nextDue = $start->modify('+' . $fullMonthsCovered . ' months');
            return \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');
        }
        
        // If there's a partial payment for the next uncovered month
        if ($partialAmount > 0 && $fullMonthsCovered < $monthsElapsed) {
            $nextDue = $start->modify('+' . $fullMonthsCovered . ' months');
            return \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');
        }
        
        // Otherwise, next due is the first uncovered month
        $nextDue = $start->modify('+' . $fullMonthsCovered . ' months');
        $nextDueDate = \DateTimeImmutable::createFromFormat('Y-n-j', $nextDue->format('Y-m-01'))->modify('last day of this month');
        
        // If that month is overdue, return it
        if ($nextDueDate < $today) {
            return $nextDueDate;
        }
        
        return $nextDueDate;
    }

    public function summary(): array
    {
        $hasCanonicalObligationRows = Schema::hasTable('monthly_obligations')
            && DB::table('monthly_obligations')->exists();

        if ($hasCanonicalObligationRows && Schema::hasTable('payments')) {
            return app(FinancialReadModel::class)->summary();
        }

        $totalMembers = (int) DB::table('members')->count();
        $activeMembers = (int) DB::table('members')->where('is_active', 1)->count();
        $totalContributions = (float) DB::table('contributions')
            ->whereIn('status', ['paid', 'partial'])
            ->where('amount', '>', 0)
            ->sum('amount');
        $totalPenaltiesPaid = (float) DB::table('penalties')->where('is_paid', 1)->sum('amount');
        $totalPenaltiesOutstanding = 0.0;

        $members = DB::table('members')->select('id', 'first_contribution_date', 'created_at')->get();
        $paidMonthsByMember = DB::table('contributions')
            ->select('member_id', DB::raw('COUNT(DISTINCT CONCAT(year, "-", month)) as paid_months'))
            ->where(function ($query) {
                $query->where('status', 'paid')->orWhereNotNull('paid_date');
            })
            ->groupBy('member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->member_id => (int) $row->paid_months]);
        $outstandingPenaltiesByMember = DB::table('penalties')
            ->select('member_id', DB::raw('SUM(amount) as outstanding'))
            ->where('is_paid', 0)
            ->groupBy('member_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->member_id => (float) $row->outstanding]);
        $waivedPenaltiesByMember = Schema::hasTable('penalty_waivers')
            ? DB::table('penalty_waivers')
                ->select('member_id', DB::raw('SUM(amount_minor) / 100 as waived'))
                ->groupBy('member_id')
                ->get()
                ->mapWithKeys(fn ($row) => [$row->member_id => (float) $row->waived])
            : collect();

        $totalExpected = 0.0;
        $totalPaid = 0.0;
        $totalPending = 0.0;
        $totalOverdue = 0.0;
        $totalPenaltiesAccrued = 0.0;
        $defaulters = 0;
        $overdueMembers = 0;

        $today = new \DateTimeImmutable('today');
        $monthly = $this->monthly();
        $graceDays = $this->graceDays();
        $currentMonthDue = $today->modify('last day of this month');
        $currentMonthGrace = $currentMonthDue->modify('+' . $graceDays . ' days');
        $currentMonthPending = $today <= $currentMonthGrace;

        foreach ($members as $member) {
            $start = \DateTimeImmutable::createFromFormat('Y-m-d', substr($member->first_contribution_date ?? $member->created_at, 0, 10)) ?: new \DateTimeImmutable();
            $start = $start->setTime(0, 0);
            $paidMonths = $paidMonthsByMember->get($member->id, 0);
            $penaltyOutstanding = max(
                0,
                $outstandingPenaltiesByMember->get($member->id, 0.0)
                    - $waivedPenaltiesByMember->get($member->id, 0.0)
            );
            $totalPenaltiesOutstanding += $penaltyOutstanding;

            $totalMonths = 0;
            $cursor = $start;
            while (true) {
                $y = (int) $cursor->format('Y');
                $m = (int) $cursor->format('n');
                if ($y > (int) $today->format('Y') || ($y === (int) $today->format('Y') && $m > (int) $today->format('n'))) {
                    break;
                }
                $totalMonths++;
                $cursor = $cursor->modify('first day of next month');
                if ($totalMonths > 120) break;
            }

            $pendingCount = $currentMonthPending ? 1 : 0;
            $overdueCount = (int) max(0, $totalMonths - $paidMonths - $pendingCount);

            $penaltyAmount = (float) ($overdueCount * $this->penaltyAmount());

            $totalExpected += $paidMonths * $monthly + $pendingCount * $monthly + $overdueCount * $monthly;
            $totalPaid += (float) DB::table('contributions')
                ->where('member_id', $member->id)
                ->whereIn('status', ['paid', 'partial'])
                ->sum('amount');
            $totalPending += $pendingCount * $monthly;
            $totalOverdue += $overdueCount * $monthly;
            $totalPenaltiesAccrued += $penaltyAmount;
            $totalPenaltiesOutstanding += $penaltyOutstanding;

            if ($overdueCount > 0) {
                $overdueMembers++;
            }
            if ($overdueCount > 2) {
                $defaulters++;
            }
        }

        $totalContributionOutstanding = max(0, $totalExpected - $totalContributions);
        $totalPending = min($totalPending, $totalContributionOutstanding);
        $totalOverdue = max(0, $totalContributionOutstanding - $totalPending);
        $totalOutstanding = $totalContributionOutstanding + $totalPenaltiesOutstanding;

        return [
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'inactive_members' => $totalMembers - $activeMembers,
            'total_contributions' => $totalContributions,
            'total_expected' => $totalExpected,
            'total_paid' => $totalContributions,
            'total_pending' => $totalPending,
            'total_overdue' => $totalOverdue,
            'total_overdue_members' => $overdueMembers,
            'total_defaulters' => $defaulters,
            'total_penalties_accrued' => $totalPenaltiesAccrued,
            'total_penalties_paid' => $totalPenaltiesPaid,
            'total_penalties_outstanding' => $totalPenaltiesOutstanding,
            'total_contribution_outstanding' => $totalContributionOutstanding,
            'total_outstanding' => $totalOutstanding,
            'members_with_first_date' => (int) DB::table('members')->whereNotNull('first_contribution_date')->count(),
            'members_without_first_date' => (int) DB::table('members')->whereNull('first_contribution_date')->count(),
        ];
    }
}
