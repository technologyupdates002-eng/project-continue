<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('members', 'first_contribution_date')) {
            Schema::table('members', function (Blueprint $table) {
                $table->date('first_contribution_date')->nullable()->after('created_at');
            });
        }

        if (! Schema::hasColumn('members', 'source')) {
            Schema::table('members', function (Blueprint $table) {
                $table->string('source', 64)->nullable()->after('first_contribution_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['first_contribution_date', 'source']);
        });
    }
};
