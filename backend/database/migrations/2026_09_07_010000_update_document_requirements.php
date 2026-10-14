<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('document_types')) {
            return;
        }

        $required = ['national_id', 'passport'];
        $notRequired = [
            'kra_pin',
            'nhif',
            'nssf',
            'profile_photo',
            'employment_letter',
            'appointment_letter',
            'payslip',
            'contract',
            'license',
            'next_of_kin_id',
            'beneficiary_id',
            'marriage_cert',
            'death_cert',
            'medical_cert',
            'bank_statement',
            'bank_account_proof',
            'other',
        ];

        foreach ($required as $code) {
            DB::table('document_types')->where('code', $code)->update(['is_required' => true]);
        }

        foreach ($notRequired as $code) {
            DB::table('document_types')->where('code', $code)->update(['is_required' => false]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('document_types')) {
            return;
        }

        $originalRequired = ['national_id', 'kra_pin', 'nhif', 'nssf', 'profile_photo', 'employment_letter', 'next_of_kin_id', 'bank_account_proof'];
        $originalNotRequired = ['passport', 'appointment_letter', 'payslip', 'contract', 'license', 'beneficiary_id', 'marriage_cert', 'death_cert', 'medical_cert', 'bank_statement', 'other'];

        foreach ($originalRequired as $code) {
            DB::table('document_types')->where('code', $code)->update(['is_required' => true]);
        }

        foreach ($originalNotRequired as $code) {
            DB::table('document_types')->where('code', $code)->update(['is_required' => false]);
        }
    }
};
