<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_heartbeats')) {
            Schema::create('system_heartbeats', function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->string('component', 64);
                $table->text('message')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['component', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
