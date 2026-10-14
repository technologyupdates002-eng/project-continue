<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('ai_assistant_conversations')) {
            Schema::create('ai_assistant_conversations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id');
                $table->string('context_type', 100);
                $table->string('title', 255)->nullable();
                $table->timestamps(3);
                $table->index(['user_id', 'context_type', 'updated_at'], 'ai_conversations_user_context_updated');
            });
        }

        if (! Schema::hasTable('ai_assistant_messages')) {
            Schema::create('ai_assistant_messages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->uuid('conversation_id');
                $table->string('role', 16);
                $table->text('content');
                $table->timestamp('created_at', 3)->useCurrent();
                $table->foreign('conversation_id')
                    ->references('id')
                    ->on('ai_assistant_conversations')
                    ->cascadeOnDelete();
                $table->index(['conversation_id', 'id'], 'ai_messages_conversation_order');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_messages');
        Schema::dropIfExists('ai_assistant_conversations');
    }
};
