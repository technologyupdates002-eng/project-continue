<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('penalty_waivers')) {
            return;
        }

        Schema::create('penalty_waivers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('member_id');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->uuid('created_by');
            $table->dateTime('created_at', 3);
            $table->index(['member_id', 'created_at'], 'penalty_waivers_member_created');
            $table->index('created_by', 'penalty_waivers_created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_waivers');
    }
};
