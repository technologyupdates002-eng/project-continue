<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialInventoryCommand extends Command
{
    protected $signature = 'financial:inventory
        {--json : Output the complete read-only report as JSON}
        {--crosswalk : Include historical source-record crosswalk candidates}
        {--limit=500 : Maximum rows per crosswalk category}';

    protected $description = 'Inventory financial duplicates, orphan records, and crosswalk candidates without changing data.';

    private const TABLES = [
        'members', 'payments', 'contributions', 'bank_transactions',
        'coop_transfer_requests', 'coop_transfer_responses', 'coop_transfers',
        'coop_transfer_events', 'wallet_transactions', 'penalties',
        'penalty_payments', 'penalty_payment_records', 'donation_payment_records',
        'operational_payment_records', 'campaign_contributions',
        'member_import_batches', 'member_import_rows', 'statement_processing_audit',
        'bank_sync_audit', 'unmatched_payments',
    ];

    public function handle(): int
    {
        try {
            $report = $this->buildReport();
        } catch (\Throwable $exception) {
            $this->error('Financial inventory could not read the configured local database: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->displaySummary($report);

        return self::SUCCESS;
    }

    private function buildReport(): array
    {
        $report = [
            'environment' => (string) config('app.env'),
            'database' => [
                'connection' => (string) config('database.default'),
                'database' => (string) config('database.connections.' . config('database.default') . '.database'),
                'host' => (string) config('database.connections.' . config('database.default') . '.host'),
            ],
            'generated_at' => now()->toIso8601String(),
            'tables' => [],
            'totals' => [],
            'duplicates' => [],
            'orphans' => [],
            'status_conflicts' => [],
            'missing_references' => [],
            'member_total_discrepancies' => [],
            'crosswalk' => [],
        ];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $report['tables'][$table] = ['exists' => false];
                continue;
            }

            $report['tables'][$table] = [
                'exists' => true,
                'columns' => Schema::getColumnListing($table),
                'rows' => DB::table($table)->count(),
            ];
        }

        $this->addPaymentChecks($report);
        $this->addBankChecks($report);
        $this->addContributionChecks($report);
        $this->addWalletChecks($report);
        $this->addMemberTotalChecks($report);

        if ($this->option('crosswalk')) {
            $this->addCrosswalk($report);
        }

        foreach ($report['tables'] as $table => $metadata) {
            $report['totals'][$table] = $metadata['exists'] ? $metadata['rows'] : null;
        }

        return $report;
    }

    private function addPaymentChecks(array &$report): void
    {
        if (! $this->hasTable('payments')) {
            return;
        }

        foreach (['transaction_ref', 'internal_reference', 'provider_reference', 'fingerprint'] as $column) {
            if (! $this->hasColumn('payments', $column)) {
                continue;
            }

            $report['duplicates']['payments.' . $column] = $this->duplicateGroups(
                DB::table('payments')->whereNotNull($column),
                $column,
            );
        }

        if ($this->hasTable('contributions') && $this->hasColumn('contributions', 'payment_id')) {
            $report['missing_references']['contributions_without_payment'] = DB::table('contributions as c')
                ->leftJoin('payments as p', 'p.id', '=', 'c.payment_id')
                ->whereNotNull('c.payment_id')
                ->whereNull('p.id')
                ->limit($this->limit())
                ->pluck('c.id')
                ->all();
        }
    }

    private function addBankChecks(array &$report): void
    {
        if (! $this->hasTable('bank_transactions')) {
            return;
        }

        foreach (['transaction_reference', 'bank_transaction_reference', 'bank_fingerprint'] as $column) {
            if ($this->hasColumn('bank_transactions', $column)) {
                $report['duplicates']['bank_transactions.' . $column] = $this->duplicateGroups(
                    DB::table('bank_transactions')->whereNotNull($column),
                    $column,
                );
            }
        }

        if ($this->hasColumn('bank_transactions', 'contribution_id') && $this->hasTable('contributions')) {
            $report['missing_references']['bank_transactions_without_contribution'] = DB::table('bank_transactions as b')
                ->leftJoin('contributions as c', 'c.id', '=', 'b.contribution_id')
                ->whereNotNull('b.contribution_id')
                ->whereNull('c.id')
                ->limit($this->limit())
                ->pluck('b.id')
                ->all();
        }
    }

    private function addContributionChecks(array &$report): void
    {
        if (! $this->hasTable('contributions')) {
            return;
        }

        $report['duplicates']['contributions.member_period'] = DB::table('contributions')
            ->select('member_id', 'month', 'year', DB::raw('COUNT(*) as row_count'), DB::raw('SUM(amount) as amount'))
            ->groupBy('member_id', 'month', 'year')
            ->having('row_count', '>', 1)
            ->limit($this->limit())
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        if ($this->hasColumn('contributions', 'payment_id') && $this->hasTable('payments')) {
            $report['missing_references']['paid_contributions_without_payment'] = DB::table('contributions as c')
                ->leftJoin('payments as p', 'p.id', '=', 'c.payment_id')
                ->whereIn('c.status', ['paid', 'partial'])
                ->whereNull('p.id')
                ->limit($this->limit())
                ->pluck('c.id')
                ->all();
        }

        if ($this->hasColumn('contributions', 'status')) {
            $report['status_conflicts']['contributions'] = DB::table('contributions')
                ->select('status', DB::raw('COUNT(*) as row_count'), DB::raw('SUM(amount) as amount'))
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }
    }

    private function addWalletChecks(array &$report): void
    {
        if (! $this->hasTable('wallet_transactions')) {
            return;
        }

        $query = DB::table('wallet_transactions');
        if ($this->hasColumn('wallet_transactions', 'reference_id')) {
            $report['duplicates']['wallet_transactions.reference_id'] = $this->duplicateGroups(
                $query->whereNotNull('reference_id'),
                'reference_id',
                ['wallet_type', 'direction'],
            );
        }

        if ($this->hasColumn('wallet_transactions', 'reference_id') && $this->hasTable('payments')) {
            $report['missing_references']['wallet_transactions_without_payment'] = DB::table('wallet_transactions as w')
                ->leftJoin('payments as p', 'p.id', '=', 'w.reference_id')
                ->where('w.source', 'like', '%payment%')
                ->whereNotNull('w.reference_id')
                ->whereNull('p.id')
                ->limit($this->limit())
                ->pluck('w.id')
                ->all();
        }
    }

    private function addMemberTotalChecks(array &$report): void
    {
        if (! $this->hasTable('members') || ! $this->hasTable('contributions') || ! $this->hasColumn('members', 'total_contributions')) {
            return;
        }

        $report['member_total_discrepancies'] = DB::table('members as m')
            ->leftJoin('contributions as c', function ($join) {
                $join->on('c.member_id', '=', 'm.id')
                    ->whereIn('c.status', ['paid', 'partial']);
            })
            ->select('m.id', 'm.name', 'm.total_contributions', DB::raw('COALESCE(SUM(c.amount), 0) as calculated_total'))
            ->groupBy('m.id', 'm.name', 'm.total_contributions')
            ->havingRaw('ABS(COALESCE(m.total_contributions, 0) - COALESCE(SUM(c.amount), 0)) > 0.01')
            ->limit($this->limit())
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function addCrosswalk(array &$report): void
    {
        if ($this->hasTable('payments')) {
            $report['crosswalk']['payments'] = DB::table('payments')
                ->select('id as source_record_id', 'member_id', 'amount', 'transaction_ref', 'source', 'received_at', 'matched')
                ->limit($this->limit())
                ->get()
                ->map(fn ($row) => array_merge(['source_type' => 'payments', 'match_confidence' => 'exact'], (array) $row))
                ->all();
        }

        if ($this->hasTable('bank_transactions')) {
            $report['crosswalk']['bank_transactions'] = DB::table('bank_transactions')
                ->select('id as source_record_id', 'member_id', 'amount', 'transaction_reference', 'transaction_date', 'contribution_id', 'reconciliation_status')
                ->limit($this->limit())
                ->get()
                ->map(function ($row) {
                    $data = (array) $row;
                    $data['source_type'] = 'bank_transactions';
                    $data['candidate_payment_id'] = null;
                    $data['match_confidence'] = $row->contribution_id ? 'strong' : 'unmatched';
                    $data['conflict_reason'] = $row->contribution_id ? null : 'No contribution linkage';

                    return $data;
                })
                ->all();
        }

        if ($this->hasTable('contributions')) {
            $report['crosswalk']['contributions'] = DB::table('contributions')
                ->select('id as source_record_id', 'payment_id as candidate_payment_id', 'member_id', 'amount', 'payment_ref', 'paid_date', 'status')
                ->limit($this->limit())
                ->get()
                ->map(fn ($row) => array_merge(['source_type' => 'contributions', 'match_confidence' => $row->candidate_payment_id ? 'strong' : 'unmatched'], (array) $row))
                ->all();
        }
    }

    private function duplicateGroups(Builder $query, string $column, array $groupColumns = []): array
    {
        $groups = array_merge([$column], $groupColumns);

        return $query
            ->select($groups, DB::raw('COUNT(*) as row_count'), DB::raw('SUM(amount) as amount'))
            ->groupBy($groups)
            ->havingRaw('COUNT(*) > 1')
            ->limit($this->limit())
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function displaySummary(array $report): void
    {
        $this->info(sprintf('Financial inventory: environment=%s database=%s host=%s', $report['environment'], $report['database']['database'], $report['database']['host']));

        foreach ($report['totals'] as $table => $count) {
            $this->line(sprintf('%-35s %s', $table, $count === null ? 'missing' : $count));
        }

        $this->line('Duplicate categories: ' . count($report['duplicates']));
        $this->line('Orphan/missing-reference categories: ' . count($report['missing_references']));
        $this->line('Member total discrepancies: ' . count($report['member_total_discrepancies']));
        $this->line('Read-only: no financial records were changed.');
    }

    private function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    private function limit(): int
    {
        return max(1, min((int) $this->option('limit'), 5000));
    }
}