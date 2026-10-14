<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'uq_notifications_user_event';

    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'event_key')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('event_key', 64)->nullable()->after('target_url');
            });
        }

        if (! Schema::hasIndex('notifications', self::INDEX_NAME)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->unique(['user_id', 'event_key'], self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notifications', self::INDEX_NAME)) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropUnique(self::INDEX_NAME);
            });
        }

        if (Schema::hasColumn('notifications', 'event_key')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('event_key');
            });
        }
    }
};
