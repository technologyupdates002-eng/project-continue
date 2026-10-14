<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Note: Run `php artisan backfill:member-codes` before applying this migration
     * to avoid unique constraint failures. On MySQL multiple NULLs are allowed.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Add a unique index on member_id. If a duplicate exists the migration will fail —
            // run the backfill and verify uniqueness before applying in your local env.
            $table->unique('member_id', 'members_member_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique('members_member_id_unique');
        });
    }
};
