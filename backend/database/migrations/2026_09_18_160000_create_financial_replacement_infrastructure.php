<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('payments', 'status')) {
                    $table->string('status', 24)->default('pending')->after('matched');
                }
                if (! Schema::hasColumn('payments', 'currency')) {
                    $table->char('currency', 3)->default('KES')->after('amount');
                }
                if (! Schema::hasColumn('payments', 'amount_minor')) {
                    $table->unsignedBigInteger('amount_minor')->nullable()->after('amount');
                }
                if (! Schema::hasColumn('payments', 'transaction_reference')) {
                    $table->string('transaction_reference', 191)->nullable()->after('transaction_ref');
                }
                if (! Schema::hasColumn('payments', 'provider_name')) {
                    $table->string('provider_name', 64)->nullable()->after('source');
                }
                if (! Schema::hasColumn('payments', 'provider_reference')) {
                    $table->string('provider_reference', 191)->nullable()->after('provider_name');
                }
                if (! Schema::hasColumn('payments', 'internal_reference')) {
                    $table->string('internal_reference', 191)->nullable()->after('provider_reference');
                }
                if (! Schema::hasColumn('payments', 'fingerprint')) {
                    $table->string('fingerprint', 191)->nullable()->after('internal_reference');
                }
                if (! Schema::hasColumn('payments', 'occurred_at')) {
                    $table->dateTime('occurred_at')->nullable()->after('received_at');
                }
                if (! Schema::hasColumn('payments', 'updated_at')) {
                    $table->dateTime('updated_at')->nullable()->after('received_at');
                }
                if (! Schema::hasColumn('payments', 'confirmed_at')) {
                    $table->dateTime('confirmed_at')->nullable()->after('occurred_at');
                }
            });
        }

        if (! Schema::hasTable('payment_provider_events')) {
            Schema::create('payment_provider_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('payment_id')->nullable();
                $table->string('provider_name', 64);
                $table->string('provider_event_id', 191);
                $table->string('event_type', 64);
                $table->json('payload_json');
                $table->string('normalized_status', 24)->nullable();
                $table->dateTime('received_at');
                $table->dateTime('processed_at')->nullable();
                $table->text('processing_error')->nullable();
                $table->timestamps();
                $table->unique(['provider_name', 'provider_event_id']);
                $table->index(['payment_id', 'normalized_status']);
            });
        }

        if (! Schema::hasTable('monthly_obligations')) {
            Schema::create('monthly_obligations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('member_id');
                $table->unsignedSmallInteger('period_year');
                $table->unsignedTinyInteger('period_month');
                $table->unsignedBigInteger('required_amount_minor');
                $table->unsignedBigInteger('paid_amount_minor')->default(0);
                $table->string('status', 32)->default('unpaid');
                $table->timestamps();
                $table->unique(['member_id', 'period_year', 'period_month']);
                $table->index(['member_id', 'status']);
            });
        }

        if (! Schema::hasTable('contribution_allocations')) {
            Schema::create('contribution_allocations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('payment_id');
                $table->uuid('member_id');
                $table->uuid('obligation_id');
                $table->unsignedBigInteger('amount_minor');
                $table->string('allocation_type', 32);
                $table->timestamps();
                $table->unique(['payment_id', 'obligation_id']);
                $table->index(['member_id', 'obligation_id']);
            });
        }

        if (! Schema::hasTable('carry_forward_movements')) {
            Schema::create('carry_forward_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('member_id');
                $table->uuid('source_payment_id')->nullable();
                $table->uuid('source_carry_forward_id')->nullable();
                $table->uuid('allocation_id')->nullable();
                $table->string('movement_type', 24);
                $table->unsignedBigInteger('amount_minor');
                $table->string('idempotency_key', 191);
                $table->dateTime('created_at');
                $table->unique('idempotency_key');
                $table->index(['member_id', 'movement_type']);
            });
        }

        if (! Schema::hasTable('carry_forward_balances')) {
            Schema::create('carry_forward_balances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('member_id');
                $table->uuid('source_payment_id');
                $table->unsignedBigInteger('amount_minor');
                $table->unsignedBigInteger('remaining_amount_minor');
                $table->dateTime('consumed_at')->nullable();
                $table->timestamps();
                $table->index(['member_id', 'remaining_amount_minor']);
            });
        }

        if (! Schema::hasTable('financial_ledger_entries')) {
            Schema::create('financial_ledger_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('payment_id')->nullable();
                $table->string('source_type', 64);
                $table->uuid('source_id')->nullable();
                $table->string('account_code', 64);
                $table->unsignedBigInteger('debit_minor')->default(0);
                $table->unsignedBigInteger('credit_minor')->default(0);
                $table->char('currency', 3)->default('KES');
                $table->dateTime('posted_at');
                $table->uuid('reversal_of_id')->nullable();
                $table->timestamps();
                $table->index(['payment_id', 'account_code']);
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('financial_exceptions')) {
            Schema::create('financial_exceptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type', 64);
                $table->string('source', 64);
                $table->string('external_reference', 191)->nullable();
                $table->uuid('member_id')->nullable();
                $table->uuid('payment_id')->nullable();
                $table->json('payload_json')->nullable();
                $table->string('status', 24)->default('open');
                $table->text('resolution_notes')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['type', 'status']);
                $table->index(['payment_id', 'member_id']);
            });
        }

        if (Schema::hasTable('financial_exceptions')) {
            Schema::table('financial_exceptions', function (Blueprint $table): void {
                if (! Schema::hasColumn('financial_exceptions', 'source_table')) {
                    $table->string('source_table', 64)->nullable()->after('source');
                }
                if (! Schema::hasColumn('financial_exceptions', 'source_id')) {
                    $table->string('source_id', 191)->nullable()->after('source_table');
                }
                if (! Schema::hasColumn('financial_exceptions', 'member_reference')) {
                    $table->string('member_reference', 191)->nullable()->after('member_id');
                }
                if (! Schema::hasColumn('financial_exceptions', 'investigation_status')) {
                    $table->string('investigation_status', 24)->default('open')->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_exceptions');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('carry_forward_balances');
        Schema::dropIfExists('carry_forward_movements');
        Schema::dropIfExists('contribution_allocations');
        Schema::dropIfExists('monthly_obligations');
        Schema::dropIfExists('payment_provider_events');

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                foreach ([
                    'status', 'currency', 'amount_minor', 'transaction_reference',
                    'provider_name', 'provider_reference', 'internal_reference',
                    'fingerprint', 'occurred_at', 'updated_at', 'confirmed_at',
                ] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};