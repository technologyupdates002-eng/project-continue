<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('CREATE UNIQUE INDEX uk_wallet_tx_mpesa_receipt ON wallet_transactions (mpesa_receipt)');
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'Duplicate')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        try {
            $driver = DB::getDriverName();
            if ($driver === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS uk_wallet_tx_mpesa_receipt');
            } else {
                DB::statement('DROP INDEX uk_wallet_tx_mpesa_receipt ON wallet_transactions');
            }
        } catch (\Throwable $e) {
        }
    }
};
