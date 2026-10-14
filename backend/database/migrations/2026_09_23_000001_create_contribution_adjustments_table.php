<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('contribution_adjustments')) {
            return;
        }

        Schema::create('contribution_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('member_id');
            $table->bigInteger('amount_minor');
            $table->string('category', 64)->default('other');
            $table->string('reason', 160);
            $table->uuid('created_by');
            $table->timestamps(3);
            $table->index(['member_id', 'created_at']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_adjustments');
    }
};
