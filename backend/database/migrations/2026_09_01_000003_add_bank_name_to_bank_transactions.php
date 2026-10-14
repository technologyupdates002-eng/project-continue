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
            if (! Schema::hasColumn('bank_transactions', 'bank_name')) {
                Schema::table('bank_transactions', function (Blueprint $table) {
                    $table->string('bank_name', 50)->default('coop')->after('id');
                });
            } else {
                DB::statement("UPDATE `bank_transactions` SET `bank_name` = 'coop' WHERE `bank_name` IS NULL OR `bank_name` = ''");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bank_transactions', 'bank_name')) {
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->dropColumn('bank_name');
            });
        }
    }
};
