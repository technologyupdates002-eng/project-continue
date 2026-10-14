<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $withdrawalTables = ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'];
        $signatoryTables = ['withdrawal_signatories', 'donation_withdrawal_signatories', 'operational_withdrawal_signatories'];

        foreach ($withdrawalTables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'approval_stage')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->string('approval_stage', 40)->default('treasurer')->after('status');
                });
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'b2c_reference')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('b2c_reference', 64)->nullable()->after('status');
                });
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'bank_transfer_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->char('bank_transfer_id', 36)->nullable()->after('b2c_reference');
                });
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'mpesa_receipt')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('mpesa_receipt', 64)->nullable()->after('bank_transfer_id');
                });
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'completed_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->timestamp('completed_at')->nullable()->after('submitted_at');
                });
            }
        }

        if (Schema::hasTable('b2c_transactions') && !Schema::hasColumn('b2c_transactions', 'transfer_id')) {
            Schema::table('b2c_transactions', function (Blueprint $t) {
                $t->char('transfer_id', 36)->nullable()->after('wallet_type');
            });
        }

        if (Schema::hasTable('withdrawal_receipts')) {
            if (!Schema::hasColumn('withdrawal_receipts', 'wallet_type')) {
                Schema::table('withdrawal_receipts', function (Blueprint $t) {
                    $t->text('wallet_type')->default('penalty')->after('withdrawal_id');
                });
            }
            if (!Schema::hasColumn('withdrawal_receipts', 'b2c_reference')) {
                Schema::table('withdrawal_receipts', function (Blueprint $t) {
                    $t->string('b2c_reference', 64)->nullable()->after('generated_at');
                });
            }
            if (!Schema::hasColumn('withdrawal_receipts', 'bank_transfer_id')) {
                Schema::table('withdrawal_receipts', function (Blueprint $t) {
                    $t->char('bank_transfer_id', 36)->nullable()->after('b2c_reference');
                });
            }
            if (!Schema::hasColumn('withdrawal_receipts', 'mpesa_receipt')) {
                Schema::table('withdrawal_receipts', function (Blueprint $t) {
                    $t->string('mpesa_receipt', 64)->nullable()->after('bank_transfer_id');
                });
            }
            if (!Schema::hasColumn('withdrawal_receipts', 'created_by')) {
                Schema::table('withdrawal_receipts', function (Blueprint $t) {
                    $t->char('created_by', 36)->nullable()->after('created_at');
                });
            }
            if (Schema::hasColumn('withdrawal_receipts', 'receipt_pdf_url')) {
                // Change NOT NULL to nullable to allow generated receipts from server
            }
        } else {
            Schema::create('withdrawal_receipts', function (Blueprint $t) {
                $t->char('id', 36)->primary();
                $t->char('withdrawal_id', 36);
                $t->text('wallet_type')->default('penalty');
                $t->text('receipt_pdf_url')->nullable();
                $t->timestamp('generated_at')->useCurrent();
                $t->string('b2c_reference', 64)->nullable();
                $t->char('bank_transfer_id', 36)->nullable();
                $t->string('mpesa_receipt', 64)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->char('created_by', 36)->nullable();
            });
        }
    }

    public function down(): void
    {
        $columns = ['approval_stage', 'b2c_reference', 'bank_transfer_id', 'mpesa_receipt', 'completed_at'];
        $tables = ['penalty_withdrawals', 'donation_withdrawals', 'operational_withdrawals'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                foreach ($columns as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        Schema::table($table, function (Blueprint $t) use ($col) {
                            $t->dropColumn($col);
                        });
                    }
                }
            }
        }

        if (Schema::hasTable('b2c_transactions') && Schema::hasColumn('b2c_transactions', 'transfer_id')) {
            Schema::table('b2c_transactions', function (Blueprint $t) {
                $t->dropColumn('transfer_id');
            });
        }
    }
};
