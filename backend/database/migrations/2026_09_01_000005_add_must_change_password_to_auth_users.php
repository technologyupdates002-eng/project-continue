<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_users', function (Blueprint $table) {
            if (!Schema::hasColumn('auth_users', 'must_change_password')) {
                $table->tinyInteger('must_change_password')->default(0)->after('is_banned');
            }
        });
    }

    public function down(): void
    {
        Schema::table('auth_users', function (Blueprint $table) {
            if (Schema::hasColumn('auth_users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
