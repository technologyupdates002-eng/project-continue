<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'target_url')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('target_url', 512)->nullable()->after('type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'target_url')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('target_url');
            });
        }
    }
};
