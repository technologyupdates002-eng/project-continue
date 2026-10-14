<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_content_items', function (Blueprint $table) {
            $table->id();
            $table->string('content_type', 24);
            $table->string('slug', 180);
            $table->string('title', 200);
            $table->string('category', 120)->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('image_url')->nullable();
            $table->string('image_alt', 255)->nullable();
            $table->string('location', 200)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('resource_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->char('updated_by', 36)->nullable();
            $table->timestamps();

            $table->unique(['content_type', 'slug'], 'website_content_type_slug_unique');
            $table->index(['content_type', 'status', 'published_at'], 'website_content_publication_index');
            $table->index(['content_type', 'is_featured', 'sort_order'], 'website_content_featured_index');
            $table->foreign('created_by')->references('id')->on('auth_users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('auth_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_content_items');
    }
};
