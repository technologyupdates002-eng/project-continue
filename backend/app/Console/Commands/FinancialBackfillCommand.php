<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ContributionObligationService;
use App\Services\MemberFinancialProjectionService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialBackfillCommand extends Command
{
    protected $signature = 'financial:backfill
        {--dry-run : Inspect deterministic mappings without modifying data}
        {--execute : Apply only exact mappings and record unsafe mappings as exceptions}
        {--limit=0 : Limit legacy payments inspected; zero means all}';

    protected $description = 'Backfill canonical financial state from legacy records without duplicating ambiguous money.';

    public function handle(): int
    {
        if (! $this->option('dry-run') && ! $this->option('execute')) {
            $this->error('Run with --dry-run first, then use --execute to apply exact mappings.');

            return self::INVALID;
        }

        if (! $this->requiredTablesExist()) {
            $this->error('Canonical financial tables are not available.');

            return self::FAILURE;
        }

        $report = $this->buildReport((bool) $this->option('execute'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function buildReport(bool $execute): array
    {
        $report = [
            'read_only' => true,
            'generated_at' => now()->toIso8601String(),
            'members_inspected' => DB::table('members')->count(),
            'payments_inspected' => 0,
            'payments_deterministic' => 0,
            'payments_conflicting' => 0,
            'payments_unlinked' => 0,
            'obligations_detected' => 0,
            'obligations_to_create' => 0,
            'allocations_detected' => 0,
            'allocations_to_create' => 0,
            'carry_forward_minor' => 0,
            'duplicates' => 0,
            'conflicts' => 0,
            'unresolved' => 0,
            'exceptions_to_create' => 0,
            'writes' => [],
        ];

        $this->prepareObligations($report, $execute);

        $payments = DB::table('payments')
            ->orderBy('id');
        if ((int) $this->option('limit') > 0) {
            $payments->limit((int) $this->option('limit'));
        }

        foreach ($payments->get() as $payment) {
            $report['payments_inspected']++;
            $linked = DB::table('contributions')
                ->where('payment_id', $payment->id)
                ->whereIn('status', ['paid', 'partial'])
                ->get(['id', 'member_id', 'amount', 'year', 'month', 'paid_date']);

            $linkedMinor = (int) round((float) $linked->sum('amount') * 100);
            $paymentMinor = (int) round((float) $payment->amount * 100);

            if ($linked->isEmpty()) {
                $report['payments_unlinked']++;
                $report['unresolved']++;
                $report['exceptions_to_create']++;
                if ($execute) {
                    $this->recordException($payment, 'unlinked_legacy_payment', [
                        'reason' => 'No paid or partial contribution is linked to this payment.',
                    ]);
                }
                continue;
            }

            if ($linkedMinor !== $paymentMinor || $linked->pluck('member_id')->unique()->count() !== 1) {
                $report['payments_conflicting']++;
                $report['conflicts']++;
                $report['exceptions_to_create']++;
                if ($execute) {
                    $this->recordException($payment, 'conflicting_legacy_payment', [
                        'payment_amount_minor' => $paymentMinor,
                        'linked_contribution_amount_minor' => $linkedMinor,
                        'member_count' => $linked->pluck('member_id')->unique()->count(),
                    ]);
                }
                continue;
            }

            $report['payments_deterministic']++;
            $report['obligations_detected'] += $linked->unique(fn ($row) => $row->year . '-' . $row->month)->count();
            $report['allocations_detected'] += $linked->count();

            foreach ($linked->groupBy(fn ($row) => $row->year . '-' . $row->month) as $rows) {
                $first = $rows->first();
                $obligationExists = DB::table('monthly_obligations')
                    ->where('member_id', $first->member_id)
                    ->where('period_year', $first->year)
                    ->where('period_month', $first->month)
                    ->exists();
                if (! $obligationExists) {
                    $report['obligations_to_create']++;
                }

                $allocationExists = DB::table('contribution_allocations')
                    ->where('payment_id', $payment->id)
                    ->whereIn('obligation_id', DB::table('monthly_obligations')
                        ->where('member_id', $first->member_id)
                        ->where('period_year', $first->year)
                        ->where('period_month', $first->month)
                        ->pluck('id'))
                    ->exists();
                if (! $allocationExists) {
                    $report['allocations_to_create']++;
                } else {
                    $report['duplicates']++;
                }
            }

            if ($execute) {
                $this->backfillPayment($payment, $linked);
                $report['writes'][] = (string) $payment->id;
            }
        }

        return $report;
    }

    private function prepareObligations(array &$report, bool $execute): void
    {
        $service = app(ContributionObligationService::class);
        $today = new DateTimeImmutable('today');

        foreach (DB::table('members')->select('id', 'first_contribution_date', 'created_at')->get() as $member) {
            $start = $service->startDate((string) $member->id);
            $cursor = $start->modify('first day of this month');
            while ($cursor <= $today) {
                $year = (int) $cursor->format('Y');
                $month = (int) $cursor->format('n');
                $report['obligations_detected']++;
                $exists = DB::table('monthly_obligations')
                    ->where('member_id', $member->id)
                    ->where('period_year', $year)
                    ->where('period_month', $month)
                    ->exists();
                if (! $exists) {
                    $report['obligations_to_create']++;
                    if ($execute) {
                        $this->ensureObligation((string) $member->id, $year, $month);
                    }
                }
                $cursor = $cursor->modify('+1 month');
            }
        }
    }

    private function backfillPayment(object $payment, \Illuminate\Support\Collection $linked): void
    {
        DB::transaction(function () use ($payment, $linked): void {
            $occurredAt = $payment->received_at ?: $payment->created_at ?: now()->toDateTimeString();
            $providerReference = (string) ($payment->provider_reference
                ?: $payment->transaction_reference
                ?: $payment->transaction_ref
                ?: $payment->internal_reference
                ?: $payment->id);

            DB::table('payments')->where('id', $payment->id)->update([
                'amount_minor' => (int) round((float) $payment->amount * 100),
                'status' => 'confirmed',
                'provider_name' => $payment->provider_name ?: 'legacy_reconciliation',
                'provider_reference' => $providerReference,
                'internal_reference' => $payment->internal_reference ?: $providerReference,
                'occurred_at' => $occurredAt,
                'confirmed_at' => $payment->confirmed_at ?: $occurredAt,
                'updated_at' => now(),
            ]);

            foreach ($linked->groupBy(fn ($row) => $row->year . '-' . $row->month) as $rows) {
                $first = $rows->first();
                $obligationId = $this->ensureObligation(
                    (string) $first->member_id,
                    (int) $first->year,
                    (int) $first->month,
                );
                $amountMinor = (int) round((float) $rows->sum('amount') * 100);
                $existing = DB::table('contribution_allocations')
                    ->where('payment_id', $payment->id)
                    ->where('obligation_id', $obligationId)
                    ->first();

                if (! $existing) {
                    DB::table('contribution_allocations')->insert([
                        'id' => \App\Support\Uuid::v4(),
                        'payment_id' => $payment->id,
                        'member_id' => $first->member_id,
                        'obligation_id' => $obligationId,
                        'amount_minor' => $amountMinor,
                        'allocation_type' => 'legacy_backfill',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $this->refreshObligation($obligationId);
            }

            app(MemberFinancialProjectionService::class)->syncMember((string) $linked->first()->member_id);
        });
    }

    private function ensureObligation(string $memberId, int $year, int $month): string
    {
        $existing = DB::table('monthly_obligations')
            ->where('member_id', $memberId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
        if ($existing) {
            return (string) $existing->id;
        }

        $required = (int) round(app(ContributionObligationService::class)->monthly() * 100);
        $id = \App\Support\Uuid::v4();
        DB::table('monthly_obligations')->insert([
            'id' => $id,
            'member_id' => $memberId,
            'period_year' => $year,
            'period_month' => $month,
            'required_amount_minor' => $required,
            'paid_amount_minor' => 0,
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function refreshObligation(string $obligationId): void
    {
        $paid = (int) DB::table('contribution_allocations')
            ->where('obligation_id', $obligationId)
            ->sum('amount_minor');
        $obligation = DB::table('monthly_obligations')->where('id', $obligationId)->first();
        $required = (int) $obligation->required_amount_minor;
        $status = $paid >= $required ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        DB::table('monthly_obligations')->where('id', $obligationId)->update([
            'paid_amount_minor' => $paid,
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function recordException(object $payment, string $type, array $details): void
    {
        $exists = DB::table('financial_exceptions')
            ->where('type', $type)
            ->where('source_table', 'payments')
            ->where('source_id', $payment->id)
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('financial_exceptions')->insert([
            'id' => \App\Support\Uuid::v4(),
            'type' => $type,
            'source' => 'financial_backfill',
            'source_table' => 'payments',
            'source_id' => $payment->id,
            'external_reference' => $payment->provider_reference ?: $payment->transaction_ref,
            'member_id' => $payment->member_id,
            'payload_json' => json_encode($details),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function requiredTablesExist(): bool
    {
        foreach ([
            'members',
            'payments',
            'contributions',
            'monthly_obligations',
            'contribution_allocations',
            'carry_forward_balances',
            'carry_forward_movements',
            'financial_exceptions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
