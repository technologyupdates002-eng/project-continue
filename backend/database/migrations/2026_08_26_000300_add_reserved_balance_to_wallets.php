<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['penalty_wallet', 'donation_wallet', 'operational_wallet'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'reserved_balance')) {
                DB::statement("ALTER TABLE `$table` ADD COLUMN `reserved_balance` DECIMAL(14,2) NOT NULL DEFAULT 0");
                DB::statement("ALTER TABLE `$table` ADD COLUMN `available_balance` DECIMAL(14,2) NOT NULL DEFAULT 0");
                DB::statement("UPDATE `$table` SET `available_balance` = `total_balance` - `reserved_balance`");
            }
        }
    }

    public function down(): void
    {
        $tables = ['penalty_wallet', 'donation_wallet', 'operational_wallet'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                if (Schema::hasColumn($table, 'available_balance')) {
                    DB::statement("ALTER TABLE `$table` DROP COLUMN `available_balance`");
                }
                if (Schema::hasColumn($table, 'reserved_balance')) {
                    DB::statement("ALTER TABLE `$table` DROP COLUMN `reserved_balance`");
                }
            }
        }
    }
};
