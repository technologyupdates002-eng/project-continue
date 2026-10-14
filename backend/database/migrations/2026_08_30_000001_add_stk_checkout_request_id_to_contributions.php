<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contributions') && !Schema::hasColumn('contributions', 'stk_checkout_request_id')) {
            Schema::table('contributions', function (Blueprint $table) {
                $table->string('stk_checkout_request_id', 120)->nullable()->after('payment_id');
                $table->index('stk_checkout_request_id', 'idx_contributions_stk_checkout_request_id');
            });
        }
        if (Schema::hasTable('contributions') && !Schema::hasColumn('contributions', 'payment_ref')) {
            Schema::table('contributions', function (Blueprint $table) {
                $table->string('payment_ref', 120)->nullable()->after('stk_checkout_request_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contributions')) {
            if (Schema::hasColumn('contributions', 'payment_ref')) {
                Schema::table('contributions', function (Blueprint $table) {
                    $table->dropColumn('payment_ref');
                });
            }
            if (Schema::hasColumn('contributions', 'stk_checkout_request_id')) {
                Schema::table('contributions', function (Blueprint $table) {
                    $table->dropIndex('idx_contributions_stk_checkout_request_id');
                    $table->dropColumn('stk_checkout_request_id');
                });
            }
        }
    }
};
