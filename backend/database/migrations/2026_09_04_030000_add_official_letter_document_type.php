<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `official_letter` document type so TreasurerDocuments can store
 * generated letter files in the unified documents table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_types')) {
            return;
        }
        $row = [
            'code' => 'official_letter',
            'label' => 'Official Letter',
            'description' => 'Generated official letter from the organization (e.g. treasurer demand letter, notice).',
            'category' => 'other',
            'requires_expiry' => false,
            'requires_approval' => false,
            'is_required' => false,
            'allowed_mime' => json_encode(['text/plain', 'application/pdf']),
            'max_size_bytes' => 5 * 1024 * 1024,
            'is_active' => true,
            'sort_order' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('document_types')->updateOrInsert(['code' => 'official_letter'], $row);
    }

    public function down(): void
    {
        if (Schema::hasTable('document_types')) {
            DB::table('document_types')->where('code', 'official_letter')->delete();
        }
    }
};
