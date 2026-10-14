<?php

namespace App\Services;

use App\Models\Contribution;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MemberStatsService
{
    public function __construct(
        private ContributionObligationService $obligation,
        private FinancialReadModel $financialReadModel
    ) {}

    public function getStats(?string $memberId = null): array
    {
        $cacheKey = $memberId ? "member:stats:{$memberId}" : 'member:stats:all';
        $resolver = function () use ($memberId) {
            $query = DB::table('members')
                ->select([
                    'id',
                    'user_id',
                    'name',
                    'phone',
                    'member_id',
                    'total_contributions',
                    'total_penalties',
                    'is_active',
                    'status',
                    'first_contribution_date',
                    'source',
                    'created_at',
                ]);

            if ($memberId) {
                $query->where('id', $memberId);
                $member = $query->first();
                if (!$member) {
                    return [];
                }
                $summary = $this->obligation->memberSummary($member->id);
                $canonical = $this->financialReadModel->memberOverview($member->id);
                return array_merge($summary, [
                    'total_contributions' => ($canonical['cash_received_minor'] ?? 0) / 100,
                    'contribution_outstanding' => ($canonical['outstanding_minor'] ?? 0) / 100,
                    'penalty_outstanding' => ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'total_penalties' => ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'total_outstanding' => ($canonical['outstanding_minor'] ?? 0) / 100 + ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'status' => $member->status,
                    'paid_months' => $canonical['paid_months'] ?? ($summary['paid_months'] ?? 0),
                    'pending_months' => ($canonical['partial_months'] ?? 0) + ($canonical['unpaid_months'] ?? 0),
                    'partial_months' => $canonical['partial_months'] ?? 0,
                    'advance_months' => $canonical['advance_months'] ?? 0,
                    'cash_paid_months' => $canonical['cash_paid_months'] ?? 0,
                    'cash_remainder_minor' => $canonical['cash_remainder_minor'] ?? 0,
                    'cash_next_due_date' => $canonical['cash_next_due_date'] ?? null,
                    'next_due_date' => $canonical['cash_next_due_date'] ?? ($summary['next_due_date'] ?? null),
                    'cash_received_minor' => $canonical['cash_received_minor'] ?? 0,
                    'allocated_minor' => $canonical['allocated_minor'] ?? 0,
                    'unapplied_minor' => $canonical['unapplied_minor'] ?? 0,
                ]);
            }

            $members = $query->get()->map(function ($m) {
                return (array) $m;
            })->all();

            return array_map(function ($member) {
                $summary = $this->obligation->memberSummary($member['id']);
                $canonical = $this->financialReadModel->memberOverview($member['id']);
                return array_merge($summary, [
                    'total_contributions' => ($canonical['cash_received_minor'] ?? 0) / 100,
                    'penalty_outstanding' => ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'total_penalties' => ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'total_outstanding' => ($canonical['outstanding_minor'] ?? 0) / 100 + ($canonical['penalties_outstanding_minor'] ?? 0) / 100,
                    'status' => $member['status'],
                    'paid_months' => $canonical['paid_months'] ?? ($summary['paid_months'] ?? 0),
                    'partial_months' => $canonical['partial_months'] ?? 0,
                    'advance_months' => $canonical['advance_months'] ?? 0,
                    'cash_paid_months' => $canonical['cash_paid_months'] ?? 0,
                    'cash_remainder_minor' => $canonical['cash_remainder_minor'] ?? 0,
                    'cash_next_due_date' => $canonical['cash_next_due_date'] ?? null,
                    'next_due_date' => $canonical['cash_next_due_date'] ?? ($summary['next_due_date'] ?? null),
                    'cash_received_minor' => $canonical['cash_received_minor'] ?? 0,
                    'allocated_minor' => $canonical['allocated_minor'] ?? 0,
                    'unapplied_minor' => $canonical['unapplied_minor'] ?? 0,
                ]);
            }, $members);
        };

        try {
            return Cache::store('redis')->remember($cacheKey, 3600, $resolver);
        } catch (\RedisException|\RuntimeException) {
            return $resolver();
        }
    }

    public function getSummary(): array
    {
        $resolver = function () {
            $financial = $this->financialReadModel->summary();

            return $financial;
        };

        try {
            return Cache::store('redis')->remember('member:stats:summary', 3600, $resolver);
        } catch (\RedisException|\RuntimeException) {
            return $resolver();
        }
    }

    public function forgetCache(?string $memberId = null): void
    {
        try {
            $cache = Cache::store('redis');
            $cache->forget('member:stats:all');
            $cache->forget('member:stats:summary');
            if ($memberId !== null) {
                $cache->forget("member:stats:{$memberId}");
            }
        } catch (\RedisException|\RuntimeException $exception) {
            Log::warning('Unable to invalidate member stats cache', [
                'member_id' => $memberId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
