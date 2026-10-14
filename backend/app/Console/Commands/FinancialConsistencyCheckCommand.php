<?php

namespace App\Console\Commands;

use App\Services\FinancialReadModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialConsistencyCheckCommand extends Command
{
    protected $signature = 'financial:consistency-check
        {--json : Output the complete read-only report as JSON}
        {--limit=500 : Maximum discrepancies to report}';

    protected $description = 'Compare legacy financial records with canonical read-model totals without repairing discrepancies.';

    public function __construct(private readonly FinancialReadModel $financialReadModel)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $report = $this->buildReport();
        } catch (\Throwable $exception) {
            $this->error('Financial consistency check failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Read-only financial consistency check');
        $this->line('Members inspected: '.$report['members_inspected']);
        $this->line('Expected differences: '.count($report['expected_differences']));
        $this->line('Real discrepancies: '.count($report['real_discrepancies']));
        $this->line('Unresolved: '.count($report['unresolved']));
        $this->line('No records were inserted, updated, deleted, or repaired.');

        return self::SUCCESS;
    }

    private function buildReport(): array
    {
        $report = [
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
            'members_inspected' => 0,
            'expected_differences' => [],
            'real_discrepancies' => [],
            'unresolved' => [],
        ];

        foreach (DB::table('members')->select('id', 'name')->get() as $member) {
            $report['members_inspected']++;
            $canonical = $this->financialReadModel->memberOverview((string) $member->id);
            $legacy = $this->legacyTotals((string) $member->id);

            $this->compare($report, $member, 'contribution_cash', $legacy['contribution_cash_minor'], $canonical['cash_received_minor'] ?? 0);
            $this->compare($report, $member, 'paid_obligations', $legacy['paid_obligations'], $canonical['paid_months'] ?? 0);
            $this->compare($report, $member, 'penalties_outstanding', $legacy['penalties_outstanding_minor'], $canonical['penalties_outstanding_minor'] ?? 0);

            if (($canonical['carry_forward_minor'] ?? 0) > 0 && $legacy['contribution_cash_minor'] === 0) {
                $this->add($report['unresolved'], $member, 'carry_forward', null, $canonical['carry_forward_minor'], 'Canonical carry-forward has no legacy equivalent.');
            }
        }

        return $report;
    }

    private function legacyTotals(string $memberId): array
    {
        if (! Schema::hasTable('contributions')) {
            return [
                'contribution_cash_minor' => 0,
                'paid_obligations' => 0,
                'penalties_outstanding_minor' => 0,
            ];
        }

        $rows = DB::table('contributions')
            ->where('member_id', $memberId)
            ->whereIn('status', ['paid', 'partial'])
            ->where('amount', '>', 0)
            ->get(['amount', 'status', 'year', 'month']);

        return [
            'contribution_cash_minor' => (int) round((float) $rows->sum('amount') * 100),
            'paid_obligations' => $rows->filter(fn (object $row): bool => (string) $row->status === 'paid')
                ->map(fn (object $row): string => "{$row->year}-{$row->month}")
                ->unique()
                ->count(),
            'penalties_outstanding_minor' => $this->legacyPenaltyTotal($memberId),
        ];
    }

    private function legacyPenaltyTotal(string $memberId): int
    {
        if (! Schema::hasTable('penalties')) {
            return 0;
        }

        return (int) round((float) DB::table('penalties')
            ->where('member_id', $memberId)
            ->where('is_paid', 0)
            ->sum('amount') * 100);
    }

    private function compare(array &$report, object $member, string $category, int $legacy, int $canonical): void
    {
        if ($legacy === $canonical) {
            return;
        }

        $difference = $canonical - $legacy;
        $entry = [
            'member_id' => $member->id,
            'member_name' => $member->name,
            'category' => $category,
            'legacy_value' => $legacy,
            'canonical_value' => $canonical,
            'difference' => $difference,
        ];

        if ($legacy > 0 && $canonical === 0 && ! Schema::hasTable('monthly_obligations')) {
            $entry['reason'] = 'Historical legacy data exists before canonical tables.';
            $report['expected_differences'][] = $entry;

            return;
        }

        $this->add($report['real_discrepancies'], $member, $category, $legacy, $canonical, null);
    }

    private function add(array &$target, object $member, string $category, mixed $legacy, mixed $canonical, ?string $reason): void
    {
        $entry = [
            'member_id' => $member->id,
            'member_name' => $member->name,
            'category' => $category,
            'legacy_value' => $legacy,
            'canonical_value' => $canonical,
        ];
        if ($reason !== null) {
            $entry['reason'] = $reason;
        }
        $target[] = $entry;

        if (count($target) > $this->limit()) {
            array_pop($target);
        }
    }

    private function limit(): int
    {
        return max(1, min((int) $this->option('limit'), 5000));
    }
}
