<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_public_profiles', function (Blueprint $table) {
            $table->id();
            $table->char('member_id', 36)->unique();
            $table->string('slug', 200)->unique();
            $table->string('title_prefix', 20)->nullable();
            $table->string('display_name', 200)->nullable();
            $table->string('public_role', 200);
            $table->text('short_bio')->nullable();
            $table->longText('biography')->nullable();
            $table->text('qualifications')->nullable();
            $table->json('social_links')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->char('created_by', 36)->nullable();
            $table->char('updated_by', 36)->nullable();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('auth_users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('auth_users')->nullOnDelete();
            $table->index(['published', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_public_profiles');
    }
};
