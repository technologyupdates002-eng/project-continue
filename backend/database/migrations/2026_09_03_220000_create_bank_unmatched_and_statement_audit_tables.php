<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_unmatched_transactions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('bank_reference', 120)->nullable();
            $table->date('transaction_date')->nullable();
            $table->date('value_date')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->decimal('debit_amount', 14, 2)->default(0);
            $table->string('payer_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('narration')->nullable();
            $table->string('account_number', 64)->nullable();
            $table->char('sync_batch_id', 36)->nullable();
            $table->string('reason', 64)->default('unknown_member');
            $table->string('status', 32)->default('pending'); // pending, matched, ignored, investigating
            $table->char('recorded_by', 36)->nullable();
            $table->char('resolved_by', 36)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->index('phone');
            $table->index('sync_batch_id');
            $table->index('status');
            $table->index('transaction_date');
            $table->index('bank_reference');
        });

        Schema::create('statement_processing_audit', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('mode', 64); // TREASURER_STATEMENT_IMPORT | BANK_STATEMENT_SYNC
            $table->char('batch_id', 36)->nullable();
            $table->string('account_number', 64)->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('source_name', 128)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('new_members')->default(0);
            $table->unsignedInteger('members_matched')->default(0);
            $table->unsignedInteger('contributions_created')->default(0);
            $table->unsignedInteger('contributions_updated')->default(0);
            $table->unsignedInteger('duplicates_skipped')->default(0);
            $table->unsignedInteger('unmatched')->default(0);
            $table->unsignedInteger('invalid_phones')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->char('created_by', 36)->nullable();
            $table->string('status', 32)->default('completed');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('mode');
            $table->index('batch_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_unmatched_transactions');
        Schema::dropIfExists('statement_processing_audit');
    }
};