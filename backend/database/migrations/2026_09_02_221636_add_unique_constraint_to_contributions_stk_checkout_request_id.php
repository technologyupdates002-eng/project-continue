<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contributions') && Schema::hasColumn('contributions', 'stk_checkout_request_id')) {
            try {
                DB::statement('CREATE UNIQUE INDEX uk_contributions_stk_checkout_request_id ON contributions (stk_checkout_request_id)');
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contributions') && Schema::hasColumn('contributions', 'stk_checkout_request_id')) {
            try {
                DB::statement('DROP INDEX uk_contributions_stk_checkout_request_id ON contributions');
            } catch (\Throwable $e) {
            }
        }
    }
};
