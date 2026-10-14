<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Maintains legacy member financial columns from canonical financial state.
 *
 * This is a compatibility projection only. Payments and allocations remain
 * authoritative; this service never creates or changes ledger records.
 */
class MemberFinancialProjectionService
{
    public function syncMember(string $memberId): void
    {
        if (! Schema::hasTable('members') || ! DB::table('members')->where('id', $memberId)->exists()) {
            return;
        }

        $canonicalPaidMonthKeys = [];
        $canonicalPaidMinor = 0;

        if (Schema::hasTable('monthly_obligations')) {
            $canonicalRows = DB::table('monthly_obligations')
                ->where('member_id', $memberId)
                ->where('status', 'paid')
                ->select('period_year', 'period_month', 'required_amount_minor')
                ->get();

            $canonicalPaidMinor = (int) $canonicalRows->sum('required_amount_minor');
            foreach ($canonicalRows as $row) {
                $canonicalPaidMonthKeys["{$row->period_year}-{$row->period_month}"] = true;
            }
        }

        $legacyCompatibilityMinor = 0;
        if (Schema::hasTable('contributions')) {
            $legacyRows = DB::table('contributions as c')
                ->where('c.member_id', $memberId)
                ->where('c.status', 'paid')
                ->select('c.year', 'c.month', 'c.amount')
                ->get();

            foreach ($legacyRows as $row) {
                $monthKey = "{$row->year}-{$row->month}";
                if (isset($canonicalPaidMonthKeys[$monthKey])) {
                    continue;
                }

                $legacyCompatibilityMinor += (int) round((float) $row->amount * 100);
            }
        }

        $totalMinor = $canonicalPaidMinor + $legacyCompatibilityMinor;

        if (Schema::hasTable('contribution_adjustments')) {
            $totalMinor += (int) DB::table('contribution_adjustments')
                ->where('member_id', $memberId)
                ->sum('amount_minor');
        }

        DB::table('members')
            ->where('id', $memberId)
            ->update([
                'total_contributions' => $totalMinor / 100,
                'updated_at' => now(),
            ]);

        try {
            Cache::store('redis')->forget("member:stats:{$memberId}");
            Cache::store('redis')->forget('member:stats:summary');
        } catch (\RedisException|\RuntimeException) {
            // Cache invalidation must not prevent the compatibility projection.
        }
    }

    /**
     * @param list<string> $memberIds
     */
    public function syncMembers(array $memberIds): void
    {
        foreach (array_values(array_unique(array_filter($memberIds))) as $memberId) {
            $this->syncMember((string) $memberId);
        }
    }
}
