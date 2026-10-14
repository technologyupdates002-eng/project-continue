<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_email_templates')) return;
        if (DB::table('website_email_templates')->where('template_key', 'donation_receipt')->exists()) return;
        $row = [
            'template_key' => 'donation_receipt',
            'subject' => 'Thank you for your donation – {{campaign}}',
            'body' => "Dear {{name}},\n\nThank you for your donation of {{amount}} to {{campaign}}.\nReceipt: {{receipt}}\nDate: {{date}}\n\nKirinyaga Health Workers Welfare (KHCWW)",
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (array_keys($row) as $col) {
            if (! Schema::hasColumn('website_email_templates', $col)) unset($row[$col]);
        }
        if (Schema::hasColumn('website_email_templates', 'name')) $row['name'] = 'Donation receipt';
        DB::table('website_email_templates')->insert($row);
    }

    public function down(): void
    {
        if (Schema::hasTable('website_email_templates')) {
            DB::table('website_email_templates')->where('template_key', 'donation_receipt')->delete();
        }
    }
};
