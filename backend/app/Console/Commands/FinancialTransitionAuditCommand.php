<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialTransitionAuditCommand extends Command
{
    protected $signature = 'financial:transition-audit
        {--dry-run : Required safety flag; inspect only and never modify financial data}
        {--json : Output the complete read-only audit as JSON}
        {--limit=500 : Maximum candidate rows to report per category}';

    protected $description = 'Audit legacy financial records for safe canonical transition candidates without changing data.';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('This command is read-only and must be invoked with --dry-run.');

            return self::INVALID;
        }

        try {
            $report = $this->buildReport();
        } catch (\Throwable $exception) {
            $this->error('Financial transition audit failed: '.$exception->getMessage());

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
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'destructive_operations' => [],
            'tables' => [],
            'candidates' => [],
            'unresolved' => [],
            'conflicts' => [],
        ];

        foreach ([
            'members',
            'contributions',
            'payments',
            'monthly_obligations',
            'contribution_allocations',
            'carry_forward_balances',
            'wallet_transactions',
            'penalties',
            'bank_transactions',
            'coop_transfer_requests',
        ] as $table) {
            $report['tables'][$table] = $this->tableSummary($table);
        }

        $this->auditContributions($report);
        $this->auditPayments($report);
        $this->auditBankTransactions($report);

        return $report;
    }

    private function auditContributions(array &$report): void
    {
        if (! $this->hasTable('contributions')) {
            return;
        }

        $query = DB::table('contributions');
        $hasPaymentId = $this->hasColumn('contributions', 'payment_id');
        $hasMemberId = $this->hasColumn('contributions', 'member_id');
        $hasAmount = $this->hasColumn('contributions', 'amount');

        if ($hasPaymentId && $this->hasTable('payments')) {
            $report['candidates']['contributions_with_existing_payment'] = DB::table('contributions as c')
                ->join('payments as p', 'p.id', '=', 'c.payment_id')
                ->select('c.id as contribution_id', 'c.payment_id', 'c.member_id', 'c.amount', 'c.status')
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => array_merge(['classification' => 'existing_canonical_payment'], (array) $row))
                ->all();

            $report['unresolved']['paid_contributions_without_payment'] = DB::table('contributions as c')
                ->leftJoin('payments as p', 'p.id', '=', 'c.payment_id')
                ->whereIn('c.status', ['paid', 'partial'])
                ->whereNull('p.id')
                ->select('c.id', 'c.member_id', 'c.amount', 'c.status', 'c.paid_date')
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => array_merge(['classification' => 'requires_review_no_payment_created'], (array) $row))
                ->all();
        } elseif ($hasMemberId && $hasAmount) {
            $report['unresolved']['legacy_contributions'] = $query
                ->whereIn('status', ['paid', 'partial'])
                ->select('id', 'member_id', 'amount', 'status')
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => array_merge(['classification' => 'requires_review'], (array) $row))
                ->all();
        }
    }

    private function auditPayments(array &$report): void
    {
        if (! $this->hasTable('payments')) {
            return;
        }

        $report['candidates']['canonical_payments'] = [
            'inspected' => DB::table('payments')->count(),
            'confirmed' => DB::table('payments')->where('status', 'confirmed')->count(),
            'pending' => DB::table('payments')->where('status', 'pending')->count(),
            'failed_or_reversed' => DB::table('payments')->whereIn('status', ['failed', 'reversed'])->count(),
        ];

        if ($this->hasColumn('payments', 'amount_minor') && $this->hasColumn('payments', 'amount')) {
            $report['unresolved']['payments_missing_amount_minor'] = DB::table('payments')
                ->whereNull('amount_minor')
                ->select('id', 'member_id', 'amount', 'status', 'provider_reference')
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => array_merge(['classification' => 'deterministically_derivable_but_not_written'], (array) $row))
                ->all();
        }
    }

    private function auditBankTransactions(array &$report): void
    {
        if (! $this->hasTable('bank_transactions')) {
            return;
        }

        $report['candidates']['bank_transactions'] = [
            'inspected' => DB::table('bank_transactions')->count(),
            'matched' => $this->hasColumn('bank_transactions', 'member_id')
                ? DB::table('bank_transactions')->whereNotNull('member_id')->count()
                : null,
            'unmatched' => $this->hasColumn('bank_transactions', 'member_id')
                ? DB::table('bank_transactions')->whereNull('member_id')->count()
                : null,
        ];

        if ($this->hasColumn('bank_transactions', 'member_id')) {
            $report['unresolved']['unmatched_bank_transactions'] = DB::table('bank_transactions')
                ->whereNull('member_id')
                ->select('id', 'transaction_reference', 'amount', 'transaction_date')
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => array_merge(['classification' => 'financial_exception_required'], (array) $row))
                ->all();
        }
    }

    private function tableSummary(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return ['exists' => false];
        }

        return [
            'exists' => true,
            'rows' => DB::table($table)->count(),
            'columns' => Schema::getColumnListing($table),
        ];
    }

    private function displaySummary(array $report): void
    {
        $this->info('Read-only financial transition audit');
        $this->line('Environment: '.$report['environment']);
        $this->line('Tables inspected: '.count($report['tables']));
        $this->line('Candidate categories: '.count($report['candidates']));
        $this->line('Unresolved categories: '.count($report['unresolved']));
        $this->line('Conflicts: '.count($report['conflicts']));
        $this->line('Destructive operations: none');
        $this->line('No records were inserted, updated, deleted, or marked as successful.');
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
