<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('auth_users', 'last_seen_at')) {
            Schema::table('auth_users', function (Blueprint $table) {
                $table->timestamp('last_seen_at')->nullable()->after('last_sign_in_at');
            });
        }

        if (! Schema::hasTable('login_activity')) {
            Schema::create('login_activity', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable();
                $table->string('email', 191)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('role', 40)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('event_type', 50)->default('login');
                $table->boolean('success')->default(true);
                $table->string('failure_reason', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('logged_out_at')->nullable();

                $table->index(['user_id', 'created_at']);
                $table->index(['email', 'created_at']);
                $table->index(['created_at', 'success']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activity');

        if (Schema::hasColumn('auth_users', 'last_seen_at')) {
            Schema::table('auth_users', function (Blueprint $table) {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};
