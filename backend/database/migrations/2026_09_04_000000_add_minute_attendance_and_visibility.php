<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master Engineering — 2026-09-04
 *
 * Schema additions to support the consolidated KHWWC feature set.
 *
 * - meeting_minute_attendance: proper attendance state machine (one row per
 *   member per minute), backed by UNIQUE(minute_id, member_id) so a member
 *   can only ever be in one PRESENT / ABSENT / ABSENT_WITH_APOLOGY bucket.
 * - meeting_minutes.is_executive: marks Executive minutes (vs General).
 *   When true, ordinary members must NOT see the record.
 * - meeting_minutes.visible_to_roles: optional allow-list of roles that may
 *   read the minute (defaults to all members when General, executive only
 *   when is_executive=true).
 * - sms_logs.provider: tracks which provider handled the send.
 * - signatory_signatures: UNIQUE(user_id, signatory_role) so an officer has
 *   exactly one signature per role.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Attendance table.
        if (! Schema::hasTable('meeting_minute_attendance')) {
            Schema::create('meeting_minute_attendance', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('minute_id', 36);
                $table->char('member_id', 36);
                // PRESENT | ABSENT | ABSENT_WITH_APOLOGY
                $table->string('status', 32);
                $table->timestamp('created_at')->useCurrent();
                $table->index('minute_id');
                $table->index('member_id');
                $table->unique(['minute_id', 'member_id'], 'uq_attendance_minute_member');
                $table->foreign('minute_id')->references('id')->on('meeting_minutes')->onDelete('cascade');
            });
        }

        // 2. meeting_minutes columns.
        if (! Schema::hasColumn('meeting_minutes', 'is_executive')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                $table->boolean('is_executive')->default(false)->after('meeting_type');
            });
        }
        if (! Schema::hasColumn('meeting_minutes', 'visible_to_roles')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                $table->text('visible_to_roles')->nullable()->after('visible_to_members');
            });
        }
        if (! Schema::hasColumn('meeting_minutes', 'venue')) {
            Schema::table('meeting_minutes', function (Blueprint $table) {
                $table->string('venue', 255)->nullable()->after('meeting_date');
            });
        }

        // 3. sms_logs.provider
        if (! Schema::hasColumn('sms_logs', 'provider')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                $table->string('provider', 32)->nullable()->after('status');
            });
        }

        // 4. signatory_signatures unique index.
        $hasUnique = collect(DB::select("SHOW INDEX FROM signatory_signatures WHERE Key_name = 'uq_signatory_user_role'"))->isNotEmpty();
        if (! $hasUnique) {
            DB::statement('ALTER TABLE signatory_signatures ADD UNIQUE KEY uq_signatory_user_role (user_id, signatory_role)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('meeting_minute_attendance')) {
            Schema::drop('meeting_minute_attendance');
        }
        if (Schema::hasColumn('meeting_minutes', 'is_executive')) {
            Schema::table('meeting_minutes', fn (Blueprint $t) => $t->dropColumn('is_executive'));
        }
        if (Schema::hasColumn('meeting_minutes', 'visible_to_roles')) {
            Schema::table('meeting_minutes', fn (Blueprint $t) => $t->dropColumn('visible_to_roles'));
        }
        if (Schema::hasColumn('meeting_minutes', 'venue')) {
            Schema::table('meeting_minutes', fn (Blueprint $t) => $t->dropColumn('venue'));
        }
        if (Schema::hasColumn('sms_logs', 'provider')) {
            Schema::table('sms_logs', fn (Blueprint $t) => $t->dropColumn('provider'));
        }
        DB::statement('ALTER TABLE signatory_signatures DROP INDEX uq_signatory_user_role');
    }
};
