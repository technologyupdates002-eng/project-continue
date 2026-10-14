<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->unsignedInteger('year')->nullable()->after('member_id');
            $table->unsignedInteger('month')->nullable()->after('year');
            $table->unique(['member_id', 'year', 'month', 'reason'], 'uq_penalty_member_period');
        });
    }

    public function down(): void
    {
        Schema::table('penalties', function (Blueprint $table) {
            $table->dropUnique('uq_penalty_member_period');
            $table->dropColumn(['year', 'month']);
        });
    }
};
