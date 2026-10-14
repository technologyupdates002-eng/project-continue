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
                if (! Schema::hasColumn('payments', 'status_message')) {
                    $table->text('status_message')->nullable()->after('status');
                }
                if (! Schema::hasColumn('payments', 'provider_status')) {
                    $table->string('provider_status', 64)->nullable()->after('provider_reference');
                }
                if (! Schema::hasColumn('payments', 'initiated_at')) {
                    $table->dateTime('initiated_at')->nullable()->after('occurred_at');
                }
                if (! Schema::hasColumn('payments', 'last_status_checked_at')) {
                    $table->dateTime('last_status_checked_at')->nullable()->after('initiated_at');
                }
                if (! Schema::hasColumn('payments', 'completed_at')) {
                    $table->dateTime('completed_at')->nullable()->after('confirmed_at');
                }
                if (! Schema::hasColumn('payments', 'failed_at')) {
                    $table->dateTime('failed_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('payments', 'failure_reason')) {
                    $table->text('failure_reason')->nullable()->after('failed_at');
                }
                if (! Schema::hasColumn('payments', 'payment_type')) {
                    $table->string('payment_type', 32)->nullable()->after('source');
                }
                if (! Schema::hasColumn('payments', 'related_entity_id')) {
                    $table->string('related_entity_id', 191)->nullable()->after('payment_type');
                }
            });
        }

        if (! Schema::hasTable('payment_status_checks')) {
            Schema::create('payment_status_checks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('payment_id');
                $table->string('provider_name', 64);
                $table->string('provider_reference', 191)->nullable();
                $table->string('provider_status', 64)->nullable();
                $table->string('normalized_status', 24);
                $table->text('status_message')->nullable();
                $table->json('response_json')->nullable();
                $table->dateTime('checked_at');
                $table->index(['payment_id', 'checked_at']);
                $table->index(['normalized_status', 'checked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_status_checks');

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                foreach ([
                    'status_message', 'provider_status', 'initiated_at',
                    'last_status_checked_at', 'completed_at', 'failed_at', 'failure_reason',
                    'payment_type', 'related_entity_id',
                ] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
