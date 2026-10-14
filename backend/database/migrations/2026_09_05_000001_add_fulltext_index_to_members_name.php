<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->fullText('name')->virtual(false);
        });
    }

    public function down(): void
    {
        try {
            Schema::table('members', function (Blueprint $table) {
                $table->dropFullText('name');
            });
        } catch (\Throwable $e) {
            // Some environments may not have the full-text index present after a partial migration.
        }
    }
};
