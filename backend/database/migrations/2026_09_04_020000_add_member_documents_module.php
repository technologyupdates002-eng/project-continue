<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member Documents module — Phase 1 hardening (2026-09-04).
 *
 * 1. Adds `documents` columns:
 *      - document_type_code  (VARCHAR(40) — references DOCUMENT_TYPES.code)
 *      - expires_at          (DATE — for ID cards, contracts, licenses)
 *      - replaces_id         (CHAR(36) NULL — chain of versions when a file is replaced)
 *      - version             (INT — 1, 2, 3...)
 *      - mime_type           (VARCHAR(120) — snapshot of upload MIME)
 *      - size_bytes          (BIGINT — snapshot of upload size)
 *      - reviewed_by         (CHAR(36) NULL — staff who approved/rejected)
 *      - reviewed_at         (DATETIME NULL)
 *      - review_notes        (TEXT NULL)
 *      - deleted_at          (DATETIME NULL — soft delete)
 *
 * 2. Creates `document_types` (configurable, seedable by admin).
 *      Fields: code, label, description, category, requires_expiry, allowed_mime,
 *              max_size_bytes, is_active, sort_order.
 *
 * 3. Creates `document_audit_log` (upload, view, download, replace, delete, approve, reject).
 *
 * 4. Adds indexes used by the new search/filter queries.
 *
 * All statements are idempotent (gated by hasColumn / hasTable) so this
 * migration is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Extend documents table ------------------------------------------------
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $t) {
                if (! Schema::hasColumn('documents', 'document_type_code')) {
                    $t->string('document_type_code', 40)->nullable()->after('file_type');
                }
                if (! Schema::hasColumn('documents', 'expires_at')) {
                    $t->date('expires_at')->nullable()->after('notes');
                }
                if (! Schema::hasColumn('documents', 'replaces_id')) {
                    $t->char('replaces_id', 36)->nullable()->after('expires_at');
                }
                if (! Schema::hasColumn('documents', 'version')) {
                    $t->unsignedInteger('version')->default(1)->after('replaces_id');
                }
                if (! Schema::hasColumn('documents', 'mime_type')) {
                    $t->string('mime_type', 120)->nullable()->after('file_url');
                }
                if (! Schema::hasColumn('documents', 'size_bytes')) {
                    $t->unsignedBigInteger('size_bytes')->default(0)->after('mime_type');
                }
                if (! Schema::hasColumn('documents', 'reviewed_by')) {
                    $t->char('reviewed_by', 36)->nullable()->after('uploaded_by');
                }
                if (! Schema::hasColumn('documents', 'reviewed_at')) {
                    $t->dateTime('reviewed_at')->nullable()->after('reviewed_by');
                }
                if (! Schema::hasColumn('documents', 'review_notes')) {
                    $t->text('review_notes')->nullable()->after('reviewed_at');
                }
                if (! Schema::hasColumn('documents', 'deleted_at')) {
                    $t->dateTime('deleted_at')->nullable()->after('updated_at');
                }
            });

            // Indexes (use raw SQL to avoid doctrine dependency).
            $this->addIndexIfMissing('documents', 'idx_documents_member_type', '(member_id, document_type_code)');
            $this->addIndexIfMissing('documents', 'idx_documents_status', '(status)');
            $this->addIndexIfMissing('documents', 'idx_documents_expires_at', '(expires_at)');
            $this->addIndexIfMissing('documents', 'idx_documents_deleted_at', '(deleted_at)');
        }

        // --- document_types table --------------------------------------------------
        if (! Schema::hasTable('document_types')) {
            Schema::create('document_types', function (Blueprint $t) {
                $t->string('code', 40)->primary();
                $t->string('label', 120);
                $t->text('description')->nullable();
                $t->string('category', 40)->default('identity'); // identity, employment, finance, personal, other
                $t->boolean('requires_expiry')->default(false);
                $t->boolean('requires_approval')->default(true);
                $t->boolean('is_required')->default(false);
                $t->json('allowed_mime')->nullable();
                $t->unsignedInteger('max_size_bytes')->default(10485760);
                $t->boolean('is_active')->default(true);
                $t->unsignedInteger('sort_order')->default(100);
                $t->timestamps();
            });
        }

        // --- document_audit_log ----------------------------------------------------
        if (! Schema::hasTable('document_audit_log')) {
            Schema::create('document_audit_log', function (Blueprint $t) {
                $t->char('id', 36)->primary();
                $t->string('action', 64); // upload, view, download, replace, delete, approve, reject
                $t->string('bucket', 64)->nullable();
                $t->string('object_path', 512)->nullable();
                $t->char('document_id', 36)->nullable();
                $t->char('user_id', 36)->nullable();
                $t->char('member_id', 36)->nullable();
                $t->string('ip', 64)->nullable();
                $t->string('user_agent', 512)->nullable();
                $t->json('extra')->nullable();
                $t->dateTime('created_at')->useCurrent();
                $t->index(['action', 'created_at']);
                $t->index(['user_id', 'created_at']);
                $t->index('document_id');
            });
        }

        // --- Seed document_types with the standard catalog -------------------------
        $catalog = [
            ['code' => 'national_id',         'label' => 'National ID',            'category' => 'identity',   'requires_expiry' => true,  'is_required' => true,  'sort_order' => 10],
            ['code' => 'passport',            'label' => 'Passport',               'category' => 'identity',   'requires_expiry' => true,  'is_required' => true,  'sort_order' => 20],
            ['code' => 'kra_pin',             'label' => 'KRA PIN Certificate',    'category' => 'identity',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 30],
            ['code' => 'nhif',                'label' => 'NHIF Card',              'category' => 'identity',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 40],
            ['code' => 'nssf',                'label' => 'NSSF Card',              'category' => 'identity',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 50],
            ['code' => 'profile_photo',       'label' => 'Profile Photo',          'category' => 'identity',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 5,  'requires_approval' => false],
            ['code' => 'employment_letter',   'label' => 'Employment Letter',      'category' => 'employment', 'requires_expiry' => true,  'is_required' => false, 'sort_order' => 60],
            ['code' => 'appointment_letter',  'label' => 'Appointment Letter',     'category' => 'employment', 'requires_expiry' => false, 'is_required' => false, 'sort_order' => 70],
            ['code' => 'payslip',             'label' => 'Payslip',                'category' => 'employment', 'requires_expiry' => false, 'is_required' => false, 'sort_order' => 80],
            ['code' => 'contract',            'label' => 'Employment Contract',    'category' => 'employment', 'requires_expiry' => true,  'is_required' => false, 'sort_order' => 90],
            ['code' => 'license',             'label' => 'Professional License',   'category' => 'employment', 'requires_expiry' => true,  'is_required' => false, 'sort_order' => 100],
            ['code' => 'next_of_kin_id',      'label' => 'Next of Kin ID',         'category' => 'personal',   'requires_expiry' => true,  'is_required' => false, 'sort_order' => 110],
            ['code' => 'beneficiary_id',      'label' => 'Beneficiary ID',         'category' => 'personal',   'requires_expiry' => true,  'is_required' => false, 'sort_order' => 120],
            ['code' => 'marriage_cert',       'label' => 'Marriage Certificate',   'category' => 'personal',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 130],
            ['code' => 'death_cert',          'label' => 'Death Certificate',      'category' => 'personal',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 140],
            ['code' => 'medical_cert',        'label' => 'Medical Certificate',    'category' => 'personal',   'requires_expiry' => false, 'is_required' => false, 'sort_order' => 150],
            ['code' => 'bank_statement',      'label' => 'Bank Statement',         'category' => 'finance',    'requires_expiry' => false, 'is_required' => false, 'sort_order' => 160],
            ['code' => 'bank_account_proof',  'label' => 'Bank Account Proof',     'category' => 'finance',    'requires_expiry' => false, 'is_required' => false, 'sort_order' => 170],
            ['code' => 'other',               'label' => 'Other',                  'category' => 'other',      'requires_expiry' => false, 'is_required' => false, 'sort_order' => 999, 'requires_approval' => true],
        ];

        $commonMime = json_encode(['image/png', 'image/jpeg', 'image/webp', 'application/pdf']);
        $now = now();
        foreach ($catalog as $row) {
            $row['allowed_mime'] = $commonMime;
            $row['max_size_bytes'] = 10 * 1024 * 1024;
            $row['is_active'] = true;
            $row['requires_approval'] = $row['requires_approval'] ?? true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            DB::table('document_types')->updateOrInsert(['code' => $row['code']], $row);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_audit_log')) {
            Schema::drop('document_audit_log');
        }
        if (Schema::hasTable('document_types')) {
            Schema::drop('document_types');
        }
        if (Schema::hasTable('documents')) {
            $cols = ['document_type_code', 'expires_at', 'replaces_id', 'version', 'mime_type', 'size_bytes', 'reviewed_by', 'reviewed_at', 'review_notes', 'deleted_at'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('documents', $c)) {
                    Schema::table('documents', function (Blueprint $t) use ($c) { $t->dropColumn($c); });
                }
            }
        }
    }

    private function addIndexIfMissing(string $table, string $indexName, string $cols): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]))->isNotEmpty();
        if (! $exists) {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$cols}");
        }
    }
};
