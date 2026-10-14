<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_logs', 'reference_type')) {
                $table->string('reference_type', 64)->nullable()->after('provider_ref');
            }
            if (! Schema::hasColumn('sms_logs', 'reference_id')) {
                $table->string('reference_id', 128)->nullable()->after('reference_type');
            }
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sms_logs_reference_unique ON sms_logs (recipient_phone, reference_type, reference_id)');
    }

    public function down(): void
    {
        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropIndex('sms_logs_reference_unique');
            $table->dropColumn(['reference_type', 'reference_id']);
        });
    }
};
