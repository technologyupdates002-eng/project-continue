<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            if (! Schema::hasColumn('bank_transactions', 'bank_transaction_reference')) {
                Schema::table('bank_transactions', function (Blueprint $table) {
                    $table->string('bank_transaction_reference', 120)->nullable()->after('transaction_reference');
                    $table->string('transaction_type', 32)->nullable()->after('bank_transaction_reference');
                    $table->decimal('credit_amount', 14, 2)->nullable()->after('transaction_type');
                    $table->decimal('debit_amount', 14, 2)->nullable()->after('credit_amount');
                    $table->text('narration')->nullable()->after('debit_amount');
                    $table->string('account_number', 64)->nullable()->after('narration');
                    $table->string('source', 64)->nullable()->after('account_number');
                    $table->string('reconciliation_status', 32)->nullable()->after('source');
                    $table->timestamp('member_matched_at')->nullable()->after('reconciliation_status');
                });
                DB::statement("UPDATE `bank_transactions` SET `reconciliation_status` = 'unmatched' WHERE `reconciliation_status` IS NULL");
            }

            if (! Schema::hasColumn('bank_transactions', 'bank_name')) {
                Schema::table('bank_transactions', function (Blueprint $table) {
                    $table->string('bank_name', 50)->default('coop')->after('id');
                });
                DB::statement("UPDATE `bank_transactions` SET `bank_name` = 'coop' WHERE `bank_name` IS NULL OR `bank_name` = ''");
            }
        }

        if (! Schema::hasTable('bank_sync_audit')) {
            Schema::create('bank_sync_audit', function (Blueprint $table) {
                $table->char('id', 36);
                $table->string('account_number', 64);
                $table->date('from_date');
                $table->date('to_date');
                $table->integer('total_bank_transactions')->default(0);
                $table->integer('new_transactions')->default(0);
                $table->integer('duplicate_skipped')->default(0);
                $table->integer('matched')->default(0);
                $table->integer('unmatched')->default(0);
                $table->string('status', 64)->default('in_progress');
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->primary('id');
                $table->index(['account_number']);
                $table->index(['from_date', 'to_date']);
                $table->index(['status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_sync_audit');
    }
};
