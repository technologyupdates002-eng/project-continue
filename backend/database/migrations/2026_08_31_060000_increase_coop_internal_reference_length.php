<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coop_transfer_requests', function (Blueprint $table) {
            $table->string('internal_reference', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('coop_transfer_requests', function (Blueprint $table) {
            $table->string('internal_reference', 64)->nullable()->change();
        });
    }
};
