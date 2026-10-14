<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialBankReconciliationAuditCommand extends Command
{
    protected $signature = 'financial:bank-reconciliation-audit
        {--dry-run : Required safety flag; inspect only and never modify financial data}
        {--json : Output the complete report as JSON}
        {--member= : Restrict the audit to one member ID}
        {--limit=500 : Maximum member rows to report}';

    protected $description = 'Cross-check bank evidence, legacy contributions, canonical payments, and allocations per member.';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('This command is read-only and must be invoked with --dry-run.');

            return self::INVALID;
        }

        try {
            $report = $this->buildReport();
        } catch (\Throwable $exception) {
            $this->error('Bank reconciliation audit failed: '.$exception->getMessage());

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
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'scope' => $this->option('member') ?: 'all_members',
            'members' => [],
            'unmatched_bank_credits' => [],
            'ambiguous_bank_credits' => [],
            'duplicate_bank_references' => [],
        ];

        if (! $this->hasTable('members')) {
            return $report;
        }

        $members = DB::table('members')
            ->when($this->option('member'), fn ($query, $memberId) => $query->where('id', $memberId))
            ->orderBy('name')
            ->limit($this->limit())
            ->get(['id', 'name', 'phone']);

        $legacy = $this->legacyTotals($members->pluck('id')->all());
        $bank = $this->bankTotals($members->pluck('id')->all());
        $payments = $this->paymentTotals($members->pluck('id')->all());
        $allocations = $this->allocationTotals($members->pluck('id')->all());

        foreach ($members as $member) {
            $memberId = (string) $member->id;
            $legacyAmount = $legacy[$memberId] ?? 0;
            $bankAmount = $bank[$memberId] ?? 0;
            $paymentAmount = $payments[$memberId] ?? 0;
            $allocatedAmount = $allocations[$memberId] ?? 0;

            $report['members'][] = [
                'member_id' => $memberId,
                'name' => $member->name,
                'phone' => $member->phone,
                'legacy_contributions_minor' => $legacyAmount,
                'matched_bank_credits_minor' => $bankAmount,
                'confirmed_canonical_payments_minor' => $paymentAmount,
                'canonical_allocations_minor' => $allocatedAmount,
                'unapplied_confirmed_cash_minor' => max(0, $paymentAmount - $allocatedAmount),
                'legacy_minus_bank_minor' => $legacyAmount - $bankAmount,
                'bank_minus_canonical_minor' => $bankAmount - $paymentAmount,
                'canonical_minus_allocated_minor' => $paymentAmount - $allocatedAmount,
                'classification' => $this->classify($legacyAmount, $bankAmount, $paymentAmount, $allocatedAmount),
            ];
        }

        if ($this->hasTable('bank_unmatched_transactions')) {
            $unmatchedQuery = DB::table('bank_unmatched_transactions')
                ->where('credit_amount', '>', 0)
                ->where('status', '!=', 'ignored');
            if ($this->option('member')) {
                $memberPhone = $members->first()?->phone;
                $unmatchedQuery->where('phone', $memberPhone);
            }
            $report['unmatched_bank_credits'] = $unmatchedQuery
                ->orderBy('transaction_date')
                ->limit($this->limit())
                ->get(['id', 'bank_reference', 'transaction_date', 'credit_amount', 'payer_name', 'phone', 'reason', 'status'])
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        if ($this->hasTable('bank_transactions')) {
            $report['ambiguous_bank_credits'] = DB::table('bank_transactions')
                ->where('credit_amount', '>', 0)
                ->where('reconciliation_status', 'unmatched')
                ->whereIn('match_method', ['ambiguous_member', 'ambiguous'])
                ->limit($this->limit())
                ->get(['id', 'transaction_reference', 'transaction_date', 'credit_amount', 'name', 'phone', 'match_method'])
                ->map(fn (object $row): array => (array) $row)
                ->all();

            $report['duplicate_bank_references'] = DB::table('bank_transactions')
                ->whereNotNull('transaction_reference')
                ->select('transaction_reference', DB::raw('COUNT(*) as row_count'), DB::raw('SUM(credit_amount) as credit_amount'))
                ->groupBy('transaction_reference')
                ->having('row_count', '>', 1)
                ->limit($this->limit())
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $report;
    }

    private function legacyTotals(array $memberIds): array
    {
        if (! $this->hasTable('contributions') || ! $memberIds) {
            return [];
        }

        return DB::table('contributions')
            ->whereIn('member_id', $memberIds)
            ->whereIn('status', ['paid', 'partial'])
            ->select('member_id', DB::raw('SUM(amount) * 100 as amount_minor'))
            ->groupBy('member_id')
            ->pluck('amount_minor', 'member_id')
            ->map(fn ($amount): int => (int) round((float) $amount))
            ->all();
    }

    private function bankTotals(array $memberIds): array
    {
        if (! $this->hasTable('bank_transactions') || ! $memberIds) {
            return [];
        }

        $query = DB::table('bank_transactions')
            ->whereIn('member_id', $memberIds)
            ->where('transaction_type', 'CREDIT')
            ->where('transaction_state', '!=', 'reversed');
        if ($this->hasColumn('bank_transactions', 'category')) {
            $query->where(function ($categoryQuery): void {
                $categoryQuery->whereNull('category')
                    ->orWhere('category', 'monthly_contribution');
            });
        }

        return $query
            ->select('member_id', DB::raw('SUM(credit_amount) * 100 as amount_minor'))
            ->groupBy('member_id')
            ->pluck('amount_minor', 'member_id')
            ->map(fn ($amount): int => (int) round((float) $amount))
            ->all();
    }

    private function paymentTotals(array $memberIds): array
    {
        if (! $this->hasTable('payments') || ! $memberIds) {
            return [];
        }

        return DB::table('payments')
            ->whereIn('member_id', $memberIds)
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query->whereNull('payment_type')
                    ->orWhere('payment_type', '!=', 'donation');
            })
            ->select('member_id', DB::raw('SUM(amount_minor) as amount_minor'))
            ->groupBy('member_id')
            ->pluck('amount_minor', 'member_id')
            ->map(fn ($amount): int => (int) $amount)
            ->all();
    }

    private function allocationTotals(array $memberIds): array
    {
        if (! $this->hasTable('contribution_allocations') || ! $memberIds) {
            return [];
        }

        return DB::table('contribution_allocations')
            ->whereIn('member_id', $memberIds)
            ->select('member_id', DB::raw('SUM(amount_minor) as amount_minor'))
            ->groupBy('member_id')
            ->pluck('amount_minor', 'member_id')
            ->map(fn ($amount): int => (int) $amount)
            ->all();
    }

    private function classify(int $legacy, int $bank, int $payments, int $allocated): string
    {
        if ($bank > 0 && $bank === $payments && $payments >= $allocated) {
            return $legacy === $bank ? 'reconciled' : 'legacy_projection_difference';
        }
        if ($payments > $allocated) {
            return 'confirmed_cash_not_fully_allocated';
        }
        if ($bank > $payments) {
            return 'bank_cash_missing_canonical_payment';
        }
        if ($legacy > $payments) {
            return 'legacy_cash_missing_canonical_payment';
        }

        return 'requires_review';
    }

    private function displaySummary(array $report): void
    {
        $this->info('Read-only bank reconciliation audit');
        $this->line('Members inspected: '.count($report['members']));
        $this->line('Unmatched bank credits: '.count($report['unmatched_bank_credits']));
        $this->line('Ambiguous bank credits: '.count($report['ambiguous_bank_credits']));
        $this->line('Duplicate bank references: '.count($report['duplicate_bank_references']));
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
