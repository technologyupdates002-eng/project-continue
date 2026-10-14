<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_cms_settings', function (Blueprint $table) {
            $table->string('setting_key', 40)->primary();
            $table->json('setting_value');
            $table->char('updated_by', 36)->nullable();
            $table->timestamps();
            $table->foreign('updated_by')->references('id')->on('auth_users')->nullOnDelete();
        });

        Schema::create('website_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 80)->unique();
            $table->string('name', 120);
            $table->string('subject', 200);
            $table->longText('body');
            $table->boolean('enabled')->default(true);
            $table->char('updated_by', 36)->nullable();
            $table->timestamps();
            $table->foreign('updated_by')->references('id')->on('auth_users')->nullOnDelete();
        });

        DB::table('website_email_templates')->insert([
            'template_key' => 'contact-notification',
            'name' => 'Website contact notification',
            'subject' => 'New {{topic}} enquiry from {{sender_name}}',
            'body' => "A new enquiry was submitted through the KHCWW website.\n\nName: {{sender_name}}\nEmail: {{sender_email}}\nTopic: {{topic}}\n\nMessage:\n{{message}}",
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_email_templates');
        Schema::dropIfExists('website_cms_settings');
    }
};
