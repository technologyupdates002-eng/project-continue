<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\Models\Contribution;
use App\Services\ContributionObligationService;
use App\Services\MemberFinancialProjectionService;
use App\Support\Uuid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContributionAllocationService
{
    public function __construct(
        private readonly ContributionObligationService $obligations,
        private readonly MemberFinancialProjectionService $memberFinancialProjection,
    ) {}

    public function projectCanonicalPayment(string $paymentId): ?string
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment || ! DB::table('contribution_allocations')->where('payment_id', $paymentId)->exists()) {
            return null;
        }

        $allocations = DB::table('contribution_allocations as allocations')
            ->join('monthly_obligations as obligations', 'obligations.id', '=', 'allocations.obligation_id')
            ->where('allocations.payment_id', $paymentId)
            ->select([
                'obligations.member_id',
                'obligations.period_year',
                'obligations.period_month',
                'obligations.required_amount_minor',
                'obligations.paid_amount_minor',
            ])
            ->distinct()
            ->get();

        $firstContributionId = null;
        $paymentReference = (string) ($payment->provider_reference ?: $payment->transaction_ref ?: $payment->internal_reference ?: $paymentId);
        $paidDate = substr((string) ($payment->confirmed_at ?: $payment->occurred_at ?: now()->toDateTimeString()), 0, 10);

        foreach ($allocations as $allocation) {
            $amount = max(0, (int) $allocation->paid_amount_minor) / 100;
            $requiredMinor = max(0, (int) $allocation->required_amount_minor);
            $status = $amount <= 0 ? 'pending' : ((int) $allocation->paid_amount_minor >= $requiredMinor ? 'paid' : 'partial');
            $values = [
                'amount' => $amount,
                'payment_ref' => $paymentReference,
                'paid_date' => $status === 'paid' ? $paidDate : null,
                'status' => $status,
                'payment_id' => $paymentId,
                'updated_at' => now(),
            ];

            $existing = DB::table('contributions')
                ->where('member_id', $allocation->member_id)
                ->where('year', $allocation->period_year)
                ->where('month', $allocation->period_month)
                ->first();

            if ($existing) {
                DB::table('contributions')->where('id', $existing->id)->update($values);
                $firstContributionId ??= (string) $existing->id;
                continue;
            }

            $contributionId = Uuid::v4();
            DB::table('contributions')->insert(array_merge($values, [
                'id' => $contributionId,
                'member_id' => $allocation->member_id,
                'month' => $allocation->period_month,
                'year' => $allocation->period_year,
                'due_date' => date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $allocation->period_year, $allocation->period_month))),
                'created_at' => now(),
            ]));
            $firstContributionId ??= $contributionId;
        }

        $this->memberFinancialProjection->syncMember((string) $payment->member_id);

        return $firstContributionId;
    }

    public function allocatePayment(
        string $memberId,
        float $amount,
        ?string $paymentDate = null,
        ?string $txRef = null,
        ?string $bankTxId = null,
    ): AllocationResult {
        $result = new AllocationResult();
        $result->memberId = $memberId;
        $result->paymentDate = $paymentDate ?: date('Y-m-d');

        if ($amount <= 0) {
            return $result;
        }

        $monthly = $this->obligations->monthly();
        if ($monthly <= 0) {
            Log::warning('ContributionAllocationService: monthly contribution is zero', ['member_id' => $memberId]);
            return $result;
        }

        if ($txRef) {
            $existingPayment = DB::table('payments')
                ->where('member_id', $memberId)
                ->where('transaction_ref', $txRef)
                ->first();

            if ($existingPayment) {
                $allocatedAmount = (float) DB::table('contributions')
                    ->where('member_id', $memberId)
                    ->where('payment_id', $existingPayment->id)
                    ->whereIn('status', ['paid', 'partial'])
                    ->sum('amount');

                if (abs($allocatedAmount - $amount) < 0.01) {
                    $result->nextDueDate = $this->computeNextDueDate($memberId);
                    $result->monthsFullyPaid = (int) DB::table('contributions')
                        ->where('member_id', $memberId)
                        ->where('payment_id', $existingPayment->id)
                        ->where('status', 'paid')
                        ->count();
                    $result->monthsAllocated = $result->monthsFullyPaid;
                    Log::info('ContributionAllocationService: idempotent skip', [
                        'member_id' => $memberId,
                        'txRef' => $txRef,
                        'amount' => $amount,
                        'allocated' => $allocatedAmount,
                    ]);
                    return $result;
                }
            }
        }

        $paymentTs = strtotime($result->paymentDate) ?: time();
        $now = strtotime(date('Y-m-01', $paymentTs));

        DB::transaction(function () use ($memberId, $amount, $paymentTs, $txRef, $bankTxId, $monthly, $now, &$result) {
            $start = $this->memberStartTs($memberId, $result->paymentDate);
            $cursor = $start;

            $remaining = $amount;
            $monthsTouched = 0;
            $maxMonths = 240;

            $existingRows = DB::table('contributions')
                ->where('member_id', $memberId)
                ->get(['id', 'month', 'year', 'amount', 'status', 'payment_ref', 'paid_date'])
                ->keyBy(fn ($r) => $r->year . '-' . $r->month);

            $paymentId = $this->findOrCreatePayment($memberId, $amount, $result->paymentDate, $txRef);

            while ($remaining > 0.0001 && $monthsTouched < $maxMonths) {
                $y = (int) date('Y', $cursor);
                $m = (int) date('n', $cursor);
                $key = $y . '-' . $m;
                $row = $existingRows->get($key);

                $already = $row ? (float) $row->amount : 0.0;
                $status = $row->status ?? 'pending';
                $isPaid = $status === 'paid';
                $isPendingOrPartial = in_array($status, ['pending', 'partial'], true);
                $stillNeeded = $isPaid ? 0.0 : max(0.0, $monthly - $already);
                $take = $isPaid ? 0.0 : min($stillNeeded, $remaining);

                if ($isPendingOrPartial && $already >= $monthly && $stillNeeded <= 0.0001) {
                    $newAmount = $monthly;
                    $paidDate = date('Y-m-d', $paymentTs);
                    $paidStatus = 'paid';

                    if ($row) {
                        DB::table('contributions')
                            ->where('id', $row->id)
                            ->update([
                                'amount' => $newAmount,
                                'payment_ref' => $txRef ?: $row->payment_ref,
                                'paid_date' => $paidDate,
                                'status' => $paidStatus,
                                'payment_id' => $paymentId,
                                'updated_at' => now(),
                            ]);
                        $result->wasUpdated = true;
                        $result->monthsUpdated++;
                    } else {
                        DB::table('contributions')->insert([
                            'id' => Uuid::v4(),
                            'member_id' => $memberId,
                            'amount' => $newAmount,
                            'month' => $m,
                            'year' => $y,
                            'due_date' => date('Y-m-t', $cursor),
                            'payment_ref' => $txRef,
                            'paid_date' => $paidDate,
                            'status' => $paidStatus,
                            'payment_id' => $paymentId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $result->wasCreated = true;
                        $result->monthsCreated++;
                    }

                    $remaining -= $monthly;
                    $monthsTouched++;
                    $result->monthsFullyPaid++;

                    if ($monthsTouched === 1) {
                        $result->firstMonth = sprintf('%04d-%02d', $y, $m);
                    }
                    $result->lastMonth = sprintf('%04d-%02d', $y, $m);
                } elseif ($take > 0.0001) {
                    $newAmount = $already + $take;
                    $dueDate = date('Y-m-t', $cursor);
                    $paidDate = $take >= $stillNeeded ? date('Y-m-d', $paymentTs) : null;
                    $status = $take >= $stillNeeded ? 'paid' : 'partial';

                    if ($row) {
                        DB::table('contributions')
                            ->where('id', $row->id)
                            ->update([
                                'amount' => $newAmount,
                                'payment_ref' => $txRef ?: $row->payment_ref,
                                'paid_date' => $paidDate ?? $row->paid_date,
                                'status' => $status,
                                'payment_id' => $paymentId,
                                'updated_at' => now(),
                            ]);
                        $result->wasUpdated = true;
                        $result->monthsUpdated++;
                    } else {
                        DB::table('contributions')->insert([
                            'id' => Uuid::v4(),
                            'member_id' => $memberId,
                            'amount' => $newAmount,
                            'month' => $m,
                            'year' => $y,
                            'due_date' => $dueDate,
                            'payment_ref' => $txRef,
                            'paid_date' => $paidDate,
                            'status' => $status,
                            'payment_id' => $paymentId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $result->wasCreated = true;
                        $result->monthsCreated++;
                    }

                    $remaining -= $take;
                    $monthsTouched++;

                    if ($status === 'paid') {
                        $result->monthsFullyPaid++;
                    }

                    if ($monthsTouched === 1) {
                        $result->firstMonth = sprintf('%04d-%02d', $y, $m);
                    }
                    $result->lastMonth = sprintf('%04d-%02d', $y, $m);
                }

                $cursor = strtotime('+1 month', $cursor);
            }

            $result->monthsAllocated = $result->monthsFullyPaid;
            $result->creditRemaining = max(0.0, round($amount - ($result->monthsFullyPaid * $monthly), 2));
            $result->nextDueDate = $this->computeNextDueDate($memberId);
            $this->memberFinancialProjection->syncMember($memberId);
        });

        if ($bankTxId) {
            DB::table('bank_transactions')
                ->where('id', $bankTxId)
                ->update(['updated_at' => now()]);
        }

        $this->invalidateCaches($memberId);

        return $result;
    }

    private function findOrCreatePayment(string $memberId, float $amount, string $paymentDate, ?string $txRef): string
    {
        if (!$txRef) {
            return null;
        }

        $existing = DB::table('payments')
            ->where('member_id', $memberId)
            ->where('transaction_ref', $txRef)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        $paymentId = Uuid::v4();
        DB::table('payments')->insert([
            'id' => $paymentId,
            'member_id' => $memberId,
            'amount' => $amount,
            'transaction_ref' => $txRef,
            'source' => 'contribution_allocation',
            'matched' => 1,
            'received_at' => $paymentDate,
            'created_at' => now(),
        ]);

        return $paymentId;
    }

    private function memberStartTs(string $memberId, ?string $paymentDate = null): int
    {
        $member = DB::table('members')->where('id', $memberId)->first();
        $candidates = [];

        if ($member && $member->first_contribution_date) {
            $candidates[] = strtotime(date('Y-m-01', strtotime($member->first_contribution_date)));
        }

        $earliest = DB::table('contributions')
            ->where('member_id', $memberId)
            ->whereNotNull('paid_date')
            ->min('paid_date');
        if ($earliest) {
            $candidates[] = strtotime(date('Y-m-01', strtotime($earliest)));
        }

        if ($member && $member->created_at) {
            $candidates[] = strtotime(date('Y-m-01', strtotime($member->created_at)));
        }

        if ($paymentDate) {
            $candidates[] = strtotime(date('Y-m-01', strtotime($paymentDate)));
        }

        if (empty($candidates)) {
            return strtotime(date('Y-m-01'));
        }

        return min($candidates);
    }

    private function computeNextDueDate(string $memberId): ?string
    {
        $firstUnpaid = DB::table('contributions')
            ->where('member_id', $memberId)
            ->whereIn('status', ['pending', 'partial'])
            ->orderBy('year')
            ->orderBy('month')
            ->first();

        if (! $firstUnpaid) {
            $latest = DB::table('contributions')
                ->where('member_id', $memberId)
                ->where('status', 'paid')
                ->orderByDesc('year')
                ->orderByDesc('month')
                ->first();

            if (! $latest) return null;

            $next = strtotime(sprintf('%04d-%02d-01', $latest->year, $latest->month) . ' +1 month');
            return date('Y-m-t', $next);
        }

        return date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $firstUnpaid->year, $firstUnpaid->month)));
    }

    private function invalidateCaches(string $memberId): void
    {
        try {
            $keys = [
                'member:stats:' . $memberId,
                'member:summary:' . $memberId,
                'member:dashboard:' . $memberId,
                'treasurer:financial:summary',
                'admin:stats:summary',
                'members:overview',
                'contributions:overview',
            ];
            foreach ($keys as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable $e) {
            Log::warning('ContributionAllocationService: cache invalidation failed', [
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
