<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('penalty_payment_records', 'notes')) {
            Schema::table('penalty_payment_records', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('mpesa_transaction_id');
            });
        }
        if (!Schema::hasColumn('penalty_payment_records', 'narration')) {
            Schema::table('penalty_payment_records', function (Blueprint $table) {
                $table->text('narration')->nullable()->after('notes');
            });
        }

        if (!Schema::hasColumn('donation_payment_records', 'narration')) {
            Schema::table('donation_payment_records', function (Blueprint $table) {
                $table->text('narration')->nullable()->after('payment_ref');
            });
        }
        if (!Schema::hasColumn('donation_payment_records', 'notes')) {
            Schema::table('donation_payment_records', function (Blueprint $table) {
                $table->text('notes')->nullable()->after('narration');
            });
        }

        if (!Schema::hasColumn('operational_payment_records', 'narration')) {
            Schema::table('operational_payment_records', function (Blueprint $table) {
                $table->text('narration')->nullable()->after('notes');
            });
        }

        if (!Schema::hasColumn('b2c_transactions', 'internal_narration')) {
            Schema::table('b2c_transactions', function (Blueprint $table) {
                $table->text('internal_narration')->nullable()->after('error_message');
            });
        }
    }

    public function down(): void
    {
        Schema::table('penalty_payment_records', function (Blueprint $table) {
            $table->dropColumn(['narration', 'notes']);
        });
        Schema::table('donation_payment_records', function (Blueprint $table) {
            $table->dropColumn(['narration', 'notes']);
        });
        Schema::table('operational_payment_records', function (Blueprint $table) {
            $table->dropColumn('narration');
        });
        Schema::table('b2c_transactions', function (Blueprint $table) {
            $table->dropColumn('internal_narration');
        });
    }
};
