<?php

namespace App\Console\Commands;

use App\Services\FinancialReadModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PenaltiesAssess extends Command
{
    protected $signature = 'penalties:assess';
    protected $description = 'Assess and create overdue penalties according to welfare_settings';

    public function handle(FinancialReadModel $financialReadModel, int $code = 0): int
    {
        $created = 0;
        $skipped = 0;

        $overdue = $financialReadModel->overdueObligationsForAccrual();
        $overdueKeys = $overdue
            ->mapWithKeys(fn (object|array $obligation): array => [
                $this->key(
                    is_array($obligation) ? $obligation['member_id'] : $obligation->member_id,
                    is_array($obligation) ? $obligation['period_year'] : $obligation->period_year,
                    is_array($obligation) ? $obligation['period_month'] : $obligation->period_month,
                ) => true,
            ]);

        $stale = DB::table('penalties')
            ->where('is_paid', 0)
            ->where('reason', 'like', 'Late%')
            ->whereNotNull('year')
            ->whereNotNull('month')
            ->get(['id', 'member_id', 'year', 'month', 'reason'])
            ->filter(fn (object $penalty): bool => ! $overdueKeys->has(
                $this->key($penalty->member_id, $penalty->year, $penalty->month)
            ));

        foreach ($stale as $penalty) {
            DB::table('penalties')->where('id', $penalty->id)->delete();
        }

        foreach ($overdue as $obligation) {
            $memberId = is_array($obligation) ? $obligation['member_id'] : $obligation->member_id;
            $year = is_array($obligation) ? $obligation['period_year'] : $obligation->period_year;
            $month = is_array($obligation) ? $obligation['period_month'] : $obligation->period_month;
            $exists = DB::table('penalties')
                ->where('member_id', $memberId)
                ->where('year', $year)
                ->where('month', $month)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::table('penalties')->insert([
                'id' => \App\Support\Uuid::v4(),
                'member_id' => $memberId,
                'contribution_id' => null,
                'year' => $year,
                'month' => $month,
                'amount' => $financialReadModel->penaltyAmount(),
                'reason' => 'Late',
                'is_paid' => 0,
                'created_at' => now(),
            ]);

            $created++;
        }

        $this->info("Assessment complete. Created: {$created}, Skipped: {$skipped}");

        return $code;
    }

    private function key(string $memberId, int|string $year, int|string $month): string
    {
        return "{$memberId}:{$year}-{$month}";
    }
}
