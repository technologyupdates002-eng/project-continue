<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_contributions')) {
            if (! Schema::hasColumn('campaign_contributions', 'stk_checkout_request_id')) {
                Schema::table('campaign_contributions', function (Blueprint $table) {
                    $table->string('stk_checkout_request_id', 100)->nullable()->after('status');
                });
            }
            if (! Schema::hasColumn('campaign_contributions', 'payment_ref')) {
                Schema::table('campaign_contributions', function (Blueprint $table) {
                    $table->string('payment_ref', 100)->nullable()->after('stk_checkout_request_id');
                });
            }
            return;
        }

        Schema::create('campaign_contributions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('campaign_id', 36);
            $table->char('member_id', 36);
            $table->decimal('total_contributed', 14, 2)->default(0);
            $table->decimal('target_amount', 14, 2)->default(0);
            $table->decimal('remaining', 14, 2)->default(0);
            $table->enum('status', ['active', 'completed'])->default('active');
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'member_id'], 'uq_campaign_member');
            $table->index('member_id');
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_contributions');
    }
};
