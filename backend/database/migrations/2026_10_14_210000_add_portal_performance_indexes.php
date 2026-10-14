<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Safe to run on the live database: skips any table/column that is missing
// and any index that already exists.
return new class extends Migration
{
    private array $indexes = [
        'contributions' => [['member_id', 'created_at'], ['status'], ['period']],
        'payments' => [['member_id', 'created_at'], ['status'], ['checkout_request_id']],
        'unmatched_payments' => [['status', 'created_at']],
        'penalties' => [['member_id', 'status']],
        'penalty_payments' => [['member_id', 'status']],
        'wallet_transactions' => [['wallet_type', 'created_at'], ['reference']],
        'notifications' => [['user_id', 'read_at'], ['member_id', 'created_at']],
        'members' => [['user_id'], ['status'], ['phone']],
        'beneficiaries' => [['member_id']],
        'bank_transactions' => [['transaction_date'], ['reference']],
        'audit_logs' => [['created_at'], ['user_id']],
        'user_roles' => [['user_id', 'role']],
        'meeting_minutes' => [['status', 'created_at']],
        'expenses' => [['status', 'created_at']],
    ];

    private function name(string $table, array $cols): string
    {
        return substr('idx_'.$table.'_'.implode('_', $cols), 0, 60);
    }

    private function exists(string $table, string $name): bool
    {
        return collect(DB::select('SHOW INDEX FROM `'.$table.'`'))->contains(fn ($r) => $r->Key_name === $name);
    }

    public function up(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (! Schema::hasTable($table)) continue;
            foreach ($sets as $cols) {
                if (! Schema::hasColumns($table, $cols)) continue;
                $name = $this->name($table, $cols);
                if ($this->exists($table, $name)) continue;
                Schema::table($table, fn ($t) => $t->index($cols, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $sets) {
            if (! Schema::hasTable($table)) continue;
            foreach ($sets as $cols) {
                $name = $this->name($table, $cols);
                if ($this->exists($table, $name)) {
                    Schema::table($table, fn ($t) => $t->dropIndex($name));
                }
            }
        }
    }
};
