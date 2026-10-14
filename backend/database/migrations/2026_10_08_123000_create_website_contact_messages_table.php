<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('topic', 40);
            $table->text('message');
            $table->string('status', 16)->default('new');
            $table->char('read_by', 36)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('read_by')->references('id')->on('auth_users')->nullOnDelete();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_contact_messages');
    }
};
