<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            if (! Schema::hasColumn('bank_transactions', 'transaction_time')) {
                Schema::table('bank_transactions', function (Blueprint $table) {
                    $table->time('transaction_time')->nullable()->after('transaction_date');
                    $table->date('value_date')->nullable()->after('transaction_time');
                    $table->decimal('bank_balance', 14, 2)->nullable()->after('bank_name');
                    $table->string('match_method', 64)->nullable()->after('reconciliation_status');
                    $table->string('match_confidence', 32)->nullable()->after('match_method');
                    $table->char('matched_by', 36)->nullable()->after('match_confidence');
                    $table->timestamp('matched_at')->nullable()->after('matched_by');
                    $table->char('contribution_id', 36)->nullable()->after('member_id');
                    $table->char('sync_batch_id', 36)->nullable()->after('contribution_id');
                    $table->longText('raw_response')->nullable()->after('sync_batch_id');
                    $table->string('transaction_state', 32)->default('completed')->after('reconciliation_status');
                    $table->string('category', 64)->nullable()->after('transaction_state');
                    $table->timestamp('updated_at')->nullable()->after('created_at');
                    $table->string('bank_fingerprint', 128)->nullable()->after('updated_at');
                });

                DB::statement('ALTER TABLE `bank_transactions` ADD UNIQUE KEY `uq_bank_fingerprint` (`bank_fingerprint`)');
            }
        }

        if (Schema::hasTable('bank_sync_audit')) {
            if (! Schema::hasColumn('bank_sync_audit', 'bank_balance')) {
                Schema::table('bank_sync_audit', function (Blueprint $table) {
                    $table->decimal('bank_balance', 14, 2)->nullable()->after('unmatched');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            if (Schema::hasIndex('bank_transactions', 'uq_bank_fingerprint')) {
                DB::statement('ALTER TABLE `bank_transactions` DROP INDEX `uq_bank_fingerprint`');
            }
            $columns = [
                'transaction_time', 'value_date', 'bank_balance', 'match_method',
                'match_confidence', 'matched_by', 'matched_at', 'contribution_id',
                'sync_batch_id', 'raw_response', 'transaction_state', 'category',
                'updated_at', 'bank_fingerprint',
            ];
            Schema::table('bank_transactions', function (Blueprint $table) use ($columns) {
                foreach ($columns as $col) {
                    if (Schema::hasColumn('bank_transactions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('bank_sync_audit') && Schema::hasColumn('bank_sync_audit', 'bank_balance')) {
            Schema::table('bank_sync_audit', function (Blueprint $table) {
                $table->dropColumn('bank_balance');
            });
        }
    }
};
