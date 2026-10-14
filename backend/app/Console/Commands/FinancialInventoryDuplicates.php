<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialInventoryDuplicates extends Command
{
    protected $signature = 'financial:inventory-duplicates';

    protected $description = 'Report possible financial duplicates without changing data';

    public function handle(): int
    {
        $this->info('Read-only financial duplicate inventory');

        try {
            $this->report('payments.provider_reference', 'payments', ['provider_name', 'provider_reference']);
            $this->report('payments.transaction_reference', 'payments', ['transaction_reference']);
            $this->report('payments.fingerprint', 'payments', ['fingerprint']);
            $this->report('bank_transactions.reference', 'bank_transactions', ['bank_transaction_reference']);
            $this->report('bank_transactions.fingerprint', 'bank_transactions', ['bank_fingerprint']);
            $this->report('contributions.member_period', 'contributions', ['member_id', 'year', 'month']);

            if (Schema::hasColumn('contributions', 'stk_checkout_request_id')) {
                $this->report('contributions.stk_checkout_request_id', 'contributions', ['stk_checkout_request_id']);
            }
        } catch (QueryException $exception) {
            $this->error('Database unavailable; no inventory was produced: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $this->line('No rows were inserted, updated, deleted, or marked as successful.');

        return self::SUCCESS;
    }

    private function report(string $label, string $table, array $columns): void
    {
        if (! Schema::hasTable($table) || collect($columns)->contains(fn (string $column) => ! Schema::hasColumn($table, $column))) {
            $this->line("{$label}: unavailable");
            return;
        }

        $select = array_merge($columns, [DB::raw('COUNT(*) as duplicate_count')]);
        $rows = DB::table($table)
            ->select($select)
            ->where(function ($query) use ($columns) {
                foreach ($columns as $column) {
                    $query->whereNotNull($column)->where($column, '<>', '');
                }
            })
            ->groupBy($columns)
            ->having('duplicate_count', '>', 1)
            ->orderByDesc('duplicate_count')
            ->limit(100)
            ->get();

        $this->line("{$label}: {$rows->count()} duplicate groups");
        foreach ($rows as $row) {
            $values = collect($columns)->map(fn (string $column) => "{$column}=" . (string) $row->{$column})->implode(', ');
            $this->line("  {$values}, count={$row->duplicate_count}");
        }
    }
}