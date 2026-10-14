<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coop_callbacks', function (Blueprint $table) {
            $table->string('transaction_reference', 64)->nullable()->after('message_reference');
            $table->index('transaction_reference', 'idx_txn_ref');
        });
    }

    public function down(): void
    {
        Schema::table('coop_callbacks', function (Blueprint $table) {
            $table->dropIndex('idx_txn_ref');
            $table->dropColumn('transaction_reference');
        });
    }
};
